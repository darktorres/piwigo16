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
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\Event\GetPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Asset\PageAssets;
use Piwigo\Asset\ResolvedAsset;
use Piwigo\Asset\ViteManifest;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Contribution\ActionContribution;
use Piwigo\Contribution\AuthButton;
use Piwigo\Contribution\ButtonContribution;
use Piwigo\Contribution\FieldOverride;
use Piwigo\Contribution\FormProvider;
use Piwigo\Contribution\MenuItem;
use Piwigo\Contribution\PictureInfoRow;
use Piwigo\Contribution\ProfileField;
use Piwigo\Contribution\ThumbnailOverlay;
use Piwigo\Core\AppInfo;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HeadLink;
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
use Piwigo\Template\Event\CombinedScript;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Template\Projection\LocalHeadView;

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

    /**
     * `getCombinedScripts('footer')`'s own placeholder, resolved
     * alongside `COMBINED_SCRIPTS_TAG` above in `finalizeHtml()` --
     * unified onto the same placeholder-deferred path rather than
     * `getCombinedScripts()` resolving footer content immediately at
     * its own template-render call site the way it used to (P41-G,
     * docs/PLAN.md).
     */
    public const string COMBINED_FOOTER_SCRIPTS_TAG = '<!-- COMBINED_FOOTER_SCRIPTS -->';

    public const string COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';

    /**
     * Collects `{do combineCss}`/`{do combineScript}`/`{do footerScript}`
     * registrations and resolves them into ordered, `ViteManifest`-aware
     * `ResolvedAsset` lists -- `ScriptLoader`/`CssLoader`/`FileCombiner`'s
     * own real replacement (P41-G, docs/PLAN.md). Constructed fresh per
     * instance below, same shape as `$templateLocator`/`$themeChain`.
     * File-combining itself (`FileCombiner`'s real, `templateCombineFiles`-gated
     * multi-file-bundle-into-one-cache-file mechanism) is intentionally
     * NOT preserved -- a real bundler (Vite) replaces the need for it
     * once JS migrates to TS in a later phase, so porting that ad-hoc
     * mechanism into `PageAssets` now would be throwaway work.
     */
    private readonly PageAssets $pageAssets;

    /**
     * `ScriptLoader::didHead()`'s own generalized replacement: guards
     * BOTH `COMBINED_SCRIPTS_TAG` and `COMBINED_FOOTER_SCRIPTS_TAG`
     * substitution in `finalizeHtml()` together, since both now resolve
     * from the same single `PageAssets::resolveScripts()` call -- a
     * second `finalizeHtml()` call on this instance leaves either
     * placeholder tag literally unreplaced, matching `didHead()`'s own
     * "don't reprocess" contract. CSS has no equivalent lock --
     * `PageAssets::clearCss()` is called instead, so a second call
     * simply sees nothing left registered.
     */
    private bool $scriptsResolved = false;

    /**
     * `Asset\Event\GetPageAssets`'s own one-shot dispatch guard -- fired
     * at most once per instance, from `Renderer::render()`'s first call
     * on this `Template` (docs/PLAN.md's P42; formerly `finalizeHtml()`'s
     * own first line), not tied to `$scriptsResolved` above since
     * plugin-contributed CSS needs it too and CSS resolves on every call.
     */
    private bool $pageAssetsDispatched = false;

    /**
     * The theme-base "local-head resolver" piece's own one-shot guard
     * (docs/PLAN.md's P42) -- fired from `Renderer::render()`'s first
     * call on this instance, same timing as `$pageAssetsDispatched`
     * above, but a separate flag since the two resolve genuinely
     * different things (plugin-contributed assets vs. a theme's own
     * `local_head.latte`-equivalent partial).
     */
    private bool $localHeadResolved = false;

    /**
     * Set by `applyThemeBaseAssets()` (docs/PLAN.md's P42) -- lets
     * `finalizeHtml()` know whether to also register
     * `ThemeBaseAssets::lateAdminScripts()` alongside its own `page-data`
     * registration; both sat at `layout.latte`'s own tail originally,
     * admin-only, so this flag scopes that lazy registration correctly
     * instead of adding admin-only scripts on every theme family.
     */
    private bool $isAdminLayout = false;

    /**
     * Set by `applyThemeBaseAssets()` -- true only when this instance is
     * rendering one of the 3 real `layout.latte` families (that method's
     * own `$path !== 'template'` early return skips it otherwise, e.g.
     * `InstallWizard`'s separately-rooted `Template`, which has no
     * `{=getPageDataScript()}`/`page-data` script call at all). Gates
     * `finalizeHtml()`'s own lazy `page-data` registration below --
     * unconditional there would add `page-data.js` to a page family
     * that never wanted it.
     */
    private bool $themeBaseApplied = false;

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
     * Keyed by `ButtonContribution::$order`, same ksort+flatten shape as
     * `AssetContribution`'s own ordering -- a plain rank, not a `Priority`
     * enum (P43, docs/PLAN.md: no `enum Priority` exists anywhere in this
     * codebase, `AssetContribution::$order`'s own plain `int` is the one
     * proven ordering precedent).
     *
     * @var array<int, ButtonContribution[]>
     */
    private array $pictureButtons = [];

    /**
     * @var array<int, ButtonContribution[]>
     */
    private array $indexButtons = [];

    /**
     * @var array<int, ActionContribution[]>
     */
    private array $pictureActions = [];

    /**
     * @var array<int, ActionContribution[]>
     */
    private array $indexActions = [];

    /**
     * @var array<int, PictureInfoRow[]>
     */
    private array $pictureInfoRows = [];

    /**
     * @var array<int, ProfileField[]>
     */
    private array $registerFields = [];

    /**
     * @var array<int, ProfileField[]>
     */
    private array $profileFields = [];

    /**
     * @var array<int, AuthButton[]>
     */
    private array $authButtons = [];

    /**
     * @var array<int, ThumbnailOverlay[]>
     */
    private array $thumbnailOverlays = [];

    /**
     * @var array<int, MenuItem[]>
     */
    private array $menuItems = [];

    /**
     * No `$order`-keyed bucketing like the collectors above -- a
     * `FieldOverride` case is a plain presence check
     * (`in_array($case, $this->fieldOverrides, true)`), not a
     * visually-stacked list.
     *
     * @var list<FieldOverride>
     */
    private array $fieldOverrides = [];

    /**
     * @var array<int, FormProvider[]>
     */
    private array $formProviders = [];

    /**
     * Owns the theme directory chain `resolveLatteTemplatePath()` walks
     * to find a bare `.latte` filename's real path -- constructed fresh
     * per instance below, same shape as `$pageAssets`
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
        private readonly ProcessCache $processCache,
        private readonly CurrentConfigService $currentConfigService,
        private readonly Paths $paths,
        private readonly AccessLevelChecker $accessLevelChecker,
        private readonly UrlServiceInterface $urlService,
        private readonly PageState $pageState,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly ImageStdParams $imageStdParams,
        string $root = '.',
        ?ThemeId $theme = null,
        string $path = 'template',
        bool $applyThemeBase = true,
    ) {
        $this->pageAssets = new PageAssets(new ViteManifest($this->paths));
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
                $this->htmlRenderer
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
            $this->setTheme($root, $theme, $path, applyThemeBase: $applyThemeBase);
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
     * `public` and called from well outside this class -- `Core\DateHelper`,
     * `Core\FilesystemHelper`, and `Bootstrap\RequestBootstrap` all resolve
     * `Lang` this way rather than carrying their own constructor-injected
     * reference, the same permanent-exception shape `currentConfig()`
     * below has for `themeconf.inc.php`.
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
     * `public` and referenced by its fully-qualified name -- used inside
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
     * Lazily constructed, memoized per `Template` instance -- nothing
     * forces this cost (a real cache-dir mkdir, a `PiwigoExtension`
     * construction) except a `.latte` file actually being parsed.
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

        $extension = new PiwigoExtension($this, $this->lang, $this->accessLevelChecker, $this->urlService);
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

        $this->htmlRenderer
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
    public function setTheme(string $root, ThemeId $theme, string $path, bool $load_css = true, bool $load_local_head = true, string $colorscheme = 'dark', bool $applyThemeBase = true): void
    {
        $resolution = $this->themeChain->resolve($root, $theme, $path, $this->currentConfig, $load_css, $load_local_head, $colorscheme);

        foreach ($resolution->dirs as $dir) {
            $this->setTemplateDir($dir);
        }

        $this->assign('themes', $resolution->themes);
        $this->assign('themeconf', $resolution->themeconf);

        if ($applyThemeBase) {
            $this->applyThemeBaseAssets($root, $path, $resolution->themes);
        }
    }

    /**
     * The theme-base pieces (docs/PLAN.md's P42-A) -- the plain
     * unconditional `combineCss`/`combineScript` calls every real
     * `layout.latte` used to make imperatively, `localCssRules()`'s own
     * call site (for the 2 real layout families that have it), and the
     * confirm-dialog base-strings registration all 3 real `layout.latte`
     * files register unconditionally. Skipped entirely for Mail's own
     * separately-rooted `Template` instances (`$path` is always
     * `'template/mail/' . $emailFormat` there, never the bare
     * `'template'` every real gallery/admin/standard_pages construction
     * uses) -- those never render `layout.latte` at all, so none of this
     * applies.
     *
     * @param list<array<string, mixed>> $themes
     */
    private function applyThemeBaseAssets(string $root, string $path, array $themes): void
    {
        if ($path !== 'template') {
            return;
        }

        $this->themeBaseApplied = true;

        $isAdmin = str_ends_with($root, 'themes/admin');
        $isStandardPages = ! $isAdmin && $themes !== [] && ($themes[array_key_last($themes)]['id'] ?? null) === 'standard_pages';
        $this->isAdminLayout = $isAdmin;

        if ($isAdmin) {
            $assets = ThemeBaseAssets::forAdminLayout($themes);
        } elseif ($isStandardPages) {
            $assets = ThemeBaseAssets::forStandardPagesLayout($themes);
            $this->localCssRules($themes);
        } else {
            $assets = ThemeBaseAssets::forDefaultLayout($themes);
            $this->localCssRules($themes);
        }

        foreach ($assets as $asset) {
            $this->pageAssets->add($asset);
        }

        $this->exposeString('Yes, I am sure');
        $this->exposeString('No, I have changed my mind');
        $this->exposeString('Are you sure?');
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
     * bare `{$ADMIN_CONTENT}`, with no `|noescape` filter (the
     * now-deleted (P44-B) `EXTRA_BODY_CONTENT` template block was the
     * one real site that still applied `|noescape` explicitly -- dead
     * code with zero producers, unlike `ADMIN_CONTENT`). Latte only
     * skips auto-escaping when the actual runtime value is a recognized
     * safe type -- a `varType` annotation
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

        $this->assign('ROOT_URL', $this->urlService->getRootUrl());
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
        $this->assign('ROOT_URL', $this->urlService->getRootUrl());
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
     * same `Template` instance's `$pageAssets`/`$htmlHeadElements`
     * regardless of which page called this.
     */
    public function finalizeHtml(string $html): string
    {
        if (! $this->scriptsResolved) {
            $this->scriptsResolved = true;

            // Registered here, not in `ThemeBaseAssets` (docs/PLAN.md's
            // P42), so both insert *last* among same-priority scripts --
            // matching every real `layout.latte`'s own original
            // imperative call order, right before this same resolution --
            // see `ThemeBaseAssets`'s own class docblock for why theme-
            // init timing would reorder same-priority ties instead.
            // `jquery.tipTip` before `page-data`: admin's own original
            // tail literally called `combineScript('jquery.tipTip', ...)`
            // before `combineScript('page-data', ...)`, and same-rank
            // ties break by insertion order.
            if ($this->themeBaseApplied) {
                if ($this->isAdminLayout) {
                    foreach (ThemeBaseAssets::lateAdminScripts() as $lateAsset) {
                        $this->pageAssets->add($lateAsset);
                    }
                }
                $this->pageAssets->add(AssetContribution::script('page-data', 'themes/default/js/page-data.ts', loadMode: LoadMode::Footer));
            }

            $scripts = $this->pageAssets->resolveScripts();

            $pos = strpos($html, self::COMBINED_SCRIPTS_TAG);
            if ($pos !== false) {
                $content = [];
                foreach ($scripts as $asset) {
                    if ($asset->loadMode === LoadMode::Header) {
                        $content[] =
                            '<script type="text/javascript" src="'
                            . $this->makeAssetSrc($asset)
                            . '"></script>';
                    }
                }

                $html = substr_replace($html, $this->indentedJoin($html, $pos, $content), $pos, strlen(self::COMBINED_SCRIPTS_TAG));
            } // else maybe error or warning ?

            $footerPos = strpos($html, self::COMBINED_FOOTER_SCRIPTS_TAG);
            if ($footerPos !== false) {
                $html = substr_replace($html, $this->renderFooterScripts($scripts, $this->lineIndent($html, $footerPos)), $footerPos, strlen(self::COMBINED_FOOTER_SCRIPTS_TAG));
            } // else maybe error or warning ?
        }

        $css = $this->pageAssets->resolveCss();

        $content = [];
        foreach ($css as $asset) {
            $href = $this->urlService->embellishUrl($this->urlService->getRootUrl() . $asset->path);
            if ($asset->version !== false) {
                $href .= '?v' . ((bool) $asset->version ? $asset->version : AppInfo::VERSION);
            }
            $content[] = '<link rel="stylesheet" type="text/css" href="' . $href . '">';
        }
        $cssPos = strpos($html, self::COMBINED_CSS_TAG);
        if ($cssPos !== false) {
            $html = substr_replace($html, $this->indentedJoin($html, $cssPos, $content), $cssPos, strlen(self::COMBINED_CSS_TAG));
        }
        $this->pageAssets->clearCss();

        // docs/PLAN.md's P37 -- same unconditional str_replace() shape as
        // COMBINED_CSS_TAG above (only one real call site,
        // footer.latte's own {=getPageDataScript()}, so nothing to
        // guard against a second resolution). PageState isn't cleared
        // here, unlike $pageAssets' CSS registrations above -- see Template::exposeData()'s
        // own docblock and docs/PLAN.md's P37 section for why it must
        // not be: any `{do exposeData(...)}`/`{do exposeString(...)}`
        // call anywhere in the page (e.g. admin.latte's own body) has to
        // survive until this substitution runs against footer.latte's
        // own placeholder, regardless of how many separate
        // Template::finalizeHtml() calls the request makes along the way.
        $pageDataPayload = new PageDataPayload($this->pageState, self::lang());
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
     * Dispatches `Asset\Event\GetPageAssets` at most once per instance --
     * `Renderer::render()`'s own first call on this instance (docs/PLAN.md's
     * P42), before that first View's own declared assets, and before
     * either script or CSS resolution reads `$this->pageAssets`, so a
     * plugin-contributed asset participates in the exact same
     * dedup/ordering/promotion pass as a template-registered one. Public
     * because `Renderer` (a different class) is the caller now, not this
     * class's own `finalizeHtml()`.
     */
    public function dispatchPageAssetsOnce(): void
    {
        if ($this->pageAssetsDispatched) {
            return;
        }
        $this->pageAssetsDispatched = true;

        $event = $this->eventDispatcher->dispatch(new GetPageAssets());
        foreach ($event->assets as $contribution) {
            $this->pageAssets->add($contribution);
        }
    }

    /**
     * The theme-base "local-head resolver" piece (docs/PLAN.md's P42) --
     * fired from `Renderer::render()`'s first call on this instance, same
     * timing as `dispatchPageAssetsOnce()` above. Renders
     * `LocalHeadView` directly via `renderView()` (not a recursive
     * `Renderer::render()` call -- `Renderer` itself depends on this
     * class via `CurrentTemplate`, so calling back into it here would be
     * circular) purely for its side effect on `$this->pageAssets`; the
     * returned markup string itself is discarded, matching
     * `local_head.latte`'s own real content (empty markup once
     * `pageAssets()` covers its former `{do combineCss(...)}`). Applies
     * `HasPageAssets` inline (docs/PLAN.md's P42-B) since bypassing
     * `Renderer::render()` above also bypasses its own automatic
     * `HasPageAssets` hook.
     *
     * Narrowly scoped to the one real instance that exists today
     * (`themes/default/local_head.latte`) by comparing each resolved
     * theme's own `local_head` path against that exact file, not by
     * theme id alone -- `themes/admin/default/` is also a real theme
     * literally named "default", with no `localHead` of its own, but
     * matching by id alone would still be a coincidence worth not
     * relying on. A second theme adding its own `localHead` file needs
     * its own dedicated View plus its own branch here, not a change to
     * this comparison.
     */
    public function resolveLocalHeadOnce(): void
    {
        if ($this->localHeadResolved) {
            return;
        }
        $this->localHeadResolved = true;

        $expected = realpath($this->paths->root . 'themes/default/local_head.latte');
        if ($expected === false) {
            return;
        }

        $themes = $this->getTemplateVars('themes');
        if (! is_array($themes)) {
            return;
        }

        foreach ($themes as $theme) {
            $localHead = is_array($theme) ? ($theme['local_head'] ?? null) : null;
            if (! is_string($localHead) || $localHead !== $expected) {
                continue;
            }

            $localHeadView = new LocalHeadView(load_css: (bool) ($theme['load_css'] ?? true));
            $this->registerPageAssets($localHeadView->pageAssets());
            $this->renderView('themes/default/local_head.latte', $localHeadView);
        }
    }

    /**
     * `Renderer::render()`'s own entry point for a `HasPageAssets` View's
     * declared contributions (docs/PLAN.md's P42) -- `$pageAssets` itself
     * is `private`, so this small public wrapper is the only way a
     * different class can feed it.
     *
     * @param list<AssetContribution> $contributions
     */
    public function registerPageAssets(array $contributions): void
    {
        foreach ($contributions as $contribution) {
            $this->pageAssets->add($contribution);
        }
    }

    /**
     * Returns clean relative URL to an asset file.
     */
    private function makeAssetSrc(ResolvedAsset $asset): string
    {
        $isRemote = $this->urlService->urlIsRemote($asset->path) || str_starts_with($asset->path, '//');

        if ($isRemote) {
            $ret = $asset->path;
        } else {
            $ret = $this->urlService->getRootUrl() . $asset->path;
            if ($asset->version !== false) {
                $ret .= '?v' . ((bool) $asset->version ? $asset->version : AppInfo::VERSION);
            }
        }
        // trigger the event for eventual use of a cdn
        $combinedScriptEvent = $this->eventDispatcher->dispatch(new CombinedScript($ret, $asset));

        return $this->urlService->embellishUrl($combinedScriptEvent->src);
    }

    /**
     * Builds `COMBINED_FOOTER_SCRIPTS_TAG`'s own real substitution
     * content: footer-sync `<script src>` tags, then (if any) one
     * `<script>` block wrapping every inline registration
     * (`{do footerScript(...)}`'s own real target) in registration
     * order, then (if any) the async-bootstrap IIFE -- the exact same
     * 3-phase shape `getCombinedScripts('footer')` used to build
     * directly, now driven off `$scripts`' single resolved list instead
     * of `ScriptLoader::getFooterScripts()`'s own 2-list `FooterScripts`
     * projection.
     *
     * @param list<ResolvedAsset> $scripts
     */
    private function renderFooterScripts(array $scripts, string $indent): string
    {
        $content = [];
        foreach ($scripts as $asset) {
            if ($asset->loadMode === LoadMode::Footer && $asset->inlineCode === null) {
                $content[] =
                  '<script type="text/javascript" src="'
                  . $this->makeAssetSrc($asset)
                  . '"></script>';
            }
        }

        $inline = array_values(array_filter($scripts, static fn (ResolvedAsset $asset): bool => $asset->inlineCode !== null));
        if ($inline !== []) {
            $content[] = '<script type="text/javascript">//<![CDATA[
';
            foreach ($inline as $asset) {
                $content[] = (string) $asset->inlineCode;
            }
            $content[] = '//]]></script>';
        }

        $async = array_values(array_filter($scripts, static fn (ResolvedAsset $asset): bool => $asset->loadMode === LoadMode::Async));
        if ($async !== []) {
            $content[] = '<script type="text/javascript">';
            $content[] = <<<'JS'
            (function() {
            var s,after = document.getElementsByTagName('script')[document.getElementsByTagName('script').length-1];
            JS;
            foreach ($async as $asset) {
                $content[] = <<<JS
                s=document.createElement('script'); s.type='text/javascript'; s.async=true; s.src='{$this->makeAssetSrc($asset)}';
                JS;
                $content[] = 'after = after.parentNode.insertBefore(s, after);';
            }
            $content[] = '})();';
            $content[] = '</script>';
        }

        return implode("\n" . $indent, $content);
    }

    /**
     * Whatever whitespace precedes $pos on its own line in $html --
     * empty when $pos isn't alone on its line (real templates always
     * place a placeholder tag on its own, indented line, but this
     * still has to degrade safely rather than treat arbitrary
     * preceding non-whitespace text as if it were indentation).
     */
    private function lineIndent(string $html, int $pos): string
    {
        $lineStart = strrpos(substr($html, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $prefix = substr($html, $lineStart, $pos - $lineStart);

        return trim($prefix) === '' ? $prefix : '';
    }

    /**
     * Joins $lines the same way every multi-line placeholder
     * substitution below needs: every line lands at the same visual
     * column the placeholder tag itself sat at in the source .latte
     * file, not just the first one (`implode("\n", $lines)` alone only
     * gets the first line "for free", since it's inserted exactly
     * where the placeholder was -- P41-G, docs/PLAN.md: dropping
     * `FileCombiner`'s real multi-file bundling turned what used to be
     * a single substituted line, on most real pages, into several).
     *
     * @param list<string> $lines
     */
    private function indentedJoin(string $html, int $pos, array $lines): string
    {
        return implode("\n" . $this->lineIndent($html, $pos), $lines);
    }

    /**
     * Returns `Latte\Runtime\Html` (not a plain string), since this prints
     * real markup at its own call site (`{=getCombinedScripts(...)}` in
     * every real `layout.latte`) and would otherwise be HTML-escaped by
     * Latte's auto-escaping (see docs/PLAN.md's P31 section,
     * "Auto-escaping"). Both loads return a placeholder now -- resolved
     * together, later, in `finalizeHtml()` (P41-G, docs/PLAN.md).
     */
    public function getCombinedScripts(string $load): Html
    {
        return new Html($load === 'header' ? self::COMBINED_SCRIPTS_TAG : self::COMBINED_FOOTER_SCRIPTS_TAG);
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
            return $this->imageStdParams
                ->getByType($type);
        }

        if ($width === null || $height === null) {
            $this->htmlRenderer
                ->fatalError('defineDerivative missing width or height');
        }

        $cropRatio = is_bool($crop) ? ($crop ? 1 : 0) : round(((float) $crop) / 100.0, 2);
        $minw = null;
        $minh = null;

        if ($cropRatio !== 0 && $cropRatio !== 0.0) {
            $minw = $minWidth ?? $width;
            if ($minw > $width) {
                $this->htmlRenderer
                    ->fatalError('defineDerivative invalid min_width');
            }
            $minh = $minHeight ?? $height;
            if ($minh > $height) {
                $this->htmlRenderer
                    ->fatalError('defineDerivative invalid min_height');
            }
        }

        return $this->imageStdParams
            ->getCustom($width, $height, $cropRatio, $minw, $minh);
    }

    /**
     * `Renderer::render()`'s own entry point for a `HasHeadLinks` View's
     * declared `<link>` elements (docs/PLAN.md's P42) -- builds the same
     * `rel`/`type`/`title`/`href` attribute order the old
     * `{do htmlHead(...)}` call sites hand-wrote.
     */
    public function registerHeadLink(HeadLink $link): void
    {
        $tag = '<link rel="' . $link->rel . '"';
        if ($link->type !== null) {
            $tag .= ' type="' . $link->type . '"';
        }

        if ($link->title !== null) {
            $tag .= ' title="' . $link->title . '"';
        }

        $tag .= ' href="' . $link->href . '">';

        $this->htmlHeadElements[] = $tag;
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
                $this->pageAssets->add(AssetContribution::css($f, order: 10));
            }
        }

        $f = $siteLocalDir . 'css/rules.css';
        if (file_exists($this->paths->root . $f)) {
            $this->pageAssets->add(AssetContribution::css($f, order: 10));
        }
    }

    /**
     * `{do exposeData(...)}` -- accumulates into `PageState`, like
     * `combineScript()`/`combineCss()` above accumulate into
     * `$pageAssets`, rather than being implemented directly
     * on `PiwigoExtension` the way stateless `translate()` is (see
     * docs/PLAN.md's P37 section for why the two functions below match
     * this method's own registration shape, not that one's).
     *
     * @param array<array-key, mixed>|string|int|float|bool|null $value
     */
    public function exposeData(string $key, string|int|float|bool|null|array $value): void
    {
        $this->pageState->exposeData($key, $value);
    }

    /**
     * `{do exposeString(...)}` -- same reasoning as exposeData() above.
     */
    public function exposeString(string $translationKey): void
    {
        $this->pageState->exposeString($translationKey);
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
     * Registers a typed button to be displayed on the picture page --
     * P43's typed replacement for the former raw-HTML `addPictureButton()`.
     */
    public function addPictureButton(ButtonContribution $button): void
    {
        $this->pictureButtons[$button->order][] = $button;
    }

    /**
     * Registers a typed button to be displayed on index pages -- P43's
     * typed replacement for the former raw-HTML `addIndexButton()`.
     */
    public function addIndexButton(ButtonContribution $button): void
    {
        $this->indexButtons[$button->order][] = $button;
    }

    /**
     * Registers a typed toggle-panel action to be displayed on the
     * picture page -- P43's typed replacement for the former
     * `concat('PLUGIN_PICTURE_ACTIONS', ...)`.
     */
    public function addPictureAction(ActionContribution $action): void
    {
        $this->pictureActions[$action->order][] = $action;
        $this->registerActionSwitchBox($action);
    }

    /**
     * Registers a typed toggle-panel action to be displayed on index
     * pages -- P43's typed replacement for the former
     * `concat('PLUGIN_INDEX_ACTIONS', ...)`.
     */
    public function addIndexAction(ActionContribution $action): void
    {
        $this->indexActions[$action->order][] = $action;
        $this->registerActionSwitchBox($action);
    }

    /**
     * Registers a typed label/value row to be displayed in the picture
     * page's own "imageInfoTable" list -- P43's typed replacement for a
     * hand-written `set_prefilter('picture', ...)` markup patch.
     */
    public function addPictureInfoRow(PictureInfoRow $row): void
    {
        $this->pictureInfoRows[$row->order][] = $row;
    }

    /**
     * Registers a typed field to be displayed on the registration form
     * -- P43's typed replacement for a hand-written
     * `set_prefilter('register', ...)` markup patch.
     */
    public function addRegisterField(ProfileField $field): void
    {
        $this->registerFields[$field->order][] = $field;
    }

    /**
     * Registers a typed field to be displayed on the profile-edit form
     * -- P43's typed replacement for a hand-written
     * `set_prefilter('profile_content', ...)` markup patch.
     */
    public function addProfileField(ProfileField $field): void
    {
        $this->profileFields[$field->order][] = $field;
    }

    /**
     * Registers a typed third-party sign-in button, shown on both the
     * identification and registration pages -- P43's typed replacement
     * for a hand-written `set_prefilter('identification', ...)`/
     * `set_prefilter('register', ...)` markup patch.
     */
    public function addAuthButton(AuthButton $button): void
    {
        $this->authButtons[$button->order][] = $button;
    }

    /**
     * Registers a typed icon overlay to be displayed on every thumbnail
     * on the gallery index -- P43's typed replacement for a
     * hand-written `set_prefilter('index_thumbnails', ...)` markup
     * patch.
     */
    public function addThumbnailOverlay(ThumbnailOverlay $overlay): void
    {
        $this->thumbnailOverlays[$overlay->order][] = $overlay;
    }

    /**
     * Registers a typed navigational link to be appended to the
     * menubar's own "Menu" block -- P43's typed replacement for a
     * hand-written `set_prefilter('menubar', ...)` markup patch.
     */
    public function addMenuItem(MenuItem $item): void
    {
        $this->menuItems[$item->order][] = $item;
    }

    /**
     * Requests a native form field be hidden -- P43's typed replacement
     * for a hand-written `set_prefilter('profile_content', ...)` patch
     * that hides the profile-edit form's own password fields.
     */
    public function overrideField(FieldOverride $override): void
    {
        $this->fieldOverrides[] = $override;
    }

    /**
     * Registers a typed, titled field group to be displayed on the
     * profile-edit form as its own labeled section -- P43's typed
     * replacement for the dead `$PLUGINS_PROFILE`/
     * `{include $plugin_block['template']}` mechanism (see
     * `FormProvider`'s own docblock).
     */
    public function addFormProvider(FormProvider $provider): void
    {
        $this->formProviders[$provider->order][] = $provider;
    }

    /**
     * Every real `switchBox` pair in this codebase (`themes/default/js/
     * index.js`'s own `#derivativeSwitchLink`/`#derivativeSwitchBox` etc.)
     * is wired via a `window.SwitchBox.push(link, box)` call --
     * `themes/default/js/switchbox.ts`'s own generic toggle/hide
     * behavior, already an unconditional page asset on both
     * `IndexView`/`PictureView` (`core.switchbox`). Registered here, once
     * per action, rather than requiring `index.latte`/`picture.latte` to
     * emit this JS themselves -- a plugin author gets working toggle
     * behavior for free, with no JS of their own to write (matching the
     * real `language_switch_17.0.0` plugin's own pre-P43 flag-picker,
     * which had to hand-write this same wiring itself). `json_encode()`
     * around each selector, not raw string concatenation, since `$id` is
     * plugin-supplied and this is a JS string literal rendered inside an
     * inline `<script>` tag -- the same `JSON_HEX_*` flag set
     * `PageDataPayload`'s own JSON-island encode already uses for the
     * identical `<script>`-context escaping concern.
     */
    private function registerActionSwitchBox(ActionContribution $action): void
    {
        if ($action->panel === []) {
            return;
        }

        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
        $link = json_encode('#' . $action->id . 'Link', $flags);
        $box = json_encode('#' . $action->id . 'Box', $flags);
        $this->pageAssets->add(AssetContribution::inlineScript(
            "window.SwitchBox=window.SwitchBox||[];window.SwitchBox.push({$link},{$box});",
            ['core.switchbox']
        ));
    }

    /**
     * Ksort+flatten by `$order`, same shape as `indexButtons()` below --
     * the `View`-based sibling for a migrated page's own
     * `IndexView::$pluginIndexButtons` property.
     *
     * @return list<ButtonContribution>
     */
    public function indexButtons(): array
    {
        return self::flattenByOrder($this->indexButtons);
    }

    /**
     * Ksort+flatten by `$order`, same shape as `pictureButtons()` below --
     * the `View`-based sibling for a migrated page's own
     * `PictureView::$pluginPictureButtons` property.
     *
     * @return list<ButtonContribution>
     */
    public function pictureButtons(): array
    {
        return self::flattenByOrder($this->pictureButtons);
    }

    /**
     * @return list<ActionContribution>
     */
    public function indexActions(): array
    {
        return self::flattenByOrder($this->indexActions);
    }

    /**
     * @return list<ActionContribution>
     */
    public function pictureActions(): array
    {
        return self::flattenByOrder($this->pictureActions);
    }

    /**
     * Ksort+flatten by `$order`, same shape as the 4 getters above -- the
     * `View`-based sibling for `PictureView::$pluginPictureInfoRows`.
     *
     * @return list<PictureInfoRow>
     */
    public function pictureInfoRows(): array
    {
        return self::flattenByOrder($this->pictureInfoRows);
    }

    /**
     * Ksort+flatten by `$order`, same shape as the getters above -- the
     * `View`-based sibling for `RegisterView::$pluginRegisterFields`.
     *
     * @return list<ProfileField>
     */
    public function registerFields(): array
    {
        return self::flattenByOrder($this->registerFields);
    }

    /**
     * Ksort+flatten by `$order`, same shape as the getters above -- the
     * `View`-based sibling for `ProfileFormView`/`ProfileView`'s own
     * `$pluginProfileFields`.
     *
     * @return list<ProfileField>
     */
    public function profileFields(): array
    {
        return self::flattenByOrder($this->profileFields);
    }

    /**
     * Ksort+flatten by `$order`, same shape as the getters above -- the
     * `View`-based sibling for `IdentificationView`/`RegisterView`'s
     * own `$pluginAuthButtons`.
     *
     * @return list<AuthButton>
     */
    public function authButtons(): array
    {
        return self::flattenByOrder($this->authButtons);
    }

    /**
     * Ksort+flatten by `$order`, same shape as the getters above -- the
     * `View`-based sibling for `ThumbnailsView::$pluginThumbnailOverlays`.
     *
     * @return list<ThumbnailOverlay>
     */
    public function thumbnailOverlays(): array
    {
        return self::flattenByOrder($this->thumbnailOverlays);
    }

    /**
     * Ksort+flatten by `$order`, same shape as the getters above -- read
     * by `Menu\MenubarRenderer::render()` to append to the `mbMenu`
     * block's own row list.
     *
     * @return list<MenuItem>
     */
    public function menuItems(): array
    {
        return self::flattenByOrder($this->menuItems);
    }

    /**
     * The `View`-based sibling for `ProfileFormView`/`ProfileView`'s own
     * `$pluginFieldOverrides` -- not order-keyed, see `$fieldOverrides`'s
     * own docblock.
     *
     * @return list<FieldOverride>
     */
    public function fieldOverrides(): array
    {
        return $this->fieldOverrides;
    }

    /**
     * Ksort+flatten by `$order`, same shape as the getters above -- the
     * `View`-based sibling for `ProfileFormView`/`ProfileView`'s own
     * `$pluginFormProviders`.
     *
     * @return list<FormProvider>
     */
    public function formProviders(): array
    {
        return self::flattenByOrder($this->formProviders);
    }

    /**
     * Shared by all 4 getters above -- same ksort-by-rank, flatten,
     * preserve-registration-order-within-a-rank logic the former
     * `parseIndexButtons()`/`parsePictureButtons()` methods each had
     * their own copy of.
     *
     * @template T
     * @param array<int, T[]> $byOrder
     * @return list<T>
     */
    private static function flattenByOrder(array $byOrder): array
    {
        if ($byOrder === []) {
            return [];
        }

        ksort($byOrder);
        $flattened = [];
        foreach ($byOrder as $row) {
            $flattened = array_merge($flattened, $row);
        }

        return array_values($flattened);
    }
}
