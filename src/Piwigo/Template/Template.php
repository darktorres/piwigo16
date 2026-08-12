<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Exception;
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
use Piwigo\Core\DeviceHelper;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\Event\CombinedCss;
use Piwigo\Template\Event\CombinedScript;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Template\Request\TemplateExtentsRequest;
use Smarty\Debug;
use Smarty\Smarty;
use Smarty\Template as SmartyTemplate;
use Smarty\TemplateBase;

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
 * contract (see TemplateInterface's own rationale); every mod_*()/
 * block_*()/func_*() modifier/block/function plugin's $param(s) is
 * Smarty's own tag-attribute API -- genuinely template-author-supplied,
 * already defensively is_string()/is_scalar()/is_numeric()-validated at
 * each real use site (see funcDefineDerivative() for the fullest
 * example), the same "parse, don't trust" boundary as InputValidator.
 */
final class Template implements ThemeConfProviderInterface, TemplateInterface
{
    public Smarty $smarty;

    public string $output = '';

    /**
     * @var string[] - Hash of filenames for each template handle.
     */
    public array $files = [];

    /**
     * @var string[] - Template extents filenames for each template handle.
     */
    public array $extents = [];

    /**
     * @var array<string, array<int, array<int, array{0: string, 1: callable}>>> - Templates prefilter from external sources (plugins)
     */
    public array $external_filters = [];

    /**
     * @var string[] - Content to add before </head> tag
     */
    public array $html_head_elements = [];

    /**
     * @var string - Runtime CSS rules
     */
    private string $html_style = '';

    public const string COMBINED_SCRIPTS_TAG = '<!-- COMBINED_SCRIPTS -->';

    public ScriptLoader $scriptLoader;

    public const string COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';

    public CssLoader $cssLoader;

    /**
     * @var array<int, string[]> - Runtime buttons on picture page
     */
    public array $picture_buttons = [];

    /**
     * @var array<int, string[]> - Runtime buttons on index page
     */
    public array $index_buttons = [];

    /**
     * Plain-array mirror of the directory chain `setTemplateDir()` also
     * hands to `$this->smarty->addTemplateDir()` -- Smarty's own chain
     * isn't reachable from the Latte dispatch path added for P31, so this
     * class tracks it itself instead of delegating entirely to Smarty for
     * directory resolution, same shape as `resolveLatteTemplatePath()`
     * uses it.
     *
     * @var list<string>
     */
    private array $templateDirs = [];

    private ?LatteEngine $latteEngineInstance = null;

    /**
     * Backs `once()` -- a real, per-request cross-`{include}` dedup guard.
     * Smarty's own equivalent (`{if !$smarty.capture.NAME}...{capture
     * name=NAME}1{/capture}...{/if}`, e.g. `album_selector.inc.tpl`'s guard
     * against rendering twice when included from more than one place in
     * the same page) has no Latte equivalent: a bare `{capture $var}`
     * inside an `{include}`d template is local to that one render call and
     * does not persist back to the caller for a *second*, separate
     * `{include}` of the same partial to observe -- confirmed empirically
     * (a two-include test renders the guarded body twice), including
     * against the reference's own `{if empty($var)}{capture $var}1{/capture}`
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
        private readonly PageState $pageState,
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
        // \Smarty\Exception::$escape = false;

        $this->scriptLoader = new ScriptLoader();
        $this->cssLoader = new CssLoader();
        $this->smarty = new Smarty();
        $this->smarty->escape_html = false;
        // CurrentConfig::debugTemplate() is SCHEMA-typed 'bool' only -- the
        // int=2 "per-template debug window" mode Smarty's own $debugging
        // property supports (vendor/smarty/smarty/src/Smarty.php) isn't a
        // reachable value here, so no is_int() passthrough is needed.
        $this->smarty->debugging = $this->currentConfig->debugTemplate;
        if (! $this->smarty->debugging) {
            $this->smarty->error_reporting = error_reporting() & ~E_NOTICE;
        }
        // compile_check/force_compile mirror Smarty's own setCompileCheck()/
        // setForceCompile() coercions (vendor/smarty/smarty/src/TemplateBase.php,
        // vendor/smarty/smarty/src/Smarty.php), whose own @var docblocks
        // (int / boolean respectively) don't carry the same bool|int
        // flexibility as $debugging above.
        $compile_check = $this->currentConfig->templateCompileCheck;
        $this->smarty->compile_check = (int) $compile_check;
        $this->smarty->force_compile = $this->currentConfig->templateForceCompile;

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

        $compile_dir = $this->paths->root . $conf_data_location . 'templates_c';
        FilesystemHelper::mkgetdir($compile_dir, $this->currentConfig);

        $this->smarty->setCompileDir($compile_dir);

        $this->smarty->assign('pwg', new TemplateAdapter($this->currentConfig));
        $this->smarty->registerPlugin('modifiercompiler', 'translate', $this->modcompilerTranslate(...));
        $this->smarty->registerPlugin('modifiercompiler', 'translate_dec', $this->modcompilerTranslateDec(...));
        $this->smarty->registerPlugin('modifier', 'sprintf', 'sprintf');
        $this->smarty->registerPlugin('modifier', 'urlencode', 'urlencode');
        $this->smarty->registerPlugin('modifier', 'intval', 'intval');
        $this->smarty->registerPlugin('modifier', 'file_exists', 'file_exists');
        $this->smarty->registerPlugin('modifier', 'constant', 'constant');
        $this->smarty->registerPlugin('modifier', 'json_encode', 'json_encode');
        $this->smarty->registerPlugin('modifier', 'json_decode', 'json_decode');
        $this->smarty->registerPlugin('modifier', 'htmlspecialchars', 'htmlspecialchars');
        $this->smarty->registerPlugin('modifier', 'implode', 'implode');
        $this->smarty->registerPlugin('modifier', 'stripslashes', 'stripslashes');
        $this->smarty->registerPlugin('modifier', 'in_array', 'in_array');
        $this->smarty->registerPlugin('modifier', 'ucfirst', 'ucfirst');
        $this->smarty->registerPlugin('modifier', 'strstr', 'strstr');
        $this->smarty->registerPlugin('modifier', 'stristr', 'stristr');
        $this->smarty->registerPlugin('modifier', 'trim', 'trim');
        $this->smarty->registerPlugin('modifier', 'md5', 'md5');
        $this->smarty->registerPlugin('modifier', 'strtolower', 'strtolower');
        $this->smarty->registerPlugin('modifier', 'str_ireplace', 'str_ireplace');
        $this->smarty->registerPlugin('modifier', 'explode', self::modExplode(...));
        $this->smarty->registerPlugin('modifier', 'ternary', self::modTernary(...));
        $this->smarty->registerPlugin('modifier', 'get_extent', $this->getExtent(...));
        $this->smarty->registerPlugin('block', 'html_head', $this->blockHtmlHead(...));
        $this->smarty->registerPlugin('block', 'html_style', $this->blockHtmlStyle(...));
        $this->smarty->registerPlugin('function', 'combine_script', $this->funcCombineScript(...));
        $this->smarty->registerPlugin('function', 'get_combined_scripts', $this->funcGetCombinedScripts(...));
        $this->smarty->registerPlugin('function', 'combine_css', $this->funcCombineCss(...));
        $this->smarty->registerPlugin('function', 'define_derivative', $this->funcDefineDerivative(...));
        $this->smarty->registerPlugin('compiler', 'get_combined_css', $this->funcGetCombinedCss(...));
        $this->smarty->registerPlugin('block', 'footer_script', $this->blockFooterScript(...));
        $this->smarty->registerFilter('pre', self::prefilterWhiteSpace(...));
        $this->smarty->registerPlugin('modifier', 'url_is_remote', self::urlService()->urlIsRemote(...));
        $this->smarty->registerPlugin('modifier', 'is_null', 'is_null');
        $this->smarty->registerPlugin('modifier', 'l10n', $this->lang->t(...));
        $this->smarty->registerPlugin('modifier', 'str_replace', 'str_replace');
        // AccessLevelChecker has no dependency chain that can throw -- a
        // required constructor property is safe here, including for
        // InstallWizard::render()'s own Template construction before
        // submitted DB credentials are known to be valid.
        $this->smarty->registerPlugin('modifier', 'is_admin', fn (string $userStatus = ''): bool => $this->accessLevelChecker->isAdmin($userStatus));
        $this->smarty->registerPlugin('modifier', 'is_classic_user', fn (string $userStatus = ''): bool => $this->accessLevelChecker->isClassicUser($userStatus));
        $this->smarty->registerPlugin('modifier', 'get_device', fn (): string => DeviceHelper::getDevice($this->sessionService));
        $this->smarty->registerPlugin('modifier', 'is_file', 'is_file');
        $this->smarty->registerPlugin('modifier', 'strpos', 'strpos');
        $this->smarty->registerPlugin('modifier', 'preg_match', 'preg_match');
        $this->smarty->registerPlugin('modifier', 'get_gallery_home_url', self::urlService()->getGalleryHomeUrl(...));
        $this->smarty->registerPlugin('modifier', 'sizeOf', 'sizeOf');
        $this->smarty->registerPlugin('modifier', 'array_key_exists', 'array_key_exists');

        if ($this->currentConfig->compiledTemplateCacheLanguage) {
            $this->smarty->registerFilter('post', self::postfilterLanguage(...));
        }

        $this->smarty->setTemplateDir([]);
        if ($theme instanceof ThemeId) {
            $this->setTheme($root, $theme, $path);
            if (! $this->adminContext->isActive()) {
                $this->setPrefilter('header', fn (string $source, SmartyTemplate $smarty): string => self::prefilterLocalCss($source, $smarty, $this->paths));
            }
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
        $this->smarty->assign('lang_info', $lang_info);

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
     * `public` (unlike urlService() above) and referenced by its
     * fully-qualified name: the generated PHP source text
     * (modcompilerTranslate()/modcompilerTranslateDec()'s own output)
     * is spliced by Smarty into `templates_c/*.php` compiled-cache files
     * and executed later by a Smarty-internal render function with no
     * `Template` instance (`$this`) or class scope available at all -- a
     * `private`/`self::`-style resolver like urlService() isn't reachable
     * from there, only a real `public static` method called by its
     * fully-qualified class name is. No pre-boot fallback needed: a
     * Smarty template only ever compiles/renders after a real request
     * has fully booted.
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
     * funcDefineDerivative() actually running.
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

        // Real, live case: search_filters.inc.tpl's own {include
        // file='themes/admin/default/template/include/album_selector.inc.tpl'}
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

        $this->smarty->append('themes', $tpl_var);
        $this->smarty->append('themeconf', $themeconf, true);
    }

    /**
     * Adds template directory for this Template object.
     * Also set compile id if not exists.
     */
    public function setTemplateDir(string $dir): void
    {
        $this->smarty->addTemplateDir($dir);
        $this->templateDirs[] = $dir;

        if (! isset($this->smarty->compile_id)) {
            $compile_id = '1';
            $compile_id .= ($real_dir = realpath($dir)) === false ? $dir : $real_dir;
            $this->smarty->compile_id = base_convert(hash('crc32b', $compile_id), 16, 36);
        }
    }

    /**
     * Gets the template root directory for this Template object.
     */
    public function getTemplateDir(): string
    {
        $dir = $this->smarty->getTemplateDir(0);
        return is_string($dir) ? $dir : '';
    }

    /**
     * Deletes all compiled templates -- both engines' caches during the
     * P31 transition, since a site admin clicking "clear template cache"
     * reasonably expects both cleared together, not just whichever engine
     * happens to render the majority of templates right now. `Piwigo\
     * Command\CacheClearCommand` (the CLI `cache:clear`) is a *different*
     * mechanism with a deliberately narrower, Latte-only scope -- see that
     * class's own docblock.
     */
    public function deleteCompiledTemplates(): void
    {
        $save_compile_id = $this->smarty->compile_id;
        $this->smarty->compile_id = null;
        $this->smarty->clearCompiledTemplate();
        $this->smarty->compile_id = $save_compile_id;
        file_put_contents($this->smarty->getCompileDir() . '/index.htm', 'Not allowed!');

        $this->clearLatteCacheDir(LatteEngine::defaultCacheDir($this->paths->root, $this->currentConfig->dataLocation));
    }

    /**
     * Returns theme's parameter.
     */
    public function getThemeconf(string $val): mixed
    {
        $tc = $this->smarty->getTemplateVars('themeconf');
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
     * Sets the template filename for handle.
     */
    #[Override]
    public function setFilename(string $handle, string $filename): bool
    {
        return $this->setFilenames([
            $handle => $filename,
        ]);
    }

    /**
     * Sets the template filenames for handles.
     *
     * @param array<string, string|null> $filename_array hashmap of
     *   handle=>filename; a null value unsets that handle (no current
     *   first-party caller exercises this, but the API supports it)
     */
    public function setFilenames(array $filename_array): bool
    {
        reset($filename_array);
        foreach ($filename_array as $handle => $filename) {
            if ($filename === null) {
                unset($this->files[$handle]);
            } else {
                $this->files[$handle] = $this->preferLatteSibling($this->getExtent($filename, $handle));
            }
        }
        return true;
    }

    /**
     * If `$filename` (e.g. `'header.tpl'`) has a `.latte` sibling
     * somewhere in the current theme's directory chain, prefer it.
     *
     * Needed for callers shared across themes that haven't converted at
     * the same time -- confirmed live converting `header.tpl`:
     * `PageHeaderRenderer` renders through the same `setFilename('header',
     * 'header.tpl')` + `parse('header')` handle-based call for *every*
     * theme, admin included. Without this check, `parse()`'s dispatch
     * (see below) would see the handle is registered and always take the
     * Smarty path, even for a theme whose `header.tpl` has since become
     * `header.latte` -- Smarty would then fail to find a file that no
     * longer exists. This lets that one shared call site keep working
     * unmodified while each theme converts on its own schedule; no
     * per-caller theme-awareness needed.
     */
    private function preferLatteSibling(string $filename): string
    {
        if (! str_ends_with($filename, '.tpl')) {
            return $filename;
        }
        $latteName = substr($filename, 0, -4) . '.latte';
        foreach ($this->templateDirs as $dir) {
            if (file_exists(rtrim($dir, '/') . '/' . $latteName)) {
                return $latteName;
            }
        }

        return $filename;
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
        $this->smarty->assign($context->toArray());
    }

    /**
     * Defines _$varname_ as the compiled result of _$handle_.
     * This can be used to effectively include a template in another template.
     * This is equivalent to $this->smarty->assign($varname, $this->parse($handle, true)).
     *
     * @return true
     */
    #[Override]
    public function assignVarFromHandle(string $varname, string $handle): bool
    {
        $this->smarty->assign($varname, $this->parse($handle, true));
        return true;
    }

    /**
     * Renders `$file` (a real filename, e.g. `'popuphelp.latte'` --
     * resolved the same way `parse()` resolves one) and assigns the result
     * to `$varname`, wrapped in `Latte\Runtime\Html` so it propagates
     * through Latte's auto-escape unmolested at every `{$var}` print site
     * downstream -- the direct-filename sibling of `assignVarFromHandle()`
     * above, added for already-converted callers. Both coexist until every
     * caller has migrated (docs/PLAN.md's P31 section) and
     * `assignVarFromHandle()` is removed.
     */
    #[Override]
    public function assignVarFromTemplate(string $varname, string $file): void
    {
        $rendered = $this->parse($file, true);
        $this->smarty->assign($varname, new Html($rendered));
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
     */
    public function concat(string $tpl_var, string $value): void
    {
        $current = $this->smarty->getTemplateVars($tpl_var);
        $this->smarty->assign(
            $tpl_var,
            (is_string($current) ? $current : '') . $value
        );
    }

    /**
     * Removes an assigned template variable.
     * @see http://www.smarty.net/manual/en/api.clear_assign.php
     */
    #[Override]
    public function clearAssign(string $tpl_var): void
    {
        $this->smarty->clearAssign($tpl_var);
    }

    /**
     * Returns an assigned template variable.
     * @see http://www.smarty.net/manual/en/api.get_template_vars.php
     */
    public function getTemplateVars(?string $tpl_var = null): mixed
    {
        return $this->smarty->getTemplateVars($tpl_var);
    }

    /**
     * Renders `$handle` and appends the result to the output (or returns it
     * if `$return` is true).
     *
     * Dispatches on the *resolved* file's real extension, not merely on
     * whether `$handle` happens to be a registered handle -- a handle
     * registered via `setFilename()`/`setFilenames()` may have already
     * been resolved to a `.latte` sibling by `preferLatteSibling()`
     * (see that method's own docblock for why this matters: a caller
     * shared across themes, like `PageHeaderRenderer`, must pick up a
     * per-theme conversion automatically). If `$handle` isn't a
     * registered handle at all, it's treated as a real filename directly
     * (the calling convention added for P31 -- see docs/PLAN.md's P31
     * section, "Transition strategy"). Both calling conventions coexist
     * until every caller has migrated; `setFilename()`/`setFilenames()`
     * and the handle-lookup branch are removed together once they are
     * (P31.7).
     *
     * @phpstan-return ($return is true ? string : null)
     */
    public function parse(string $handle, bool $return = false): ?string
    {
        if (isset($this->files[$handle])) {
            if (str_ends_with($this->files[$handle], '.latte')) {
                return $this->parseLatte($this->files[$handle], $return);
            }

            return $this->parseSmarty($handle, $return);
        }

        return $this->parseLatte($handle, $return);
    }

    /**
     * @phpstan-return ($return is true ? string : null)
     */
    private function parseSmarty(string $handle, bool $return): ?string
    {
        $this->smarty->assign('ROOT_URL', self::urlService()->getRootUrl());
        // ROOT_PATH is the .tpl-side equivalent of PHPWG_ROOT_PATH for
        // the handful of templates that need a real filesystem existence
        // check (datepicker.inc.tpl/photos_add_direct.tpl's own
        // `{if $ROOT_PATH|@cat:...|@file_exists}`), not a URL -- ROOT_URL
        // above is request-relative and wrong for file_exists().
        $this->smarty->assign('ROOT_PATH', $this->paths->root);

        $save_compile_id = $this->smarty->compile_id;
        $this->loadExternalFilters($handle);

        $lang_info = $this->lang->langInfo();
        if ($this->currentConfig->compiledTemplateCacheLanguage and isset($lang_info['code']) and is_string($lang_info['code'])) {
            $this->smarty->compile_id .= '_' . $lang_info['code'];
        }

        $v = $this->smarty->fetch($this->files[$handle]);

        $this->smarty->compile_id = $save_compile_id;
        $this->unloadExternalFilters($handle);

        if ($return) {
            return $v;
        }
        $this->output .= $v;

        return null;
    }

    /**
     * @phpstan-return ($return is true ? string : null)
     */
    private function parseLatte(string $file, bool $return): ?string
    {
        // Resolve first, exactly like parseSmarty()'s own "does this exist
        // at all" check runs before any other work -- a genuinely
        // unresolvable $file (neither a registered Smarty handle nor a
        // real Latte file) must fail here, before touching urlService()/
        // Lang/etc. below. Confirmed live: reordering this after the
        // ROOT_URL assign changed which exception a broken-container edge
        // case surfaces (TemplateTest.php's own "htmlRenderer resolver
        // throws" case) -- resolving first keeps that failure path
        // identical to parseSmarty()'s.
        $path = $this->resolveLatteTemplatePath($file);

        // Same ROOT_URL/ROOT_PATH assigns as parseSmarty() above, into the
        // same shared var storage -- so both engines see them, and any
        // later template rendered in this same request (Smarty or Latte)
        // still finds them already assigned.
        $this->smarty->assign('ROOT_URL', self::urlService()->getRootUrl());
        $this->smarty->assign('ROOT_PATH', $this->paths->root);
        // Smarty::getTemplateVars(null) always returns the full assigned-var
        // array, string-keyed by template variable name (vendor/smarty/
        // smarty/src/Data.php's own array_merge(...)-based implementation)
        // -- the vendor stub just doesn't declare that precisely, hence the
        // real (not cast-based) narrowing below.
        $rawParams = $this->smarty->getTemplateVars();
        $params = [];
        if (is_array($rawParams)) {
            foreach ($rawParams as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
        }

        $v = $this->latteEngine()
            ->render($path, $params);

        if ($return) {
            return $v;
        }
        $this->output .= $v;

        return null;
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

        if ((bool) count($this->html_head_elements) || (bool) strlen($this->html_style)) {
            $search = "\n</head>";
            $pos = strpos($this->output, $search);
            if ($pos !== false) {
                $rep = "\n" . implode("\n", $this->html_head_elements);
                if ((bool) strlen($this->html_style)) {
                    $rep .= '<style type="text/css">' . $this->html_style . '</style>';
                }
                $this->output = substr_replace($this->output, $rep, $pos, 0);
            } // else maybe error or warning ?
            $this->html_head_elements = [];
            $this->html_style = '';
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
     * Same as flush() but with optional debugging.
     * @see Template::flush()
     */
    public function p(): void
    {
        $this->flush();

        if ((bool) $this->smarty->debugging) {
            $this->smarty->assign(
                [
                    'AAAA_DEBUG_TOTAL_TIME__' => TimingHelper::getElapsedTime($this->pageState->requestStart, TimingHelper::getMoment()),
                ]
            );
            // Smarty\Debug::display_debug() unconditionally calls
            // $obj->getSource() (vendor/smarty/smarty/src/Debug.php) before
            // ever checking its own `$obj instanceof Smarty` branch --
            // Smarty\Smarty itself has no getSource() method (only
            // Smarty\Template/Smarty\Template\Cached do), so passing the
            // bare engine here always throws `Error: Call to undefined
            // method Smarty\Smarty::getSource()`. A throwaway 'string:'
            // resource template gives display_debug() a real getSource()
            // to read, taking its Template branch instead (labels the
            // console 'string:' rather than aggregating per-template
            // timings) -- debug.tpl's own markup treats
            // template_name/template_data as optional, so this degrades
            // gracefully instead of reproducing the crash.
            new Debug()
                ->display_debug($this->smarty->createTemplate('string:'), true);
        }
    }

    /**
     * Eval a temp string to retrieve the original PHP value.
     */
    public static function getPhpStrVal(string $str): mixed
    {
        if (strlen($str) > 1) {
            if (($str[0] === '\'' && $str[strlen($str) - 1] === '\'')
              || ($str[0] === '"' && $str[strlen($str) - 1] === '"')) {
                // $tmp is always really reassigned by the eval() below --
                // this initializer exists only to give PHPStan a definite
                // assignment to trace, since it can't see into eval()'s
                // string content (same blind spot as prefilterWhiteSpace()
                // below).
                $tmp = null;
                eval('$tmp=' . $str . ';');
                return $tmp;
            }
        }
        return null;
    }

    /**
     * "translate" variable modifier.
     * Usage :
     *    - {'Comment'|translate}
     *    - {'%d comments'|translate:$count}
     * @see Template::lang()
     * @param array<int, string> $params
     */
    public function modcompilerTranslate(array $params): string
    {

        switch (count($params)) {
            case 1:
                $key = self::getPhpStrVal($params[0]);
                // getPhpStrVal() evaluates a quoted PHP string literal
                // via eval(), which PHPStan can't trace the return type of
                // -- it's always a real string here since $params[0] is a
                // template-compiled string literal expression, but narrow
                // explicitly since the callee's return type is opaque.
                if ($this->currentConfig->compiledTemplateCacheLanguage
                  && is_string($key)
                  && $this->lang->has($key)
                ) {
                    return var_export($this->lang->t($key), true);
                }
                // Deliberately NOT $this->lang->t(...) -- this string is
                // literal PHP source text Smarty splices into the compiled
                // templates_c cache file (Smarty's own "modifiercompiler"
                // mechanism -- see this method's own registration in
                // __construct()), executed later by a Smarty-internal
                // render function with no `$this` of this Template
                // instance available. Reached whenever the translation
                // can't be resolved at compile time (cache-by-language is
                // off -- the common, default case -- or the key is a
                // runtime variable, or it's an unknown key): self::lang()
                // (a public static resolver) is a permanent exception to
                // this class's constructor-injected dependencies, needed
                // because no `$this` is available where this generated
                // code runs.
                return '\Piwigo\Template\Template::lang()->t(' . $params[0] . ')';

            default:
                if ($this->currentConfig->compiledTemplateCacheLanguage) {
                    $ret = 'sprintf(';
                    $ret .= $this->modcompilerTranslate([$params[0]]);
                    $ret .= ',' . implode(',', array_slice($params, 1));
                    $ret .= ')';
                    return $ret;
                }
                // Same permanent-exception reasoning as the single-param
                // branch above.
                return '\Piwigo\Template\Template::lang()->t(' . $params[0] . ',' . implode(',', array_slice($params, 1)) . ')';
        }
    }

    /**
     * "translate_dec" variable modifier.
     * Usage :
     *    - {$count|translate_dec:'%d comment':'%d comments'}
     * @see Template::lang()
     * @param array<int, string> $params
     */
    public function modcompilerTranslateDec(array $params): string
    {
        if ($this->currentConfig->compiledTemplateCacheLanguage) {
            $ret = 'sprintf(';
            if ((bool) $this->lang->langInfo()['zero_plural']) {
                $ret .= '($tmp=(' . $params[0] . '))>1||$tmp==0';
            } else {
                $ret .= '($tmp=(' . $params[0] . '))>1';
            }
            $ret .= '?';
            $ret .= $this->modcompilerTranslate([$params[2]]);
            $ret .= ':';
            $ret .= $this->modcompilerTranslate([$params[1]]);
            $ret .= ',$tmp';
            $ret .= ')';
            return $ret;
        }
        // Permanent exception -- see modcompilerTranslate()'s own comment
        // on its identical single-param-branch return above.
        return '\Piwigo\Template\Template::lang()->plural(' . $params[1] . ',' . $params[2] . ',' . $params[0] . ')';
    }

    /**
     * "explode" variable modifier.
     * Usage :
     *    - {assign var=valueExploded value=$value|explode:','}
     *
     * @return string[]
     */
    public static function modExplode(string $text, string $delimiter = ','): array
    {
        if ($delimiter === '') {
            throw new Exception('modExplode(): delimiter must not be empty');
        }
        return explode($delimiter, $text);
    }

    /**
     * ternary variable modifier.
     * Usage :
     *    - {$variable|ternary:'yes':'no'}
     */
    public static function modTernary(mixed $param, mixed $true, mixed $false): mixed
    {
        return (bool) $param ? $true : $false;
    }

    /**
     * The "html_head" block allows to add content just before
     * </head> element in the output after the head has been parsed.
     *
     * @param array<int, mixed> $params (unused)
     */
    public function blockHtmlHead(array $params, ?string $content): void
    {
        // Smarty calls block plugins twice: null $content on the opening
        // tag, real content on the closing tag ("second call" below).
        $content = trim((string) $content);
        if ($content !== '') { // second call
            $this->html_head_elements[] = $content;
        }
    }

    /**
     * The "html_style" block allows to add CSS juste before
     * </head> element in the output after the head has been parsed.
     *
     * @param array<int, mixed> $params (unused)
     */
    public function blockHtmlStyle(array $params, ?string $content): void
    {
        // Smarty calls block plugins twice: null $content on the opening
        // tag, real content on the closing tag ("second call" below).
        $content = trim((string) $content);
        if ($content !== '') { // second call
            $this->html_style .= "\n" . $content;
        }
    }

    /**
     * The "define_derivative" function allows to define derivative from tpl file.
     * It assigns a DerivativeParams object to _name_ template variable.
     *
     * @param array<string, mixed> $params
     *    - name (required)
     *    - type (optional)
     *    - width (required if type is empty)
     *    - height (required if type is empty)
     *    - crop (optional, used if type is empty)
     *    - min_width (optional, used with crop)
     *    - min_height (optional, used with crop)
     */
    public function funcDefineDerivative(array $params, TemplateBase $smarty): void
    {
        $name = $params['name'] ?? null;
        (! in_array($name, [null, false, 0, '0', '', []], true) && is_string($name)) or $this->htmlRenderer()
            ->fatalError('define_derivative missing name');
        if (isset($params['type'])) {
            $type = $params['type'];
            is_string($type) or $this->htmlRenderer()
                ->fatalError('define_derivative type must be a string');
            $derivative = $this->imageStdParams()
                ->getByType($type);
            $smarty->assign($name, $derivative);
            return;
        }
        ! in_array($params['width'] ?? null, [null, false, 0, '0', '', []], true) or $this->htmlRenderer()->fatalError('define_derivative missing width');
        ! in_array($params['height'] ?? null, [null, false, 0, '0', '', []], true) or $this->htmlRenderer()->fatalError('define_derivative missing height');
        $width = $params['width'];
        $height = $params['height'];
        is_scalar($width) or $this->htmlRenderer()
            ->fatalError('define_derivative width must be scalar');
        is_scalar($height) or $this->htmlRenderer()
            ->fatalError('define_derivative height must be scalar');

        $w = intval($width);
        $h = intval($height);
        $crop = 0;
        $minw = null;
        $minh = null;

        if (isset($params['crop'])) {
            if (is_bool($params['crop'])) {
                $crop = $params['crop'] ? 1 : 0;
            } else {
                $crop_val = $params['crop'];
                is_numeric($crop_val) or $this->htmlRenderer()
                    ->fatalError('define_derivative crop must be numeric');
                $crop = round((float) $crop_val / 100.0, 2);
            }

            if ((bool) $crop) {
                if (in_array($params['min_width'] ?? null, [null, false, 0, '0', '', []], true)) {
                    $minw = $w;
                } else {
                    $min_width = $params['min_width'];
                    is_scalar($min_width) or $this->htmlRenderer()
                        ->fatalError('define_derivative min_width must be scalar');
                    $minw = intval($min_width);
                }
                $minw <= $w or $this->htmlRenderer()
                    ->fatalError('define_derivative invalid min_width');
                if (in_array($params['min_height'] ?? null, [null, false, 0, '0', '', []], true)) {
                    $minh = $h;
                } else {
                    $min_height = $params['min_height'];
                    is_scalar($min_height) or $this->htmlRenderer()
                        ->fatalError('define_derivative min_height must be scalar');
                    $minh = intval($min_height);
                }
                $minh <= $h or $this->htmlRenderer()
                    ->fatalError('define_derivative invalid min_height');
            }
        }

        $smarty->assign($name, $this->imageStdParams()->getCustom($w, $h, $crop, $minw, $minh));
    }

    /**
     * The "combine_script" functions allows inclusion of a javascript file in the current page.
     * The engine will combine several js files into a single one.
     *
     * @param array $params
     *   - id (required)
     *   - path (optional) falls back to ScriptLoader's well-known script paths when omitted
     *   - load (optional) 'header', 'footer' or 'async'
     *   - require (optional) comma separated list of script ids required to be loaded
     *     and executed before this one
     *   - version (optional) used to force a browser refresh
     * @param array<string, mixed> $params
     */
    public function funcCombineScript(array $params): void
    {
        if (! isset($params['id']) || ! is_string($params['id'])) {
            // recordFatal() records the fatal condition without halting
            // execution (see HtmlService::fatalError()'s own docblock for
            // the reasoning) -- this return is what actually prevents
            // $params['id'] from being read below when it's genuinely
            // missing/non-string.
            $this->errorCollector->recordFatal("combine_script: missing 'id' parameter");

            return;
        }
        $id = $params['id'];
        $load = 0;
        if (isset($params['load'])) {
            switch ($params['load']) {
                case 'header': break;
                case 'footer': $load = 1;
                    break;
                case 'async': $load = 2;
                    break;
                default: $this->errorCollector->recordFatal("combine_script: invalid 'load' parameter");
            }
        }

        $require = $params['require'] ?? null;
        $require_list = (! in_array($require, [null, false, 0, '0', '', []], true) && is_scalar($require)) ? explode(',', (string) $require) : [];

        $path = $params['path'] ?? null;
        $path = is_string($path) ? $path : null;

        $version = $params['version'] ?? '0';
        $version = ($version === false || is_string($version)) ? $version : '0';

        $this->scriptLoader->add(
            $id,
            $load,
            $require_list,
            $path,
            $version,
            (bool) ($params['template'] ?? false)
        );
    }

    /**
     * The "get_combined_scripts" function returns HTML tag of combined scripts.
     * It can returns a placeholder for delayed JS files combination and minification.
     *
     * @param array $params
     *    - load (required)
     * @param array<string, mixed> $params
     */
    public function funcGetCombinedScripts(array $params): string
    {
        if (! isset($params['load'])) {
            $this->errorCollector->recordFatal("get_combined_scripts: missing 'load' parameter");
        }
        $load = $params['load'] === 'header' ? 0 : 1;
        $content = [];

        if ($load === 0) {
            return self::COMBINED_SCRIPTS_TAG;
        } else {
            $scripts = $this->scriptLoader->getFooterScripts($this->accessLevelChecker);
            foreach ($scripts->sync as $script) {
                $content[] =
                  '<script type="text/javascript" src="'
                  . $this->makeScriptSrc($script)
                  . '"></script>';
            }
            if ((bool) count($this->scriptLoader->inline_scripts)) {
                $content[] = '<script type="text/javascript">//<![CDATA[
';
                $content = array_merge($content, $this->scriptLoader->inline_scripts);
                $content[] = '//]]></script>';
            }

            if ((bool) count($scripts->async)) {
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
        }
        return implode("\n", $content);
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
     * The "footer_script" block allows to add runtime script in the HTML page.
     *
     * @param array<string, mixed> $params
     *    - require (optional) comma separated list of script ids
     */
    public function blockFooterScript(array $params, ?string $content): void
    {
        // Smarty calls block plugins twice: null $content on the opening
        // tag, real content on the closing tag ("second call" below).
        $content = trim((string) $content);
        if ($content !== '') { // second call
            $require = $params['require'] ?? null;
            $require_list = (! in_array($require, [null, false, 0, '0', '', []], true) && is_scalar($require)) ? explode(',', (string) $require) : [];

            $this->scriptLoader->addInline(
                $content,
                $require_list
            );
        }
    }

    /**
     * The "combine_css" function allows inclusion of a css file in the current page.
     * The engine will combine several css files into a single one.
     *
     * @param array $params
     *    - id (optional) used to deal with multiple inclusions from plugins
     *    - path (required)
     *    - version (optional) used to force a browser refresh
     *    - order (optional)
     *    - template (optional) set to true to allow smarty syntax in the css file
     * @param array<string, mixed> $params
     */
    public function funcCombineCss(array $params): void
    {
        if (in_array($params['path'] ?? null, [null, false, 0, '0', '', []], true) || ! is_string($params['path'])) {
            $this->htmlRenderer()
                ->fatalError('combine_css missing path');
        }
        $path = $params['path'];

        if (! isset($params['id']) || ! is_string($params['id'])) {
            $id = md5($path);
        } else {
            $id = $params['id'];
        }

        $version = $params['version'] ?? '0';
        $version = ($version === false || is_string($version)) ? $version : '0';

        $order = $params['order'] ?? 0;
        $order = is_numeric($order) ? (int) $order : 0;

        $this->cssLoader->add($id, $path, $version, $order, (bool) ($params['template'] ?? false));
    }

    /**
     * The "get_combined_css" function returns a placeholder for delayed
     * CSS files combination and minification.
     *
     * @param array<int, mixed> $params (unused)
     */
    public function funcGetCombinedCss(array $params): string
    {
        return self::COMBINED_CSS_TAG;
    }

    /**
     * Latte-facing sibling of `funcCombineScript()` above -- same
     * `ScriptLoader::add()` call, real typed named parameters instead of a
     * loose Smarty tag-attribute array. `$id` has no default, so a `.latte`
     * template omitting it gets a real PHP `ArgumentCountError` at the call
     * site rather than `funcCombineScript()`'s own soft
     * `$errorCollector->recordFatal()` -- a deliberate strengthening
     * (real, required arguments now backed by PHP's own type system), not
     * a behavior gap: no real converted template omits it.
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
     * Latte-facing sibling of `funcGetCombinedScripts()` above -- same
     * body, wrapped in `Latte\Runtime\Html` since this one (unlike
     * `combineScript()`) prints real markup at its own call site and would
     * otherwise be HTML-escaped by Latte's auto-escaping (see
     * docs/PLAN.md's P31 section, "Auto-escaping").
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
        if ($this->scriptLoader->inline_scripts !== []) {
            $content[] = '<script type="text/javascript">//<![CDATA[
';
            $content = array_merge($content, $this->scriptLoader->inline_scripts);
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
     * Latte-facing sibling of `funcCombineCss()` above -- same
     * `CssLoader::add()` call, real typed named parameters. `$path` has no
     * default, same reasoning as `combineScript()`'s `$id` above.
     */
    public function combineCss(string $path, ?string $id = null, string|false $version = '0', int $order = 0, bool $template = false): void
    {
        $this->cssLoader->add($id ?? md5($path), $path, $version, $order, $template);
    }

    /**
     * Latte-facing sibling of `funcGetCombinedCss()` above -- prints the
     * placeholder literal, so it needs the same `Html` wrap as
     * `getCombinedScripts()` above.
     */
    public function getCombinedCss(): Html
    {
        return new Html(self::COMBINED_CSS_TAG);
    }

    /**
     * Latte-facing sibling of `funcDefineDerivative()` above. Smarty's
     * version assigned a `DerivativeParams` into the compiling template's
     * own scope via a `name` parameter; Latte has no equivalent scope-
     * mutation hook, so this returns the value instead, bound at the call
     * site via `{var $x = defineDerivative(...)}` -- confirmed real usage
     * in `piwigo16-rewrite/tools/smarty-to-latte/Converter.php:602-632`.
     * `$crop` folds Smarty's own dual bool/numeric-percentage handling
     * into one parameter (bool: whole 0/1 crop ratio; float|int: a percent,
     * matching `funcDefineDerivative()`'s own `$crop_val / 100.0` math).
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
     * Latte-facing sibling of `blockHtmlHead()` above -- called via
     * `{capture $v}...{/capture}{do htmlHead($v)}` (Latte's own native
     * tags compose the same open/close content-capture Smarty's block
     * plugin API gave `blockHtmlHead()`, so no custom Latte tag is needed;
     * see docs/PLAN.md's P31 section, "Blocks/functions"). Takes the
     * content directly rather than being called twice with `null` then the
     * real body -- `{capture}` only ever hands back the final string once.
     */
    public function htmlHead(string|Html $content): void
    {
        $trimmed = trim((string) $content);
        if ($trimmed !== '') {
            $this->html_head_elements[] = $trimmed;
        }
    }

    /**
     * Latte-facing sibling of `blockHtmlStyle()` above -- same
     * `{capture}`+`{do}` composition as `htmlHead()`.
     */
    public function htmlStyle(string|Html $content): void
    {
        $trimmed = trim((string) $content);
        if ($trimmed !== '') {
            $this->html_style .= "\n" . $trimmed;
        }
    }

    /**
     * Latte-facing sibling of `blockFooterScript()` above -- same
     * `{capture}`+`{do}` composition, same `ScriptLoader::addInline()`
     * call.
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
     * Latte-facing port of `prefilterLocalCss()`'s real logic -- NOT the
     * same thing as `$theme.local_head` (that's already an ordinary
     * `{include}` on both engines, no special mechanism at all; see
     * docs/PLAN.md's P31 section, "Prefilters"). `prefilterLocalCss()`
     * injects `{combine_css}` calls for admin-configurable override files
     * at *compile time*; since `combine_css` is now a plain function
     * rather than a Smarty tag, this moves the same `file_exists()`-gated
     * walk to an explicit call from the converted `header.latte` instead
     * of a source-text injection. Only ever needed on the front-end
     * `'header'` handle today (`prefilterLocalCss()`'s own registration is
     * gated on `! $adminContext->isActive()`), but this method itself
     * doesn't need that guard -- it's simply never called from an admin
     * template.
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
     * Declares a Smarty prefilter from a plugin, allowing it to modify template
     * source before compilation and without changing core files.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.prefilters.php
     */
    public function setPrefilter(string $handle, callable $callback, int $weight = 50): void
    {
        $this->external_filters[$handle][$weight][] = ['pre', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Declares a Smarty postfilter.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.postfilters.php
     */
    public function setPostfilter(string $handle, callable $callback, int $weight = 50): void
    {
        $this->external_filters[$handle][$weight][] = ['post', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Declares a Smarty outputfilter.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.outputfilters.php
     */
    public function setOutputfilter(string $handle, callable $callback, int $weight = 50): void
    {
        $this->external_filters[$handle][$weight][] = ['output', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Register the filters for the tpl file.
     */
    public function loadExternalFilters(string $handle): void
    {
        if (isset($this->external_filters[$handle])) {
            $compile_id = '';
            foreach ($this->external_filters[$handle] as $filters) {
                foreach ($filters as $filter) {
                    [$type, $callback] = $filter;
                    if (is_array($callback)) {
                        $callback_key = implode('', array_map(
                            static fn (mixed $part): string => is_string($part) ? $part : get_debug_type($part),
                            $callback
                        ));
                    } elseif (is_string($callback)) {
                        $callback_key = $callback;
                    } else {
                        $callback_key = get_debug_type($callback);
                    }
                    $compile_id .= $type . $callback_key;
                    $this->smarty->registerFilter($type, $callback);
                }
            }
            $this->smarty->compile_id .= '.' . base_convert(hash('crc32b', $compile_id), 16, 36);
        }
    }

    /**
     * Unregister the filters for the tpl file.
     */
    public function unloadExternalFilters(string $handle): void
    {
        if (isset($this->external_filters[$handle])) {
            foreach ($this->external_filters[$handle] as $filters) {
                foreach ($filters as $filter) {
                    [$type, $callback] = $filter;
                    $this->smarty->unregisterFilter($type, $callback);
                }
            }
        }
    }

    /**
     * Strips leading tab/space indentation immediately before each
     * recognized Smarty block/include tag (and their closing counterparts,
     * where applicable), so the compiled template's literal output doesn't
     * carry stray leading whitespace from the source .tpl's own
     * indentation. `\s*$` is greedy enough to also eat the source's final
     * trailing newline when a recognized tag is the very last line with
     * nothing following -- a pre-existing quirk of this regex (see
     * TemplateTest.php's own "prefilterWhiteSpace strips leading
     * whitespace..." test), invisible in real compiled HTML output and not
     * worth a regex behavior change on its own.
     *
     * $smarty accepts both real shapes this static method is actually
     * called with: Smarty's own filter dispatch (Smarty\Extension\
     * BCPluginsAdapter -> Smarty\Filter\FilterPluginWrapper::filter())
     * always passes the currently-compiling `Smarty\Template`, never the
     * bare engine -- but this method's own Unit tests call it directly
     * against `Smarty\Smarty`, and `getLeftDelimiter()`/`getRightDelimiter()`
     * below are declared separately on each of those two classes, not on
     * their shared `TemplateBase` ancestor, so neither type alone covers
     * both real call sites.
     */
    public static function prefilterWhiteSpace(string $source, Smarty|SmartyTemplate $smarty): ?string
    {
        $ld = $smarty->getLeftDelimiter();
        $rd = $smarty->getRightDelimiter();
        // $ld = $smarty->left_delimiter;
        // $rd = $smarty->right_delimiter;
        $ldq = preg_quote($ld, '#');
        $rdq = preg_quote($rd, '#');

        $regex = [];
        $tags = ['if', 'foreach', 'section', 'footer_script'];
        foreach ($tags as $tag) {
            $regex[] = "#^[ \t]+({$ldq}{$tag}[^{$ld}{$rd}]*{$rdq})\s*$#m";
            $regex[] = "#^[ \t]+({$ldq}/{$tag}{$rdq})\s*$#m";
        }
        $tags = ['include', 'else', 'combine_script', 'html_head'];
        foreach ($tags as $tag) {
            $regex[] = "#^[ \t]+({$ldq}{$tag}[^{$ld}{$rd}]*{$rdq})\s*$#m";
        }
        $source = preg_replace($regex, '$1', $source);
        return $source;
    }

    /**
     * Postfilter used when \Piwigo\Config\CurrentConfig::compiledTemplateCacheLanguage() is true.
     *
     * $smarty is unused below -- it exists only to match Smarty's own
     * post-filter callback signature (same real-call-site duality as
     * prefilterWhiteSpace() above: Smarty's own dispatch always passes a
     * `Smarty\Template`, this method's own Unit tests pass the bare
     * `Smarty\Smarty` engine), so the shared `TemplateBase` ancestor
     * covers both without needing either subclass's own members.
     */
    public static function postfilterLanguage(string $source, TemplateBase $smarty): ?string
    {
        // replaces echo PHP_STRING_LITERAL; with the string literal value
        $source = preg_replace_callback(
            '/\\<\\?php echo ((?:\'(?:(?:\\\\.)|[^\'])*\')|(?:"(?:(?:\\\\.)|[^"])*"));\\?\\>\\n/',
            /**
             * @param array<string> $matches
             */
            function (array $matches): string {
                eval('$tmp=' . $matches[1] . ';');
                // $matches[1] is always a quoted PHP string literal (per the
                // regex above), so eval() always produces a real string here.
                // PHPStan treats variables only ever assigned inside eval()
                // as undefined in the enclosing scope (it doesn't parse the
                // evaluated string) -- there's no provable guard possible.
                // Tried the same pre-initialize-before-eval() trick that
                // fixed getPhpStrVal()'s bare `return $tmp;` above --
                // backfires here specifically because of the isset() guard:
                // pre-setting $tmp = null makes PHPStan conclude isset($tmp)
                // is always false (a variable PHPStan believes always exists
                // and is always null), so the ?: 'string cast' branch became
                // unreachable *NEVER* instead. This is a genuine
                // static-analysis blind spot on eval(), not a missed
                // narrowing opportunity.
                // @phpstan-ignore cast.string, isset.variable, variable.undefined
                return isset($tmp) ? (string) $tmp : '';
            },
            $source
        );
        return $source;
    }

    /**
     * Prefilter used to add theme local CSS files.
     *
     * Registered against a real Smarty compile pass (see the constructor's
     * own setPrefilter() call below), Smarty always invokes this with the
     * currently-compiling Smarty\Template, not the top-level Smarty\Smarty
     * engine (a bare `Smarty $smarty` closure param there throws a real
     * TypeError). This method's own $smarty param is typed to
     * their shared common ancestor instead of the narrower Smarty\Template,
     * since it only ever calls getTemplateVars() (declared on that shared
     * base), and this file's own Unit tests call it directly against
     * $this->smarty (the Smarty\Smarty engine) rather than a real compiling
     * Smarty\Template, a legitimate substitution given both share the same
     * variable-storage API.
     */
    public static function prefilterLocalCss(string $source, TemplateBase $smarty, Paths $paths): string
    {
        // The relative directory name (e.g. 'local/' or a PIWIGO_LOCAL_DIR
        // override) -- combine_css's own path= attribute needs a
        // root-relative string, same shape the retired PWG_LOCAL_DIR
        // constant already was, so the absolute Paths::$siteLocal has its
        // $paths->root prefix stripped back off here.
        $siteLocalDir = substr($paths->siteLocal, strlen($paths->root));

        $css = [];
        $themes = $smarty->getTemplateVars('themes');
        if (is_array($themes)) {
            foreach ($themes as $theme) {
                if (! is_array($theme) || ! isset($theme['id']) || ! is_string($theme['id'])) {
                    continue;
                }
                $f = $siteLocalDir . 'css/' . $theme['id'] . '-rules.css';
                if (file_exists($paths->root . $f)) {
                    $css[] = "{combine_css path='{$f}' order=10}";
                }
            }
        }
        $f = $siteLocalDir . 'css/rules.css';
        if (file_exists($paths->root . $f)) {
            $css[] = "{combine_css path='{$f}' order=10}";
        }

        if ($css !== []) {
            $source = str_replace('{get_combined_css}', implode("\n", $css) . "\n{get_combined_css}", $source);
        }

        return $source;
    }

    /**
     * Loads the configuration file from a theme directory and returns it.
     *
     * @return array<string, mixed>
     */
    public function loadThemeconf(string $dir): array
    {
        $real_dir = realpath($dir);
        if ($real_dir === false) {
            // Theme directory doesn't actually exist on disk -- don't cache
            // under a coerced-to-0 array key (every broken $dir would
            // collide on the same cache slot) or attempt to include a
            // bogus root-relative path.
            return [];
        }
        $dir = $real_dir;
        $cache_key = 'themeconf:' . $dir;
        if (! $this->processCache->has($cache_key)) {
            $themeconf = [];
            // themeconf.inc.php may set this to push extra template
            // variables, instead of reaching for $this/$template directly
            // (this file is included from many distinct Template instances,
            // not only the global $template). assign() on an empty array is
            // a no-op, so no need to guard the common case where it's unset.
            $theme_template_vars = [];
            include $dir . '/themeconf.inc.php';
            $this->smarty->assign($theme_template_vars);
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
     * Registers a button to be displayed on picture page.
     */
    public function addPictureButton(string $content, int $rank = 50): void
    {
        $this->picture_buttons[$rank][] = $content;
    }

    /**
     * Registers a button to be displayed on index pages.
     */
    public function addIndexButton(string $content, int $rank = 50): void
    {
        $this->index_buttons[$rank][] = $content;
    }

    /**
     * Assigns PLUGIN_PICTURE_BUTTONS template variable with registered picture buttons.
     */
    public function parsePictureButtons(): void
    {
        if ($this->picture_buttons !== []) {
            ksort($this->picture_buttons);
            $buttons = [];
            foreach ($this->picture_buttons as $k => $row) {
                $buttons = array_merge($buttons, $row);
            }
            $this->smarty->assign('PLUGIN_PICTURE_BUTTONS', $buttons);

            // only for PHP 5.3
            // $this->assign('PLUGIN_PICTURE_BUTTONS',
            // array_reduce(
            // $this->picture_buttons,
            // create_function('$v,$w', 'return array_merge($v, $w);'),
            // array()
            // ));
        }
    }

    /**
     * Assigns PLUGIN_INDEX_BUTTONS template variable with registered index buttons.
     */
    public function parseIndexButtons(): void
    {
        if ($this->index_buttons !== []) {
            ksort($this->index_buttons);
            $buttons = [];
            foreach ($this->index_buttons as $k => $row) {
                $buttons = array_merge($buttons, $row);
            }
            $this->smarty->assign('PLUGIN_INDEX_BUTTONS', $buttons);

            // only for PHP 5.3
            // $this->assign('PLUGIN_INDEX_BUTTONS',
            // array_reduce(
            // $this->index_buttons,
            // create_function('$v,$w', 'return array_merge($v, $w);'),
            // array()
            // ));
        }
    }
}
