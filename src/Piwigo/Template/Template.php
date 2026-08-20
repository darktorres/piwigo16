<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Doctrine\DBAL\Exception;
use Latte\Runtime\Html;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Page\PageDataPayload;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\Event\CombinedScript;
use Piwigo\Template\Latte\PiwigoExtension;

/**
 * The data_dir_checked write inside __construct() goes through
 * $this->currentConfigService->get() -- safe even though the write
 * fires from the constructor itself, not a method called later, because
 * every real construction site (Bootstrap\RequestBootstrap.php x2,
 * Admin\Install\InstallWizard.php) runs after its own path has already
 * activated CurrentConfigService (RequestBootstrap::connect() resolves
 * one before finalize() ever constructs a Template; InstallWizard::boot()
 * builds its own ConfigService directly and sets it on CurrentConfigService
 * before this class is ever constructed on the install path -- see
 * InstallWizard::boot()'s own docblock for why that's a direct build, not
 * InstallBootstrap::activateConfigService()).
 * Constructor-injecting CurrentConfigService itself is safe regardless
 * of that ordering -- per its own docblock, it's a plain
 * nullable-reference wrapper that "touches nothing until get() is
 * actually called".
 *
 * Every `mixed` below stays that way by design: assign()/append()/
 * getTemplateVars() mirror Smarty's own arbitrary-value assign()
 * contract (see TemplateInterface's own rationale); every
 * defineDerivative()/htmlHead()/etc. Latte-facing function's $param(s)
 * is genuinely template-author-supplied, already defensively
 * is_string()/is_scalar()/is_numeric()-validated at each real use site,
 * the same "parse, don't trust" boundary as InputValidator.
 */
final class Template implements ThemeConfProviderInterface, TemplateInterface
{
    /**
     * Plain-array replacement for Smarty's own `Data::$tpl_vars` --
     * `assign()`/`getTemplateVars()`/`clearAssign()` below replicate
     * Smarty's own semantics exactly (matching
     * `vendor/smarty/smarty/src/Data.php`). `setTheme()`'s own
     * `themes`/`themeconf` ambient vars are computed by `$this->themeChain`
     * (P41, docs/PLAN.md) and assigned here in one shot, not accumulated
     * via a merging `append()` anymore.
     *
     * @var array<string, mixed>
     */
    private array $vars = [];

    /**
     * @var string[] - Content to add before </head> tag
     */
    public array $htmlHeadElements = [];

    public const string COMBINED_SCRIPTS_TAG = '<!-- COMBINED_SCRIPTS -->';

    public ScriptLoader $scriptLoader;

    public const string COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';

    public CssLoader $cssLoader;

    /**
     * docs/PLAN.md's P37 -- backfilled in finalizeOutput() the same way
     * as COMBINED_SCRIPTS_TAG/COMBINED_CSS_TAG above, but via a plain
     * `str_replace()` (CSS's own shape, not scripts' `didHead()`-guarded
     * strpos()): only one real call site exists (footer.latte's
     * `{=getPageDataScript()}`), so there's nothing to guard against a
     * second resolution the way head-script placement needs to.
     */
    public const string JSON_ISLAND_TAG = '<!-- JSON_ISLAND -->';

    /**
     * @var array<int, string[]> - Runtime buttons on picture page
     */
    public array $pictureButtons = [];

    /**
     * @var array<int, string[]> - Runtime buttons on index page
     */
    public array $indexButtons = [];

    /**
     * Owns the theme directory chain `resolveLatteTemplatePath()` walks
     * to find a bare `.latte` filename's real path -- constructed fresh
     * per instance below, same shape as `$scriptLoader`/`$cssLoader`
     * (P41, docs/PLAN.md's `TemplateLocator`/`ThemeChain` extraction).
     */
    private readonly TemplateLocator $templateLocator;

    /**
     * Owns the theme parent/child chain walk `setTheme()` delegates to
     * -- constructed fresh per instance below, same reasoning as
     * `$templateLocator` above.
     */
    private readonly ThemeChain $themeChain;

    private ?LatteEngine $latteEngineInstance = null;

    /**
     * Backs `once()` -- a real, per-request cross-`{include}` dedup guard.
     * Smarty's own equivalent (`{if !$smarty.capture.NAME}...{capture
     * name=NAME}1{/capture}...{/if}`, e.g. `album_selector.inc.latte`'s guard
     * against rendering twice when included from more than one place in
     * the same page) has no Latte equivalent: a bare `{capture $var}`
     * inside an `{include}`d template is local to that one render call and
     * does not persist back to the caller for a *second*, separate
     * `{include}` of the same partial to observe -- a two-include test
     * renders the guarded body twice, including against the reference's
     * own `{if empty($var)}{capture $var}1{/capture}`
     * port, which has the same gap. This is real `Template`-instance state
     * instead, which every `{include}` call shares regardless of nesting.
     *
     * @var array<string, true>
     */
    private array $onceGuards = [];

    public function __construct(
        private readonly CurrentConfig $currentConfig,
        private readonly Lang $lang,
        private readonly EventDispatcher $eventDispatcher,
        private readonly ErrorCollector $errorCollector,
        private readonly ProcessCache $processCache,
        private readonly CurrentConfigService $currentConfigService,
        private readonly Paths $paths,
        private readonly AccessLevelChecker $accessLevelChecker,
        private readonly SessionService $sessionService,
        string $root = '.',
        ?ThemeId $theme = null,
        string $path = 'template'
    ) {
        $this->scriptLoader = new ScriptLoader();
        $this->cssLoader = new CssLoader();
        $this->templateLocator = new TemplateLocator();
        $this->themeChain = new ThemeChain($this->processCache, function (): void {
            $this->assign([
                'STD_PGS_SELECTED_SKIN' => $this->currentConfig->standardPagesSelectedSkin,
                'STD_PGS_SELECTED_LOGO' => $this->currentConfig->standardPagesSelectedLogo,
                'GALLERY_TITLE' => $this->currentConfig->galleryTitle,
            ]);
        });

        $conf_data_location = $this->currentConfig->dataLocation;

        if ($this->currentConfig->dataDirChecked === null) {
            $dir = $this->paths->root . $conf_data_location;
            FilesystemHelper::mkgetdir($dir, $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR);
            if (! is_writable($dir)) {
                $this->lang->load('admin.lang');
                $this->htmlRenderer()
                    ->fatalError(
                        $this->lang->t(
                            'Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation',
                            $conf_data_location
                        ),
                        $this->lang->t('an error happened'),
                        false // show trace
                    );
            }
            // The try/catch tolerates two distinct real failure modes:
            //
            // On a genuinely fresh install, Admin\Install\InstallWizard::boot()
            // constructs a Template (this class) *before* performInstall()
            // creates the schema, so the config table doesn't exist yet at
            // this exact call site (Doctrine\DBAL\Exception\TableNotFoundException).
            // Every other real construction site
            // (Bootstrap\RequestBootstrap.php x2) always has an existing
            // config table by the time it runs.
            //
            // On the *first* GET to install.php, before the form is ever
            // submitted, InstallWizard::boot()'s own
            // DbCredentials::seed(['PIWIGO_DB_USER' => $this->dbuser, ...])
            // (needed so a *submitted* form's real credentials win over
            // stale ones -- see that call site's own docblock) runs with
            // $this->dbuser === '' (no $_POST yet), which overwrites
            // whatever valid credentials were already loaded (e.g. a test
            // run's .env.test-sourced ones) with empty strings. The
            // resulting connection attempt here fails at the credential
            // stage itself (Doctrine\DBAL\Exception\ConnectionException,
            // "Access denied for user ''@'localhost'"), not the
            // table-lookup stage -- a sibling failure mode this call site
            // must tolerate for the same reason it already tolerates a
            // missing table.
            //
            // Harmless to skip: this write is purely a "don't repeat this
            // filesystem check" cache -- the very next real request
            // (post-install, table now exists) sees
            // CurrentConfig::has('data_dir_checked') still false and simply
            // redoes the cheap mkgetdir/is_writable check once more.
            try {
                // dataDirChecked() is ?string-typed (a presence marker, not
                // a real int) -- passing the real string here so
                // json_encode() produces the JSON-quoted text
                // hydrate()'s 'string' branch expects back, not a bare
                // JSON number that decodes to an int instead.
                $this->currentConfigService->get()
                    ->confUpdateParam('data_dir_checked', '1');
            } catch (Exception) {
            }
        }

        $this->assign('pwg', new TemplateAdapter($this->currentConfig));

        if ($theme instanceof ThemeId) {
            $this->setTheme($root, $theme, $path);
        } else {
            $this->setTemplateDir($root);
        }

        $lang_info = $this->lang->langInfo();
        if (isset($lang_info['code']) and ! isset($lang_info['jquery_code'])) {
            $lang_info['jquery_code'] = $lang_info['code'];
        }

        if (isset($lang_info['jquery_code']) and is_string($lang_info['jquery_code']) and ! isset($lang_info['plupload_code'])) {
            $lang_info['plupload_code'] = str_replace('-', '_', $lang_info['jquery_code']);
        }

        $this->lang->setLangInfo($lang_info);
        $this->assign('lang_info', $lang_info);
    }

    /**
     * Container resolve, not a constructor property -- `UrlServiceInterface`
     * stays on this established, already-correct pattern rather than
     * being folded into the required-collaborator list above for no
     * reason. `private static` (not `private`) so it's reachable from
     * makeScriptSrc() below, which is itself an instance method but
     * keeps calling this via `self::` like every other caller in this
     * class. Resolves the container-shared instance, not a throwaway
     * `new UrlService($this->htmlRenderer())` -- see Image\SrcImage's
     * own docblock for why (RootPathOverride's cross-instance sharing
     * requirement).
     */
    private static function urlService(): UrlServiceInterface
    {
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        if (! $urlService instanceof UrlServiceInterface) {
            throw new LogicException('Container returned an unexpected type for ' . UrlServiceInterface::class);
        }

        return $urlService;
    }

    /**
     * `public` (unlike urlService() above) and called from well outside
     * this class -- `Core\DateHelper`, `Core\FilesystemHelper`, and
     * `Bootstrap\RequestBootstrap` all resolve `Lang` this way rather than
     * carrying their own constructor-injected reference, the same
     * permanent-exception shape `currentConfig()` below has for
     * `themeconf.inc.php`.
     */
    public static function lang(): Lang
    {
        $lang = Kernel::container()->get(Lang::class);
        if (! $lang instanceof Lang) {
            throw new LogicException('Container returned an unexpected type for ' . Lang::class);
        }

        return $lang;
    }

    /**
     * Same reasoning as urlService() above -- docs/PLAN.md's P37 needs
     * `PageState` here (getPageDataScript()'s own PageDataPayload
     * construction below), and this class's constructor already has 11
     * real call sites across 6 files; a container resolve here avoids
     * touching all of them for one new dependency.
     */
    private static function pageState(): PageState
    {
        $pageState = Kernel::container()->get(PageState::class);
        if (! $pageState instanceof PageState) {
            throw new LogicException('Container returned an unexpected type for ' . PageState::class);
        }

        return $pageState;
    }

    /**
     * `public` and referenced by its fully-qualified name, same reasoning
     * as lang() above -- used inside
     * themes/standard_pages/themeconf.inc.php. Unlike lang()'s own
     * compiled-cache-codegen use case, this file is a real, direct PHP
     * `include` from loadThemeconf() below -- `$this` genuinely IS the
     * including Template instance there (an `include`d file shares its
     * including method's `$this` binding, including private-property
     * access) -- but PHPStan analyses every file independently and can't
     * trace that inherited scope, so `$this->currentConfig` there reports
     * as undefined. A real, ordinary static method call sidesteps that
     * analysis gap instead of suppressing it.
     */
    public static function currentConfig(): CurrentConfig
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }

        return $currentConfig;
    }

    /**
     * Container resolve, not a constructor property -- every real use in
     * this class is `->fatalError(...)`, a defensive early-crash guard
     * (missing param, non-writable dir, etc.), not real business logic.
     * `HtmlRenderingInterface` is already bound in container.php, so this
     * matches Core\FilesystemHelper::fatalError()'s own already-established
     * precedent for this exact interface/method combination (see that
     * method's own docblock) rather than growing this class's own
     * constructor for a 14-site error-path-only usage.
     */
    private function htmlRenderer(): HtmlRenderingInterface
    {
        $htmlRenderer = Kernel::container()->get(HtmlRenderingInterface::class);
        if (! $htmlRenderer instanceof HtmlRenderingInterface) {
            throw new LogicException('Container returned an unexpected type for ' . HtmlRenderingInterface::class);
        }

        return $htmlRenderer;
    }

    /**
     * Container resolve, not a constructor property -- ImageStdParams's
     * own container factory (config/container.php) unconditionally calls
     * loadFromDb() against a real DB connection. A required constructor
     * param here would force that DB read merely by constructing a
     * Template, or (worse) merely by *any* caller satisfying this class's
     * own constructor signature -- InstallWizard's own real construction
     * site had to resolve this eagerly just to build a Template, before
     * install.php has ever confirmed the schema exists (the
     * `derivative_settings` table doesn't exist yet on a fresh install).
     * Kept lazy here, so nothing forces this cost except
     * defineDerivative() actually running.
     */
    private function imageStdParams(): ImageStdParams
    {
        $imageStdParams = Kernel::container()->get(ImageStdParams::class);
        if (! $imageStdParams instanceof ImageStdParams) {
            throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
        }

        return $imageStdParams;
    }

    /**
     * Container resolve, not a constructor property -- the only real use
     * in this class is CssLoader::getCss()'s one call site below. A
     * required constructor param here would ripple to every real
     * `new Template(...)` construction site across the app for a single
     * caller, the same low-blast-radius reasoning as htmlRenderer()/
     * imageStdParams() above.
     */
    private function currentTemplate(): CurrentTemplate
    {
        $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
        if (! $currentTemplate instanceof CurrentTemplate) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentTemplate::class);
        }

        return $currentTemplate;
    }

    /**
     * Lazily constructed, memoized per `Template` instance -- mirrors
     * `imageStdParams()`/`currentTemplate()` above: nothing forces this
     * cost (a real cache-dir mkdir, a `PiwigoExtension` construction)
     * except a `.latte` file actually being parsed.
     */
    private function latteEngine(): LatteEngine
    {
        if ($this->latteEngineInstance instanceof LatteEngine) {
            return $this->latteEngineInstance;
        }

        $cacheDir = LatteEngine::defaultCacheDir($this->paths->root, $this->currentConfig->dataLocation);
        FilesystemHelper::mkgetdir($cacheDir, $this->currentConfig);
        if ($this->currentConfig->templateForceCompile) {
            $this->clearLatteCacheDir($cacheDir);
        }

        $extension = new PiwigoExtension($this, $this->lang, $this->accessLevelChecker, $this->sessionService, self::urlService());
        $this->latteEngineInstance = new LatteEngine($cacheDir, $this->currentConfig->templateCompileCheck, $extension, $this->lang->currentUserLanguage());

        return $this->latteEngineInstance;
    }

    /**
     * Latte's compiled-cache files are flat (one PHP file per compiled
     * template, named by hash, no nested subdirectories) -- unlike
     * Smarty's own compile dir, a plain top-level unlink pass is enough.
     */
    private function clearLatteCacheDir(string $cacheDir): void
    {
        $entries = glob($cacheDir . '/*');
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if (is_file($entry)) {
                unlink($entry);
            }
        }
    }

    /**
     * Resolves a bare `.latte` filename to a real, absolute filesystem
     * path via `$this->templateLocator`. No custom `Latte\Loader` --
     * this resolves to a real path before Latte's own default
     * `FileLoader` ever sees it, same shape as the reference's
     * `resolveLatteTemplatePath()`.
     */
    private function resolveLatteTemplatePath(string $file): string
    {
        $resolved = $this->templateLocator->resolve($file, $this->paths->root);
        if ($resolved !== null) {
            return $resolved;
        }

        $this->htmlRenderer()
            ->fatalError("Template->parse(): Couldn't load Latte template file: {$file}");
    }

    /**
     * Loads theme's parameters -- delegates the actual parent/child
     * chain walk to `$this->themeChain` (P41, docs/PLAN.md), then
     * applies its result: each resolved directory added to
     * `$this->templateLocator`, and the merged `themes`/`themeconf`
     * ambient vars assigned once (`setTheme()` itself is only ever
     * called once per real `Template` instance -- from this class's own
     * constructor -- so there's no pre-existing value an `assign()` here
     * could ever clobber).
     */
    public function setTheme(string $root, ThemeId $theme, string $path, bool $load_css = true, bool $load_local_head = true, string $colorscheme = 'dark'): void
    {
        $resolution = $this->themeChain->resolve($root, $theme, $path, $this->currentConfig, $load_css, $load_local_head, $colorscheme);

        foreach ($resolution->dirs as $dir) {
            $this->setTemplateDir($dir);
        }

        $this->assign('themes', $resolution->themes);
        $this->assign('themeconf', $resolution->themeconf);
    }

    /**
     * Adds template directory for this Template object.
     */
    public function setTemplateDir(string $dir): void
    {
        $this->templateLocator->addDir($dir);
    }

    /**
     * Gets the template root directory for this Template object.
     */
    public function getTemplateDir(): string
    {
        return $this->templateLocator->firstDir();
    }

    /**
     * Deletes all compiled Latte templates -- the CLI `bin/piwigo
     * cache:clear` (`Piwigo\Command\CacheClearCommand`) is a *different*,
     * narrower mechanism for the same directory; see that class's own
     * docblock.
     */
    public function deleteCompiledTemplates(): void
    {
        $this->clearLatteCacheDir(LatteEngine::defaultCacheDir($this->paths->root, $this->currentConfig->dataLocation));
    }

    /**
     * Returns theme's parameter.
     */
    public function getThemeconf(string $val): mixed
    {
        $tc = $this->getTemplateVars('themeconf');
        return is_array($tc) ? ($tc[$val] ?? '') : '';
    }

    /**
     * String-narrowed variant of getThemeconf() above: the corresponding
     * $themeconf value if existing and a string, otherwise an empty
     * string. Implements Piwigo\Core\ThemeConfProviderInterface so L2a
     * callers (SrcImage) can reach it without depending on this L3 class;
     * see that interface's own docblock.
     */
    #[Override]
    public function themeConf(string $key): string
    {
        $value = $this->getThemeconf($key);

        return is_string($value) ? $value : '';
    }

    #[Override]
    public function assignContext(TemplatePageContext $context): void
    {
        $this->assign($context->toArray());
    }

    /**
     * Renders `$file` (a real filename, e.g. `'popuphelp.latte'`) and
     * assigns the result to `$varname`, wrapped in `Latte\Runtime\Html` so
     * it propagates through Latte's auto-escape unmolested at every
     * `{$var}` print site downstream.
     */
    public function assignVarFromTemplate(string $varname, string $file): void
    {
        $rendered = $this->parse($file);
        $this->assign($varname, new Html($rendered));
    }

    /**
     * Assigns one or more template variables -- mirrors Smarty's own
     * `Data::assign()` polymorphic shape (a single key+value, or a bulk
     * `array<string, mixed>` when `$var` is an array and `$value` is
     * `null`), matching `vendor/smarty/smarty/src/Data.php`.
     *
     * @param array<string, mixed>|string $var
     */
    private function assign(array|string $var, mixed $value = null): void
    {
        if (is_array($var)) {
            foreach ($var as $key => $val) {
                $this->assign($key, $val);
            }

            return;
        }

        $this->vars[$var] = $value;
    }

    /**
     * Whether `$file` resolves against the Latte template-directory chain.
     * Direct replacement for the legacy `$tpl->smarty->templateExists()`
     * check used by mail rendering (`MailService`'s 3 direct call sites).
     */
    public function templateExists(string $file): bool
    {
        return $this->templateLocator->exists($file);
    }

    /**
     * Real cross-`{include}` dedup guard -- see `$onceGuards`'s own
     * docblock. Returns `true` (render now) only the first time a given
     * `$key` is passed during this request; every later call with the same
     * key returns `false`, regardless of how many different templates or
     * `{include}` sites reach it.
     */
    public function once(string $key): bool
    {
        if (isset($this->onceGuards[$key])) {
            return false;
        }

        $this->onceGuards[$key] = true;

        return true;
    }

    /**
     * Performs a string concatenation.
     *
     * `$tpl_var` can hold a `Latte\Runtime\Html` value, not just a plain
     * string, when a caller assigned it via `assignVarFromTemplate()`
     * (e.g. `ADMIN_CONTENT`, once its own producer -- like intro.latte --
     * is a converted Latte template) -- cast to string rather than the
     * previous `is_string($current) ? $current : ''` check, which
     * silently discarded the entire existing value instead of
     * concatenating onto it. Real regression: once `intro.latte`'s own
     * conversion made this a real code path, CheckIntegrity.php's
     * `concat('ADMIN_CONTENT', ...)` call would have dropped the whole
     * dashboard, keeping only the check_integrity widget.
     *
     * Result is re-wrapped in `Html`, not left a plain string, matching
     * `assignVarFromTemplate()`'s own convention -- found live via
     * AdminTools' settings page: `admin.latte` declares
     * `{varType \Latte\Runtime\Html $ADMIN_CONTENT}` and renders it as
     * bare `{$ADMIN_CONTENT}`, with no `|noescape` filter (unlike
     * `EXTRA_BODY_CONTENT`'s own template, which applies `|noescape`
     * explicitly). Latte only skips auto-escaping when the actual
     * runtime value is a recognized safe type -- a `varType` annotation
     * is a static-analysis hint only, not a runtime escaping switch --
     * so assigning a plain string here rendered as literal HTML-escaped
     * source text, not a usable form. Confirmed this was never
     * previously exercised end-to-end: `PluginSettingsPageDispatchTest`
     * only asserted the assigned value's type/content, never rendered
     * it through the real Latte template.
     */
    public function concat(string $tpl_var, string $value): void
    {
        $current = $this->getTemplateVars($tpl_var);
        $this->assign(
            $tpl_var,
            new Html((is_string($current) || $current instanceof Html ? (string) $current : '') . $value)
        );
    }

    /**
     * Removes an assigned template variable.
     */
    public function clearAssign(string $tpl_var): void
    {
        unset($this->vars[$tpl_var]);
    }

    /**
     * Returns an assigned template variable, or the full assigned-var
     * array when `$tpl_var` is omitted.
     */
    public function getTemplateVars(?string $tpl_var = null): mixed
    {
        if ($tpl_var === null) {
            return $this->vars;
        }

        return $this->vars[$tpl_var] ?? null;
    }

    /**
     * Renders `$file` (a real filename, e.g. `'header.latte'`) and
     * returns the result. Every real caller by this point in the P41
     * cutover (`MailService`'s own shell, `FileCombiner`,
     * `assignVarFromTemplate()` below) already wants the string back --
     * the old accumulate-into-`$output` mode (P41, docs/PLAN.md) is
     * gone, along with `$output`/`flush()`/`fetchOutput()` themselves.
     */
    public function parse(string $file): string
    {
        // Resolve first, before touching urlService()/Lang/etc. below --
        // a genuinely unresolvable $file has to fail here (TemplateTest.php's
        // own "htmlRenderer resolver throws" case).
        $path = $this->resolveLatteTemplatePath($file);

        $this->assign('ROOT_URL', self::urlService()->getRootUrl());
        // ROOT_PATH is the template-side equivalent of PHPWG_ROOT_PATH for
        // the handful of templates that need a real filesystem existence
        // check (datepicker.inc.latte's own
        // `{if ($ROOT_PATH|cat:...|file_exists)}`), not a URL -- ROOT_URL
        // above is request-relative and wrong for file_exists().
        $this->assign('ROOT_PATH', $this->paths->root);

        return $this->latteEngine()
            ->render($path, $this->vars);
    }

    /**
     * Renders `$file` against a typed `View` instead of the accumulated
     * `$vars` bag alone -- the `View`'s own public properties are merged
     * on top of `$vars` (winning on any key collision), so a migrated
     * template still sees the ambient globals `parse()` itself relies on
     * (`ROOT_URL`/`ROOT_PATH` here, `themeconf`/`lang_info`/`pwg` from
     * the constructor/`setTheme()`, plus whatever an earlier
     * `assignContext()`/`assignVarFromTemplate()` call on this same
     * request already put there) without each `View` having to carry
     * them itself. `Renderer::render()` is the one real caller.
     */
    public function renderView(string $file, View $view): string
    {
        $this->assign('ROOT_URL', self::urlService()->getRootUrl());
        $this->assign('ROOT_PATH', $this->paths->root);
        $path = $this->resolveLatteTemplatePath($file);

        /** @var array<string, mixed> $params */
        $params = [...$this->vars, ...get_object_vars($view)];

        return $this->latteEngine()
            ->render($path, $params);
    }

    /**
     * Compiles `$file` into the Latte cache without rendering it -- unlike
     * `parse()`, no `ROOT_URL`/`ROOT_PATH` var assignment, since compiling
     * doesn't execute the template.
     */
    public function warmupLatteCache(string $file): void
    {
        $path = $this->resolveLatteTemplatePath($file);
        $this->latteEngine()
            ->warmupCache($path);
    }

    /**
     * Combined-scripts/combined-CSS/JSON-island/`<head>`-element
     * substitutions against an arbitrary rendered string -- every real
     * page render (P41, docs/PLAN.md) calls this directly on its own
     * `Renderer::render(View): Html` result. `{do combineCss}`/
     * `{do combineScript}`/`{do htmlHead}` registrations land on this
     * same `Template` instance's `$cssLoader`/`$scriptLoader`/
     * `$htmlHeadElements` regardless of which page called this.
     */
    public function finalizeHtml(string $html): string
    {
        if (! $this->scriptLoader->didHead()) {
            $pos = strpos($html, self::COMBINED_SCRIPTS_TAG);
            if ($pos !== false) {
                $scripts = $this->scriptLoader->getHeadScripts($this->accessLevelChecker);
                $content = [];
                foreach ($scripts as $script) {
                    $content[] =
                        '<script type="text/javascript" src="'
                        . $this->makeScriptSrc($script)
                        . '"></script>';
                }

                $html = substr_replace($html, implode("\n", $content), $pos, strlen(self::COMBINED_SCRIPTS_TAG));
            } // else maybe error or warning ?
        }

        $css = $this->cssLoader->getCss(self::urlService(), $this->eventDispatcher, $this->currentTemplate(), $this->currentConfig, $this->paths, $this->accessLevelChecker);

        $content = [];
        foreach ($css as $combi) {
            $href = self::urlService()->embellishUrl(self::urlService()->getRootUrl() . $combi->path);
            if ($combi->version !== false) {
                $href .= '?v' . ((bool) $combi->version ? $combi->version : AppInfo::VERSION);
            }
            $content[] = '<link rel="stylesheet" type="text/css" href="' . $href . '">';
        }
        $html = str_replace(
            self::COMBINED_CSS_TAG,
            implode("\n", $content),
            $html
        );
        $this->cssLoader->clear();

        // docs/PLAN.md's P37 -- same unconditional str_replace() shape as
        // COMBINED_CSS_TAG above (only one real call site,
        // footer.latte's own {=getPageDataScript()}, so nothing to
        // guard against a second resolution). PageState isn't cleared
        // here, unlike cssLoader above -- see Template::exposeData()'s
        // own docblock and docs/PLAN.md's P37 section for why it must
        // not be: any `{do exposeData(...)}`/`{do exposeString(...)}`
        // call anywhere in the page (e.g. admin.latte's own body) has to
        // survive until this substitution runs against footer.latte's
        // own placeholder, regardless of how many separate
        // Template::finalizeHtml() calls the request makes along the way.
        $pageDataPayload = new PageDataPayload(self::pageState(), self::lang());
        $html = str_replace(
            self::JSON_ISLAND_TAG,
            '<script type="application/json" id="page-data">' . $pageDataPayload->toJson() . '</script>',
            $html
        );

        if ((bool) count($this->htmlHeadElements)) {
            // `[ \t]*` tolerates the leading indentation a formatted
            // `</head>` line carries (Latte's `Feature::Dedent` isn't
            // enabled, so that indentation is literal in the rendered
            // output) -- a bare `strpos($html, "\n</head>")` silently
            // misses an indented tag and drops this content.
            $pos = preg_match('#\n[ \t]*</head>#', $html, $m, PREG_OFFSET_CAPTURE) === 1
                ? $m[0][1]
                : false;
            if ($pos !== false) {
                $rep = "\n" . implode("\n", $this->htmlHeadElements);
                $html = substr_replace($html, $rep, $pos, 0);
            } // else maybe error or warning ?
            $this->htmlHeadElements = [];
        }

        return $html;
    }

    /**
     * Returns clean relative URL to script file.
     */
    private function makeScriptSrc(Combinable $script): string
    {
        $ret = '';
        if ($script->isRemote(self::urlService())) {
            // isRemote() can only return true via a real urlIsRemote($this
            // ->path) call, which it early-returns false before reaching
            // whenever $this->path is null -- so path is provably non-null
            // here.
            assert($script->path !== null);
            $ret = $script->path;
        } else {
            $ret = self::urlService()->getRootUrl() . $script->path;
            if ($script->version !== false) {
                $ret .= '?v' . ((bool) $script->version ? $script->version : AppInfo::VERSION);
            }
        }
        // trigger the event for eventual use of a cdn
        $combinedScriptEvent = $this->eventDispatcher->dispatch(new CombinedScript($ret, $script));

        return self::urlService()->embellishUrl($combinedScriptEvent->src);
    }

    /**
     * `{do combineScript(...)}` -- registers a JS file with `ScriptLoader`.
     * `$id` has no default, so a `.latte` template omitting it gets a real
     * PHP `ArgumentCountError` at the call site: real, required arguments
     * backed by PHP's own type system, not a behavior gap -- no real
     * converted template omits it.
     */
    public function combineScript(string $id, ?string $load = null, ?string $require = null, ?string $path = null, string|false $version = '0', bool $template = false): void
    {
        $loadMode = 0;
        if ($load !== null) {
            switch ($load) {
                case 'header':
                    break;
                case 'footer':
                    $loadMode = 1;
                    break;
                case 'async':
                    $loadMode = 2;
                    break;
                default:
                    $this->errorCollector->recordFatal("combineScript: invalid 'load' parameter");
            }
        }

        $requireList = $require !== null && $require !== '' ? explode(',', $require) : [];

        $this->scriptLoader->add($id, $loadMode, $requireList, $path, $version, $template);
    }

    /**
     * `{=getCombinedScripts(...)}` -- returns `Latte\Runtime\Html` (not a
     * plain string), since this one (unlike `combineScript()`) prints real
     * markup at its own call site and would otherwise be HTML-escaped by
     * Latte's auto-escaping (see docs/PLAN.md's P31 section,
     * "Auto-escaping").
     */
    public function getCombinedScripts(string $load): Html
    {
        if ($load === 'header') {
            return new Html(self::COMBINED_SCRIPTS_TAG);
        }

        $content = [];
        $scripts = $this->scriptLoader->getFooterScripts($this->accessLevelChecker);
        foreach ($scripts->sync as $script) {
            $content[] =
              '<script type="text/javascript" src="'
              . $this->makeScriptSrc($script)
              . '"></script>';
        }
        if ($this->scriptLoader->inlineScripts !== []) {
            $content[] = '<script type="text/javascript">//<![CDATA[
';
            $content = array_merge($content, $this->scriptLoader->inlineScripts);
            $content[] = '//]]></script>';
        }

        if ($scripts->async !== []) {
            $content[] = '<script type="text/javascript">';
            $content[] = <<<'JS'
            (function() {
            var s,after = document.getElementsByTagName('script')[document.getElementsByTagName('script').length-1];
            JS;
            foreach ($scripts->async as $script) {
                $content[] = <<<JS
                s=document.createElement('script'); s.type='text/javascript'; s.async=true; s.src='{$this->makeScriptSrc($script)}';
                JS;
                $content[] = 'after = after.parentNode.insertBefore(s, after);';
            }
            $content[] = '})();';
            $content[] = '</script>';
        }

        return new Html(implode("\n", $content));
    }

    /**
     * `{do combineCss(...)}` -- registers a CSS file with `CssLoader`.
     * `$path` has no default, same reasoning as `combineScript()`'s `$id`
     * above.
     */
    public function combineCss(string $path, ?string $id = null, string|false $version = '0', int $order = 0, bool $template = false): void
    {
        $this->cssLoader->add($id ?? md5($path), $path, $version, $order, $template);
    }

    /**
     * `{=getCombinedCss()}` -- prints the placeholder literal, so it needs
     * the same `Html` wrap as `getCombinedScripts()` above.
     */
    public function getCombinedCss(): Html
    {
        return new Html(self::COMBINED_CSS_TAG);
    }

    /**
     * `{var $x = defineDerivative(...)}` -- Latte has no scope-mutation
     * hook for a called function to assign a variable into the caller's
     * own template scope, so this returns the value directly, bound at the
     * call site (matching the reference's real usage,
     * `piwigo16-rewrite/tools/smarty-to-latte/Converter.php:602-632`).
     * `$crop` folds Smarty's own dual bool/numeric-percentage semantics
     * into one parameter: `bool` for a whole 0/1 crop ratio, `float|int`
     * for a percent.
     */
    public function defineDerivative(?string $type = null, ?int $width = null, ?int $height = null, bool|float|int $crop = 0, ?int $minWidth = null, ?int $minHeight = null): DerivativeParams
    {
        if ($type !== null) {
            return $this->imageStdParams()
                ->getByType($type);
        }

        if ($width === null || $height === null) {
            $this->htmlRenderer()
                ->fatalError('defineDerivative missing width or height');
        }

        $cropRatio = is_bool($crop) ? ($crop ? 1 : 0) : round(((float) $crop) / 100.0, 2);
        $minw = null;
        $minh = null;

        if ($cropRatio !== 0 && $cropRatio !== 0.0) {
            $minw = $minWidth ?? $width;
            if ($minw > $width) {
                $this->htmlRenderer()
                    ->fatalError('defineDerivative invalid min_width');
            }
            $minh = $minHeight ?? $height;
            if ($minh > $height) {
                $this->htmlRenderer()
                    ->fatalError('defineDerivative invalid min_height');
            }
        }

        return $this->imageStdParams()
            ->getCustom($width, $height, $cropRatio, $minw, $minh);
    }

    /**
     * `{capture $v}...{/capture}{do htmlHead($v)}` -- Latte's own native
     * tags compose the open/close content-capture Smarty's own
     * `html_head` block plugin API used to give, so no custom Latte tag is
     * needed; see docs/PLAN.md's P31 section, "Blocks/functions".
     */
    public function htmlHead(string|Html $content): void
    {
        $trimmed = trim((string) $content);
        if ($trimmed !== '') {
            $this->htmlHeadElements[] = $trimmed;
        }
    }

    /**
     * Same `{capture}`+`{do}` composition as `htmlHead()` above, calling
     * `ScriptLoader::addInline()`.
     */
    public function footerScript(string|Html $content, ?string $require = null): void
    {
        $trimmed = trim((string) $content);
        if ($trimmed !== '') {
            $requireList = $require !== null && $require !== '' ? explode(',', $require) : [];
            $this->scriptLoader->addInline($trimmed, $requireList);
        }
    }

    /**
     * Admin-configurable local CSS override files
     * (`local/css/{theme}-rules.css`, `local/css/rules.css`) -- NOT the
     * same thing as `$theme.local_head` (that's an ordinary `{include}`,
     * no special mechanism at all; see docs/PLAN.md's P31 section,
     * "Prefilters"). Called explicitly from `header.latte` (both
     * `themes/default/template/` and `themes/standard_pages/template/`,
     * the two real front-end headers) right before `{=getCombinedCss()}`
     * -- never from an admin template, matching this feature's original,
     * Smarty-era front-end-only scope.
     *
     * @param array<int, array<string, mixed>> $themes
     */
    public function localCssRules(array $themes): void
    {
        $siteLocalDir = substr($this->paths->siteLocal, strlen($this->paths->root));

        foreach ($themes as $theme) {
            $id = $theme['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }
            $f = $siteLocalDir . 'css/' . $id . '-rules.css';
            if (file_exists($this->paths->root . $f)) {
                $this->combineCss($f, order: 10);
            }
        }

        $f = $siteLocalDir . 'css/rules.css';
        if (file_exists($this->paths->root . $f)) {
            $this->combineCss($f, order: 10);
        }
    }

    /**
     * `{do exposeData(...)}` -- accumulates into `PageState`, like
     * `combineScript()`/`combineCss()` above accumulate into
     * `scriptLoader`/`cssLoader`, rather than being implemented directly
     * on `PiwigoExtension` the way stateless `translate()` is (see
     * docs/PLAN.md's P37 section for why the two functions below match
     * this method's own registration shape, not that one's).
     *
     * @param array<mixed> $value
     */
    public function exposeData(string $key, string|int|float|bool|null|array $value): void
    {
        self::pageState()->exposeData($key, $value);
    }

    /**
     * `{do exposeString(...)}` -- same reasoning as exposeData() above.
     */
    public function exposeString(string $translationKey): void
    {
        self::pageState()->exposeString($translationKey);
    }

    /**
     * `{=getPageDataScript()}` -- same `Html`-wrapped placeholder shape
     * as `getCombinedCss()` above (prints real markup at its own call
     * site, so needs the auto-escaping bypass); the placeholder is
     * backfilled in `finalizeOutput()` once `PageState`'s full
     * accumulated `exposeData()`/`exposeString()` calls are known
     * (docs/PLAN.md's P37).
     */
    public function getPageDataScript(): Html
    {
        return new Html(self::JSON_ISLAND_TAG);
    }

    /**
     * Loads the configuration file from a theme directory and returns
     * it -- a thin public delegate to `$this->themeChain` (P41,
     * docs/PLAN.md), kept for this method's own existing direct test
     * coverage. `setTheme()`'s own recursive walk is the one other real
     * caller, reached through `$this->themeChain` directly rather than
     * through this method.
     *
     * No legacy `themeconf.inc.php` support -- every theme in this
     * codebase is `theme.json`-only, by design: full legacy-file
     * retirement, not a dual fallback.
     *
     * @return array<string, mixed>
     */
    public function loadThemeconf(string $dir): array
    {
        return $this->themeChain->loadThemeconf($dir);
    }

    /**
     * Registers a button to be displayed on picture page.
     */
    public function addPictureButton(string $content, int $rank = 50): void
    {
        $this->pictureButtons[$rank][] = $content;
    }

    /**
     * Registers a button to be displayed on index pages.
     */
    public function addIndexButton(string $content, int $rank = 50): void
    {
        $this->indexButtons[$rank][] = $content;
    }

    /**
     * Assigns PLUGIN_PICTURE_BUTTONS template variable with registered picture buttons.
     */
    public function parsePictureButtons(): void
    {
        if ($this->pictureButtons !== []) {
            ksort($this->pictureButtons);
            $buttons = [];
            foreach ($this->pictureButtons as $k => $row) {
                $buttons = array_merge($buttons, $row);
            }
            $this->assign('PLUGIN_PICTURE_BUTTONS', $buttons);
        }
    }

    /**
     * Assigns PLUGIN_INDEX_BUTTONS template variable with registered index buttons.
     */
    public function parseIndexButtons(): void
    {
        if ($this->indexButtons !== []) {
            ksort($this->indexButtons);
            $buttons = [];
            foreach ($this->indexButtons as $k => $row) {
                $buttons = array_merge($buttons, $row);
            }
            $this->assign('PLUGIN_INDEX_BUTTONS', $buttons);
        }
    }

    /**
     * Same ksort+flatten logic as `parseIndexButtons()` above, returned
     * instead of `assign()`-ed -- the `View`-based sibling for a migrated
     * page's own `IndexView::$pluginIndexButtons` property.
     *
     * @return list<string>
     */
    public function indexButtons(): array
    {
        if ($this->indexButtons === []) {
            return [];
        }

        ksort($this->indexButtons);
        $buttons = [];
        foreach ($this->indexButtons as $row) {
            $buttons = array_merge($buttons, $row);
        }

        return array_values($buttons);
    }

    /**
     * Same ksort+flatten logic as `parsePictureButtons()` above, returned
     * instead of `assign()`-ed -- the `View`-based sibling for a migrated
     * page's own `PictureView::$pluginPictureButtons` property.
     *
     * @return list<string>
     */
    public function pictureButtons(): array
    {
        if ($this->pictureButtons === []) {
            return [];
        }

        ksort($this->pictureButtons);
        $buttons = [];
        foreach ($this->pictureButtons as $row) {
            $buttons = array_merge($buttons, $row);
        }

        return array_values($buttons);
    }
}
