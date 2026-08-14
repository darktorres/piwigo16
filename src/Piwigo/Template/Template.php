<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Latte\Runtime\Html;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Config\TemplateExtension;
use Piwigo\Core\AdminContext;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\Event\CombinedCss;
use Piwigo\Template\Event\CombinedScript;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Template\Request\TemplateExtentsRequest;

/**
 * The data_dir_checked write inside __construct() goes through
 * $this->currentConfigService->get() -- safe even though the write
 * fires from the constructor itself, not a method called later, because
 * every real construction site (Bootstrap\RequestBootstrap.php x2,
 * Admin\Install\InstallWizard.php) runs after its own path has already
 * activated CurrentConfigService (RequestBootstrap::connect() resolves
 * one before finalize() ever constructs a Template; InstallWizard is
 * only ever constructed after InstallBootstrap::activateConfigService()).
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
    public string $output = '';

    /**
     * Plain-array replacement for Smarty's own `Data::$tpl_vars` --
     * `assign()`/`append()`/`getTemplateVars()`/`clearAssign()` below
     * replicate Smarty's own semantics exactly (matching
     * `vendor/smarty/smarty/src/Data.php`), since
     * `setTheme()`'s parent/child theme accumulation (a plain-list
     * `append()` for `themes`, a key-merging `append(..., true)` for
     * `themeconf`) depends on getting that precisely right.
     *
     * @var array<string, mixed>
     */
    private array $vars = [];

    /**
     * @var string[] - Template extents filenames for each template handle.
     */
    public array $extents = [];

    /**
     * @var string[] - Content to add before </head> tag
     */
    public array $htmlHeadElements = [];

    /**
     * @var string - Runtime CSS rules
     */
    private string $htmlStyle = '';

    public const string COMBINED_SCRIPTS_TAG = '<!-- COMBINED_SCRIPTS -->';

    public ScriptLoader $scriptLoader;

    public const string COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';

    public CssLoader $cssLoader;

    /**
     * @var array<int, string[]> - Runtime buttons on picture page
     */
    public array $pictureButtons = [];

    /**
     * @var array<int, string[]> - Runtime buttons on index page
     */
    public array $indexButtons = [];

    /**
     * The theme/template-extension directory chain, in resolution order --
     * read by `resolveLatteTemplatePath()` to find a bare `.latte`
     * filename's real path.
     *
     * @var list<string>
     */
    private array $templateDirs = [];

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
        private readonly AdminContext $adminContext,
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
            } catch (\Doctrine\DBAL\Exception) {
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

        if (! $this->adminContext->isActive()) {
            // setExtents() itself stays untouched -- it's also called by
            // setExtent() with an arbitrary, genuinely-untyped $param a
            // third-party plugin supplies, so its own polymorphic
            // is_array()/is_string() handling is load-bearing, not legacy
            // cruft. Unwrap back to the raw [handle, param, theme] shape it
            // already expects here, at this one config-fed call site.
            $rawExtents = array_map(
                static fn (TemplateExtension $e): array => [$e->handle, $e->param, $e->theme],
                $this->currentConfig->extentsForTemplates,
            );
            $this->setExtents($rawExtents, $this->paths->root . 'template-extension/', true, $theme->value ?? '');
        }
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
        $this->latteEngineInstance = new LatteEngine($cacheDir, $this->currentConfig->templateCompileCheck, $extension);

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
     * path: an extents-override match (re-keyed by base filename, unlike
     * `$this->extents`'s handle-keyed entries used by the Smarty-era
     * `setFilenames()`/`getExtent()` path) wins if present, otherwise the
     * first hit walking `$this->templateDirs` in order. No custom
     * `Latte\Loader` -- this resolves to a real path before Latte's own
     * default `FileLoader` ever sees it, same shape as the reference's
     * `resolveLatteTemplatePath()`.
     */
    private function resolveLatteTemplatePath(string $file): string
    {
        // Already a real, absolute filesystem path -- FileCombiner's own
        // "template=true" combinable rendering (CSS/JS files rendered
        // through the template engine before being combined) resolves
        // $combinable->path to a real path via realpath() itself, before
        // ever reaching here; walking $this->templateDirs against an
        // already-absolute path below would double-prefix it into a
        // nonexistent candidate.
        if (str_starts_with($file, '/') && file_exists($file)) {
            return $file;
        }

        $baseName = basename($file);
        if (isset($this->extents[$baseName])) {
            return $this->extents[$baseName];
        }

        foreach ($this->templateDirs as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $file;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        // Real, live case: search_filters.inc.latte's own {include
        // $ROOT_PATH . 'themes/admin/default/template/include/album_selector.inc.latte'}
        // -- a full, project-root-relative path reaching across into a
        // different theme entirely, not resolvable against this instance's
        // own (single-theme) $templateDirs chain. Smarty resolves this the
        // same way: a file= path not found via any registered
        // template_dir falls back to being treated as relative to the
        // current working directory, which for every real entry point in
        // this app is $this->paths->root.
        $rootCandidate = rtrim($this->paths->root, '/') . '/' . $file;
        if (file_exists($rootCandidate)) {
            return $rootCandidate;
        }

        $this->htmlRenderer()
            ->fatalError("Template->parse(): Couldn't load Latte template file: {$file}");
    }

    /**
     * Loads theme's parameters.
     */
    public function setTheme(string $root, ThemeId $theme, string $path, bool $load_css = true, bool $load_local_head = true, string $colorscheme = 'dark'): void
    {
        // we need themeconf before std_pgs to see what themes use_standard_pages
        $themeconf = $this->loadThemeconf($root . '/' . $theme->value);

        // We loop over the theme and the parent theme, so if we exclude default,
        // standard pages can't get the header to load the html header
        if (
            $theme->value !== 'default'
            and in_array(PageFilterHelper::scriptBasename($this->currentConfig), ['identification', 'register', 'password', 'profile'], true)
            and ((bool) ($themeconf['use_standard_pages'] ?? false) or $this->currentConfig->useStandardPages)
        ) {
            $theme = ThemeId::from('standard_pages');
            $themeconf = $this->loadThemeconf($root . '/' . $theme->value);
        }

        $this->setTemplateDir($root . '/' . $theme->value . '/' . $path);

        $parentTheme = isset($themeconf['parent']) ? ThemeId::tryFrom($themeconf['parent']) : null;
        if ($parentTheme instanceof ThemeId and $parentTheme->value !== $theme->value) {
            $load_parent_css = $themeconf['load_parent_css'] ?? $load_css;
            $load_parent_local_head = $themeconf['load_parent_local_head'] ?? $load_local_head;
            $this->setTheme(
                $root,
                $parentTheme,
                $path,
                is_bool($load_parent_css) ? $load_parent_css : $load_css,
                is_bool($load_parent_local_head) ? $load_parent_local_head : $load_local_head,
                $colorscheme
            );
        }

        $tpl_var = [
            'id' => $theme->value,
            'load_css' => $load_css,
        ];
        if (! in_array($themeconf['local_head'] ?? null, [null, false, 0, '0', '', []], true) and $load_local_head and is_string($themeconf['local_head'])) {
            $tpl_var['local_head'] = realpath($root . '/' . $theme->value . '/' . $themeconf['local_head']);
        }
        $themeconf['id'] = $theme->value;

        if (! isset($themeconf['colorscheme'])) {
            $themeconf['colorscheme'] = $colorscheme;
        }

        $this->append('themes', $tpl_var);
        $this->append('themeconf', $themeconf, true);
    }

    /**
     * Adds template directory for this Template object.
     */
    public function setTemplateDir(string $dir): void
    {
        $this->templateDirs[] = $dir;
    }

    /**
     * Gets the template root directory for this Template object.
     */
    public function getTemplateDir(): string
    {
        return $this->templateDirs[0] ?? '';
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

    /**
     * Sets template extention filename for handles.
     */
    public function setExtent(string $filename, mixed $param, string $dir = '', bool $overwrite = true, string $theme = 'N/A'): bool
    {
        return $this->setExtents([
            $filename => $param,
        ], $dir, $overwrite, $theme);
    }

    /**
     * Sets template extentions filenames for handles.
     *
     * @param mixed $filename_array hashmap of handle=>filename; also called
     *   directly with a plugin-supplied value (setExtent()'s caller),
     *   which is not guaranteed to be an array
     */
    public function setExtents(mixed $filename_array, string $dir = '', bool $overwrite = true, string $theme = 'N/A'): bool
    {
        if (! is_array($filename_array)) {
            return false;
        }
        $getKeysConcatenated = TemplateExtentsRequest::fromGlobals()->keysConcatenated;
        foreach ($filename_array as $filename => $value) {
            if (is_array($value)) {
                $handle = $value[0] ?? null;
                $param = $value[1] ?? null;
                $thm = $value[2] ?? null;
            } elseif (is_string($value)) {
                $handle = $value;
                $param = 'N/A';
                $thm = 'N/A';
            } else {
                return false;
            }

            if ((! is_string($handle) && ! is_int($handle)) or ! is_scalar($param) or ! is_scalar($thm)) {
                return false;
            }

            if ((stripos($getKeysConcatenated, '/' . (string) $param) !== false or (is_string($param) and $param === 'N/A'))
              and ((is_string($thm) and $thm === $theme) or (is_string($thm) and $thm === 'N/A'))
              and (! isset($this->extents[$handle]) or $overwrite)
              and file_exists($dir . $filename)) {
                $real_path = realpath($dir . $filename);
                if ($real_path !== false) {
                    $this->extents[$handle] = $real_path;
                }
            }
        }
        return true;
    }

    /**
     * Returns template extension if exists.
     *
     * @param string $filename should be empty!
     */
    public function getExtent(string $filename = '', string $handle = ''): string
    {
        if (isset($this->extents[$handle])) {
            $filename = $this->extents[$handle];
        }
        return $filename;
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
    #[Override]
    public function assignVarFromTemplate(string $varname, string $file): void
    {
        $rendered = $this->parse($file, true);
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
     * Appends a value onto an existing template variable -- mirrors
     * Smarty's own `Data::append()`: the current value (defaulting to `[]`
     * when unset, cast to a one-element array when scalar) either gets
     * `$value` appended as a new list element, or -- when `$merge` is true
     * and `$value` is itself an array -- has `$value`'s own keys merged in
     * directly. `setTheme()`'s parent/child theme accumulation depends on
     * this exact distinction: a plain list for `themes` (each theme in the
     * chain is its own entry), a key-merged single array for `themeconf`
     * (child keys must win over parent keys assigned earlier).
     */
    private function append(string $var, mixed $value, bool $merge = false): void
    {
        $newValue = $this->vars[$var] ?? [];
        if (! is_array($newValue)) {
            $newValue = (array) $newValue;
        }

        if ($merge && is_array($value)) {
            foreach ($value as $key => $val) {
                $newValue[$key] = $val;
            }
        } else {
            $newValue[] = $value;
        }

        $this->vars[$var] = $newValue;
    }

    /**
     * Whether `$file` resolves against the Latte template-directory chain.
     * Direct replacement for the legacy `$tpl->smarty->templateExists()`
     * check used by mail rendering (`MailService`'s 3 direct call sites).
     */
    public function templateExists(string $file): bool
    {
        if (file_exists($file)) {
            return true;
        }

        $baseName = basename($file);
        if (isset($this->extents[$baseName]) && file_exists($this->extents[$baseName])) {
            return true;
        }

        foreach ($this->templateDirs as $dir) {
            if (file_exists(rtrim($dir, '/') . '/' . $file)) {
                return true;
            }
        }

        return false;
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
     */
    public function concat(string $tpl_var, string $value): void
    {
        $current = $this->getTemplateVars($tpl_var);
        $this->assign(
            $tpl_var,
            (is_string($current) || $current instanceof Html ? (string) $current : '') . $value
        );
    }

    /**
     * Removes an assigned template variable.
     */
    #[Override]
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
     * Renders `$file` (a real filename, e.g. `'header.latte'`) and appends
     * the result to the output (or returns it if `$return` is true).
     *
     * @phpstan-return ($return is true ? string : null)
     */
    public function parse(string $file, bool $return = false): ?string
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

        $v = $this->latteEngine()
            ->render($path, $this->vars);

        if ($return) {
            return $v;
        }
        $this->output .= $v;

        return null;
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
     * Loads the template file of the handle, compiles it and appends the result to the output,
     * then sends the output to the browser.
     */
    public function pparse(string $handle): void
    {
        $this->parse($handle, false);
        $this->flush();
    }

    /**
     * Load and compile JS & CSS into the template and sends the output to the browser.
     */
    public function flush(): void
    {
        echo $this->finalizeOutput();
    }

    /**
     * Same substitutions flush() sends to the browser (combined scripts,
     * combined CSS, injected `<head>` elements/style), but returned
     * instead of echoed -- the non-echoing sibling `fetchOutput()` uses,
     * and what flush() itself now delegates to (behavior-identical: every
     * existing flush()/p() caller is unaffected).
     */
    private function finalizeOutput(): string
    {
        if (! $this->scriptLoader->didHead()) {
            $pos = strpos($this->output, self::COMBINED_SCRIPTS_TAG);
            if ($pos !== false) {
                $scripts = $this->scriptLoader->getHeadScripts($this->accessLevelChecker);
                $content = [];
                foreach ($scripts as $script) {
                    $content[] =
                        '<script type="text/javascript" src="'
                        . $this->makeScriptSrc($script)
                        . '"></script>';
                }

                $this->output = substr_replace($this->output, implode("\n", $content), $pos, strlen(self::COMBINED_SCRIPTS_TAG));
            } // else maybe error or warning ?
        }

        $css = $this->cssLoader->getCss(self::urlService(), $this->eventDispatcher, $this->currentTemplate(), $this->currentConfig, $this->paths, $this->accessLevelChecker);

        $content = [];
        foreach ($css as $combi) {
            $href = self::urlService()->embellishUrl(self::urlService()->getRootUrl() . $combi->path);
            if ($combi->version !== false) {
                $href .= '?v' . ((bool) $combi->version ? $combi->version : AppInfo::VERSION);
            }
            // trigger the event for eventual use of a cdn
            $combinedCssEvent = $this->eventDispatcher->dispatchChange(new CombinedCss($href, $combi));
            $href = $combinedCssEvent->href;
            $content[] = '<link rel="stylesheet" type="text/css" href="' . $href . '">';
        }
        $this->output = str_replace(
            self::COMBINED_CSS_TAG,
            implode("\n", $content),
            $this->output
        );
        $this->cssLoader->clear();

        if ((bool) count($this->htmlHeadElements) || (bool) strlen($this->htmlStyle)) {
            $search = "\n</head>";
            $pos = strpos($this->output, $search);
            if ($pos !== false) {
                $rep = "\n" . implode("\n", $this->htmlHeadElements);
                if ((bool) strlen($this->htmlStyle)) {
                    $rep .= '<style type="text/css">' . $this->htmlStyle . '</style>';
                }
                $this->output = substr_replace($this->output, $rep, $pos, 0);
            } // else maybe error or warning ?
            $this->htmlHeadElements = [];
            $this->htmlStyle = '';
        }

        $output = $this->output;
        $this->output = '';

        return $output;
    }

    /**
     * The non-echoing sibling of flush()/p() -- same combined-script/CSS/
     * head-element substitutions, returned as a string instead of sent to
     * the browser. For callers that need the fully rendered page as a
     * value (controllers returning a real PSR-7 Response instead of
     * echoing directly) rather than as a side effect.
     */
    public function fetchOutput(): string
    {
        return $this->finalizeOutput();
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
        $combinedScriptEvent = $this->eventDispatcher->dispatchChange(new CombinedScript($ret, $script));

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
     * Same `{capture}`+`{do}` composition as `htmlHead()` above.
     */
    public function htmlStyle(string|Html $content): void
    {
        $trimmed = trim((string) $content);
        if ($trimmed !== '') {
            $this->htmlStyle .= "\n" . $trimmed;
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
     * Loads the configuration file from a theme directory and returns it.
     *
     * No legacy `themeconf.inc.php` support -- every theme in this
     * codebase (bundled core themes and P27.6-ported extensions alike)
     * is `theme.json`-only, by design (P27.10: full legacy-file
     * retirement, not a dual fallback).
     *
     * @return array<string, mixed>
     */
    public function loadThemeconf(string $dir): array
    {
        $real_dir = realpath($dir);
        if ($real_dir === false) {
            // Theme directory doesn't actually exist on disk -- don't cache
            // under a coerced-to-0 array key (every broken $dir would
            // collide on the same cache slot).
            return [];
        }
        $dir = $real_dir;
        $cache_key = 'themeconf:' . $dir;
        if (! $this->processCache->has($cache_key)) {
            $themeconf = $this->loadThemeJson($dir);
            // Put themeconf in cache
            $this->processCache->set($cache_key, $themeconf);

            // Return the just-computed value directly rather than falling
            // through to the get() read below -- purely to skip a redundant
            // array lookup, not for correctness (unlike the old *Static()
            // shim, $this->processCache is a real, always-present
            // constructor property now, so there's no not-booted-fallback
            // edge case to worry about here anymore).
            return $themeconf;
        }

        /** @var array<string, mixed> $cached */
        $cached = $this->processCache->get($cache_key);

        return $cached;
    }

    /**
     * Reads `theme.json`, mapped onto the same `$themeconf` shape
     * `setTheme()` already reads (`use_standard_pages`/`parent`/
     * `load_parent_css`/`load_parent_local_head`/`local_head`/
     * `colorscheme`/`icon_dir`/`admin_icon_dir`/`img_dir`/`mime_icon_dir`) -- a plain file read
     * + `json_decode()`, not `PluginConfig\ThemeManifest`/`ThemeRegistry`:
     * those are the same L3Presentation layer as this class but pull in
     * DB/EntityManager dependencies this purely-file-based lookup has no
     * reason to need. A malformed/missing `theme.json` degrades to `[]`
     * (matching `loadThemeconf()`'s own pre-P27.10 not-found behavior),
     * not a thrown exception.
     *
     * `icon_dir`/`admin_icon_dir`/`img_dir`/`mime_icon_dir`
     * (`ThemeManifest::$iconDir`/`$adminIconDir`/`$imgDir`/`$mimeIconDir`)
     * are real, live-read fields (Html\HtmlService reads `icon_dir` via
     * `themeConf()`, Image\SrcImage reads `mime_icon_dir`, admin's own
     * `permalinks`/`popuphelp`/`menubar` templates read `admin_icon_dir`
     * directly) but deliberately have **no** convention-based default
     * computed here -- unset when the manifest doesn't declare them,
     * exactly like `parent`/`local_head`/`colorscheme` above, so a child
     * theme with no icon assets of its own correctly inherits its
     * parent's value via `setTheme()`'s parent-then-child merge
     * (`themes/admin/roma` relies on this for `themes/admin/default`'s
     * own explicit `admin_icon_dir` -- a hardcoded `'themes/<id>/icon'`
     * default here would silently break that inheritance). `icon_dir`
     * and `admin_icon_dir` are deliberately separate: admin themes set
     * `icon_dir` to the gallery theme's own icon path (shared favicon,
     * see `themes/admin/default/theme.json`) while `admin_icon_dir`
     * points at the admin theme's own icon set (delete/exit/drag
     * button icons) -- the same split as legacy piwigo16's
     * `admin/themes/<id>/themeconf.inc.php`.
     *
     * `standard_pages` gets one hardcoded exception: its own
     * `STD_PGS_SELECTED_SKIN`/`STD_PGS_SELECTED_LOGO`/`GALLERY_TITLE`
     * template vars are genuinely dynamic (live `CurrentConfig` reads at
     * request time), not expressible as static `theme.json` data --
     * `standard_pages` is Piwigo-core internal infrastructure, not a
     * real plugin-author extensibility surface (`setTheme()` already
     * hardcodes substituting it in for `identification`/`register`/
     * `password`/`profile` pages), so a small special case here matches
     * that same already-established precedent rather than inventing a
     * new dynamic-config mechanism for a single, non-extensible caller.
     *
     * @return array<string, mixed>
     */
    private function loadThemeJson(string $dir): array
    {
        if (! file_exists($dir . '/theme.json')) {
            return [];
        }

        $contents = file_get_contents($dir . '/theme.json');
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);
        if (! is_array($data)) {
            return [];
        }

        $themeId = basename($dir);

        $themeconf = [
            'use_standard_pages' => is_bool($data['useStandardPages'] ?? null) ? $data['useStandardPages'] : false,
            'load_parent_css' => is_bool($data['loadParentCss'] ?? null) ? $data['loadParentCss'] : false,
        ];
        if (is_string($data['parent'] ?? null)) {
            $themeconf['parent'] = $data['parent'];
        }
        if (is_string($data['localHead'] ?? null)) {
            $themeconf['local_head'] = $data['localHead'];
        }
        if (is_string($data['colorscheme'] ?? null)) {
            $themeconf['colorscheme'] = $data['colorscheme'];
        }
        if (is_string($data['iconDir'] ?? null)) {
            $themeconf['icon_dir'] = $data['iconDir'];
        }
        if (is_string($data['adminIconDir'] ?? null)) {
            $themeconf['admin_icon_dir'] = $data['adminIconDir'];
        }
        if (is_string($data['imgDir'] ?? null)) {
            $themeconf['img_dir'] = $data['imgDir'];
        }
        if (is_string($data['mimeIconDir'] ?? null)) {
            $themeconf['mime_icon_dir'] = $data['mimeIconDir'];
        }
        if (is_bool($data['loadParentLocalHead'] ?? null)) {
            $themeconf['load_parent_local_head'] = $data['loadParentLocalHead'];
        }

        if ($themeId === 'standard_pages') {
            $this->assign([
                'STD_PGS_SELECTED_SKIN' => $this->currentConfig->standardPagesSelectedSkin,
                'STD_PGS_SELECTED_LOGO' => $this->currentConfig->standardPagesSelectedLogo,
                'GALLERY_TITLE' => $this->currentConfig->galleryTitle,
            ]);
        }

        return $themeconf;
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
}
