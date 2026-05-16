<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Latte\Runtime\Html;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Event\Template\CombinedCss;
use Piwigo\Event\Template\CombinedScript;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\LangService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Psr\EventDispatcher\EventDispatcherInterface;

/** default rank for buttons */
define('BUTTONS_RANK_NEUTRAL', 50);

/**
 * Page-rendering coordinator: holds the per-request output buffer, the
 * JS/CSS asset registries, the registered template-variable bag, and
 * the theme search path. Renders `.latte` templates through
 * {@see LatteEngine::default()}.
 *
 * After Phase F.1, the engine surface is direct: `parse($file)` /
 * `pparse($file)` / `assignVarFromTemplate($var, $file)` take the
 * `.latte` path directly — the prior handle indirection
 * (`setFilename` + `parse(handle)`) was an inheritance from the Smarty
 * era and added no value over the path.
 */
final class Template
{
    /** @var string */
    public $output = '';

    /** @var array<string, mixed> - Assigned template variables. */
    private array $vars = [];

    /** @var list<string> - Template directories searched when resolving bare filenames. */
    private array $template_dirs = [];

    /** @var string[] - Content to add before </head> tag */
    public array $html_head_elements = [];

    /** @const string */
    public const string COMBINED_SCRIPTS_TAG = '<!-- COMBINED_SCRIPTS -->';
    /** @var ScriptLoader */
    public $scriptLoader;

    /** @const string */
    public const string COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';
    /** @var CssLoader */
    public $cssLoader;

    /** @var array<int, list<mixed>> - Runtime buttons on picture page */
    public array $picture_buttons = [];
    /** @var array<int, list<mixed>> - Runtime buttons on index page */
    public array $index_buttons = [];

    /** @var array<string, array<mixed>> - per-directory themeconf cache */
    private array $themeconfs = [];

    /**
     * @param string $theme
     */
    public function __construct(string $root = '.', $theme = '', string $path = 'template')
    {
        $lang_info = Lang::langInfo();

        $this->scriptLoader = new ScriptLoader();
        $this->cssLoader = new CssLoader();

        if (!Config::has('data_dir_checked')) {
            $dir = PHPWG_ROOT_PATH.Config::dataLocation();
            Filesystem::mkgetdir($dir, Filesystem::FLAG_DEFAULT & ~Filesystem::FLAG_DIE_ON_ERROR);
            if (!is_writable($dir)) {
                Kernel::service(LangService::class)->loadLanguage('admin.lang');
                HtmlService::fatalError(
                    Lang::t(
                        'Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation',
                        Config::dataLocation()
                    ),
                    Lang::t('an error happened'),
                    false // show trace
                );
            }
            if (Config::dbName() !== '') {
                Kernel::service(ConfigService::class)->confUpdateParam('data_dir_checked', 1);
            }
        }

        $compile_dir = PHPWG_ROOT_PATH.Config::dataLocation().'templates_c';
        Filesystem::mkgetdir($compile_dir);

        if (!empty($theme)) {
            $this->setTheme($root, $theme, $path);
        } else {
            $this->setTemplateDir($root);
        }

        $this->assign('lang_info', $lang_info);
    }

    /**
     * Loads theme's parameters.
     */
    public function setTheme(string $root, string $theme, string $path, bool $load_css = true, bool $load_local_head = true, string $colorscheme = 'dark'): void
    {
        //we need themeconf before std_pgs to see what themes use_standard_pages
        $themeconf = $this->loadThemeconf($root.'/'.$theme);

        // We loop over the theme and the parent theme, so if we exclude default,
        // standard pages can't get the header to load the html header
        if (
            '_base' != $theme
            and in_array(StringUtil::scriptBasename(), ['identification', 'register', 'password', 'profile'])
            and (((bool) ($themeconf['use_standard_pages'] ?? false)) or Kernel::service(ConfigService::class)->confGetParam('use_standard_pages', false))
        ) {
            $theme = 'standard_pages';
            $themeconf = $this->loadThemeconf($root.'/'.$theme);
        }

        $this->setTemplateDir($root.'/'.$theme.'/'.$path);

        // Standard-pages active context — replaces the side-effect PHP
        // in themes/standard_pages/themeconf.inc.php. Idempotent with the
        // legacy include during the B14 transition: both assign the same
        // STD_PGS_* template vars to the same values. Once B14c lands
        // and the legacy file is gone, this is the sole source.
        if ($theme === 'standard_pages') {
            $this->applyStandardPagesContext();
        }

        if (isset($themeconf['parent']) and $themeconf['parent'] != $theme) {
            $parentTheme = is_string($themeconf['parent']) ? $themeconf['parent'] : '';
            $parentLoadCss = isset($themeconf['load_parent_css']) ? (bool) $themeconf['load_parent_css'] : $load_css;
            $parentLoadHead = isset($themeconf['load_parent_local_head']) ? (bool) $themeconf['load_parent_local_head'] : $load_local_head;
            $this->setTheme(
                $root,
                $parentTheme,
                $path,
                $parentLoadCss,
                $parentLoadHead
            );
        }

        $tpl_var = [
          'id' => $theme,
          'load_css' => $load_css,
        ];
        if (!empty($themeconf['local_head']) and $load_local_head) {
            $localHead = is_string($themeconf['local_head']) ? $themeconf['local_head'] : '';
            $tpl_var['local_head'] = realpath($root.'/'.$theme.'/'.$localHead);
        }
        $themeconf['id'] = $theme;

        if (!isset($themeconf['colorscheme'])) {
            $themeconf['colorscheme'] = $colorscheme;
        }

        $this->append('themes', $tpl_var);
        $this->append('themeconf', $themeconf, true);
    }

    /**
     * Adds a template directory to the resolution stack.
     */
    public function setTemplateDir(string $dir): void
    {
        $this->template_dirs[] = $dir;
    }

    /**
     * Returns the template-resolution stack.
     *
     * @return array<string>|string
     */
    public function getTemplateDir(): string|array
    {
        return count($this->template_dirs) === 1
            ? $this->template_dirs[0]
            : $this->template_dirs;
    }

    /**
     * Whether `$file` resolves against any of the registered template
     * directories. Direct replacement for the legacy
     * `$tpl->smarty->templateExists()` check used by mail rendering.
     */
    public function templateExists(string $file): bool
    {
        if (file_exists($file)) {
            return true;
        }
        return array_any($this->template_dirs, fn ($dir): bool => file_exists(rtrim($dir, '/') . '/' . $file));
    }

    /**
     * Recursively clears `_data/templates_c/`. Used by admin maintenance
     * actions that want to force the compile cache to repopulate.
     */
    public function deleteCompiledTemplates(): void
    {
        $compile_dir = PHPWG_ROOT_PATH.Config::dataLocation().'templates_c';
        self::rrmdirContents($compile_dir);
        Filesystem::mkgetdir($compile_dir);
        file_put_contents($compile_dir.'/index.htm', 'Not allowed!');
    }

    private static function rrmdirContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdirContents($path);
                Filesystem::tryRmdir($path);
            } else {
                Filesystem::tryUnlink($path);
            }
        }
    }

    /**
     * Returns theme's parameter.
     */
    public function getThemeconf(string $val): mixed
    {
        $tc = $this->vars['themeconf'] ?? null;
        return is_array($tc) ? ($tc[$val] ?? '') : '';
    }

    /**
     * Assigns a template variable.
     *
     * @param string|array<string,mixed> $tpl_var can be a var name or a hashmap of variables
     *    (in this case, do not use the _$value_ parameter)
     */
    public function assign(string|array $tpl_var, mixed $value = null): void
    {
        if (is_array($tpl_var)) {
            foreach ($tpl_var as $name => $v) {
                $this->vars[$name] = $v;
            }
            return;
        }
        $this->vars[$tpl_var] = $value;
    }

    /**
     * Renders `$file` and assigns the result to `$varname` as
     * `Latte\Runtime\Html` so it propagates through Latte's auto-escape
     * unmolested at every `{$VAR}` print site downstream.
     */
    public function assignVarFromTemplate(string $varname, string $file): void
    {
        $rendered = $this->parse($file, true);
        $this->assign($varname, new Html((string) $rendered));
    }

    /**
     * Appends a value to a template array variable, creating it if needed.
     *
     * - `append('foo', $value)` — pushes `$value` as a new entry.
     * - `append('foo', $hash, true)` — merges hash entries (preserves keys).
     */
    public function append(string $tpl_var, mixed $value = null, bool $merge = false): void
    {
        if (!isset($this->vars[$tpl_var]) || !is_array($this->vars[$tpl_var])) {
            $this->vars[$tpl_var] = [];
        }
        /** @var array<int|string, mixed> $bucket */
        $bucket = $this->vars[$tpl_var];
        if ($merge && is_array($value)) {
            foreach ($value as $k => $v) {
                $bucket[$k] = $v;
            }
        } else {
            $bucket[] = $value;
        }
        $this->vars[$tpl_var] = $bucket;
    }

    /**
     * Performs a string concatenation onto an existing string variable.
     */
    public function concat(string $tpl_var, string $value): void
    {
        $existing = $this->vars[$tpl_var] ?? '';
        $existingStr = is_string($existing) ? $existing : '';
        $this->vars[$tpl_var] = $existingStr . $value;
    }

    /**
     * Removes an assigned template variable.
     */
    public function clearAssign(string $tpl_var): void
    {
        unset($this->vars[$tpl_var]);
    }

    /**
     * Returns an assigned template variable.
     *
     * @return array<mixed>|mixed
     */
    public function getTemplateVars(string $tpl_var): mixed
    {
        return $this->vars[$tpl_var] ?? null;
    }

    /**
     * Renders `$file` (a bare `.latte` filename resolved against the
     * registered template directories, or an absolute path) and either
     * appends the result to the output buffer or returns it.
     */
    public function parse(string $file, bool $return = false): ?string
    {
        $this->assign('ROOT_URL', UrlService::getRootUrl());
        $wsBase = Kernel::service(UrlGenerator::class)->ws();
        $this->assign('WS_URL', $wsBase . (str_contains($wsBase, '?') ? '&' : '?'));
        $this->assign('U_SEARCH', Kernel::service(UrlGenerator::class)->searchPage());

        $v = $this->renderLatte($file);

        if ($return) {
            return $v;
        }
        $this->output .= $v;
        return null;
    }

    /**
     * Renders a `.latte` file via {@see LatteEngine::default()}, passing
     * the assigned-vars bag through as the parameter array.
     */
    private function renderLatte(string $file): string
    {
        $absPath = $this->resolveLatteTemplatePath($file);
        return LatteEngine::default()->render($absPath, $this->vars);
    }

    /**
     * Bare filenames (`help.latte`) are resolved against the registered
     * template directories; absolute paths pass through unchanged. Latte
     * expects an absolute (or cwd-relative) path so the dispatcher walks
     * the registered dirs to find the first hit.
     */
    private function resolveLatteTemplatePath(string $file): string
    {
        if (file_exists($file)) {
            return $file;
        }
        foreach ($this->template_dirs as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $file;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        HtmlService::fatalError("Template->parse(): Latte file not found in template_dir: $file");
    }

    /**
     * Renders `$file` and immediately flushes accumulated output to the
     * browser.
     */
    public function pparse(string $file): void
    {
        $this->parse($file, false);
        $this->flush();
    }

    /**
     * Splices accumulated JS/CSS into the placeholder tags then echoes
     * and resets the output buffer.
     */
    public function flush(): void
    {
        if (!$this->scriptLoader->didHead()) {
            $pos = strpos($this->output, self::COMBINED_SCRIPTS_TAG);
            if ($pos !== false) {
                $scripts = $this->scriptLoader->getHeadScripts();
                $content = [];
                foreach ($scripts as $script) {
                    $src = self::makeScriptSrc($script);
                    $type = self::isModuleScript($script) ? 'module' : 'text/javascript';
                    $content[] =
                        '<script type="' . $type . '" src="'
                        . (is_string($src) ? $src : '')
                        .'"></script>';
                }

                $this->output = substr_replace($this->output, implode("\n", $content), $pos, strlen(self::COMBINED_SCRIPTS_TAG));
            }
        }

        $css = $this->cssLoader->getCss();

        $content = [];
        foreach ($css as $combi) {
            $href = UrlService::embellishUrl(UrlService::getRootUrl().$combi->path);
            if (!is_string($href)) {
                $href = UrlService::getRootUrl().$combi->path;
            }
            if (!str_starts_with($combi->path, 'dist/')) {
                $href .= '?v' . ($combi->version ?: AppInfo::VERSION);
            }
            // trigger the event for eventual use of a cdn
            $cssEvent = new CombinedCss($href, $combi);
            Kernel::service(EventDispatcherInterface::class)->dispatch($cssEvent);
            $href = $cssEvent->href;
            $content[] = '<link rel="stylesheet" type="text/css" href="'.$href.'">';
        }
        $this->output = str_replace(
            self::COMBINED_CSS_TAG,
            implode("\n", $content),
            $this->output
        );
        $this->cssLoader->clear();

        if (count($this->html_head_elements) > 0) {
            $search = "\n</head>";
            $pos = strpos($this->output, $search);
            if ($pos !== false) {
                $rep = "\n".implode("\n", $this->html_head_elements);
                $this->output = substr_replace($this->output, $rep, $pos, 0);
            }
            $this->html_head_elements = [];
        }

        echo $this->output;
        $this->output = '';
    }

    /**
     * @return string|array<mixed>
     */
    private static function makeScriptSrc(Combinable $script): string|array
    {
        $ret = '';
        if ($script->isRemote()) {
            $ret = $script->path;
        } else {
            $ret = UrlService::getRootUrl().$script->path;
            // Vite manifest filenames already carry a content hash; keep
            // ?v= only for legacy/plugin-supplied paths.
            if (!str_starts_with($script->path, 'dist/')) {
                $ret .= '?v'. ($script->version ?: AppInfo::VERSION);
            }
        }
        // trigger the event for eventual use of a cdn
        $scriptEvent = new CombinedScript($ret, $script);
        Kernel::service(EventDispatcherInterface::class)->dispatch($scriptEvent);
        $ret = $scriptEvent->ret;
        return UrlService::embellishUrl($ret);
    }

    private static function isModuleScript(Combinable $script): bool
    {
        return str_starts_with($script->path, 'dist/');
    }

    /**
     * Load the theme manifest from a theme directory and project it
     * into the legacy themeconf array shape consumers expect.
     *
     * @return array<mixed>
     */
    public function loadThemeconf(string $dir): array
    {
        $realpathDir = realpath($dir);
        $dir = $realpathDir !== false ? $realpathDir : $dir;
        if (!isset($this->themeconfs[$dir])) {
            $jsonPath = $dir . '/theme.json';
            $this->themeconfs[$dir] = is_file($jsonPath)
                ? $this->themeconfFromJson($jsonPath)
                : [];
        }
        return $this->themeconfs[$dir];
    }

    /**
     * Project a theme.json manifest into the flat `themeconf` shape
     * legacy callers (this class's setTheme + 19 Latte sites + 10
     * controllers + SrcImage) expect. Keys emitted:
     *
     *   name, parent, load_parent_css, icon_dir, img_dir, mime_icon_dir,
     *   admin_icon_dir, local_head, colorscheme, use_standard_pages
     *
     * `id` is intentionally omitted — Template::setTheme overwrites it
     * to the directory basename on line 165 regardless of source.
     *
     * @return array<string, mixed>
     */
    private function themeconfFromJson(string $jsonPath): array
    {
        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach (['name', 'parent', 'localHead', 'colorscheme'] as $directKey) {
            $val = $decoded[$directKey] ?? null;
            if (is_string($val)) {
                // legacy localHead → local_head, others 1:1
                $key = $directKey === 'localHead' ? 'local_head' : $directKey;
                $out[$key] = $val;
            }
        }
        if (isset($decoded['loadParentCss']) && is_bool($decoded['loadParentCss'])) {
            $out['load_parent_css'] = $decoded['loadParentCss'];
        }
        if (isset($decoded['useStandardPages']) && is_bool($decoded['useStandardPages'])) {
            $out['use_standard_pages'] = $decoded['useStandardPages'];
        }
        $assets = $decoded['assets'] ?? null;
        if (is_array($assets)) {
            // assets map → flat *_dir fields the templates read
            $kindToLegacy = [
                'icon'      => 'icon_dir',
                'img'       => 'img_dir',
                'mimeIcon'  => 'mime_icon_dir',
                'adminIcon' => 'admin_icon_dir',
            ];
            foreach ($kindToLegacy as $kind => $legacy) {
                $val = $assets[$kind] ?? null;
                if (is_string($val) && $val !== '') {
                    $out[$legacy] = $val;
                }
            }
        }
        return $out;
    }

    /**
     * Standard_pages active context — used to live as side-effect PHP
     * inside `themes/standard_pages/themeconf.inc.php`. Assigns four
     * template variables consumed by every standard_pages Latte file
     * (identification, register, password, profile, header).
     *
     * Called from setTheme() when the resolved theme is
     * 'standard_pages'. Currently a no-op fallback when the bundled
     * themeconf.inc.php still exists — once B14c lands, this is the
     * sole source for the assigns.
     *
     * `$page['gallery_title']` from the legacy code was effectively
     * dead — that global was never in scope inside the include — so
     * GALLERY_TITLE always falls back to Config::galleryTitle().
     */
    private function applyStandardPagesContext(): void
    {
        // Use ConfigService::confGetParam() rather than Config::raw() so
        // PHPStan's ConfigKeyExistsRule doesn't flag the three theme-pref
        // keys (the rule only inspects Config::raw static-call sites).
        // ExtensionsController writes these via the same service.
        $configService = Kernel::service(ConfigService::class);
        $logoRaw = $configService->confGetParam('standard_pages_selected_logo', 'piwigo_logo');
        $skinRaw = $configService->confGetParam('standard_pages_selected_skin', 'default');
        $logo = is_string($logoRaw) ? $logoRaw : 'piwigo_logo';
        $skin = is_string($skinRaw) ? $skinRaw : 'default';

        $this->assign([
            'STD_PGS_SELECTED_SKIN' => $skin,
            'STD_PGS_SELECTED_LOGO' => $logo,
            'GALLERY_TITLE'         => Config::galleryTitle(),
        ]);
        if ($logo === 'custom_logo') {
            $customPath = $configService->confGetParam('standard_pages_selected_logo_path', '');
            $this->assign([
                'STD_PGS_SELECTED_LOGO_PATH' => is_string($customPath) ? $customPath : '',
            ]);
        }
    }

    /**
     * Registers a button to be displayed on picture page.
     */
    public function addPictureButton(mixed $content, int $rank = BUTTONS_RANK_NEUTRAL): void
    {
        $this->picture_buttons[$rank][] = $content;
    }

    /**
     * Registers a button to be displayed on index pages.
     */
    public function addIndexButton(mixed $content, int $rank = BUTTONS_RANK_NEUTRAL): void
    {
        $this->index_buttons[$rank][] = $content;
    }

    /**
     * Assigns PLUGIN_PICTURE_BUTTONS template variable with registered picture buttons.
     */
    public function parsePictureButtons(): void
    {
        if (!empty($this->picture_buttons)) {
            ksort($this->picture_buttons);
            $buttons = [];
            foreach ($this->picture_buttons as $row) {
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
        if (!empty($this->index_buttons)) {
            ksort($this->index_buttons);
            $buttons = [];
            foreach ($this->index_buttons as $row) {
                $buttons = array_merge($buttons, $row);
            }
            $this->assign('PLUGIN_INDEX_BUTTONS', $buttons);
        }
    }
}
