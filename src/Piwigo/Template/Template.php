<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Latte\Runtime\Html;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\LangService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;

/**
 * Page-rendering coordinator: holds the per-request output buffer, the
 * registered template-variable bag, and the theme search path. Renders
 * `.latte` templates through {@see LatteEngine::default()}.
 *
 * After Phase F.1, the engine surface is direct: `parse($file)` /
 * `pparse($file)` / `assignVarFromTemplate($var, $file)` take the
 * `.latte` path directly — the prior handle indirection
 * (`setFilename` + `parse(handle)`) was an inheritance from the Smarty
 * era and added no value over the path.
 */
final class Template
{
    /** Default rank for plugin-registered picture / index buttons. */
    public const int BUTTONS_RANK_NEUTRAL = 50;

    /** @var string */
    public $output = '';

    /** @var array<string, mixed> - Assigned template variables. */
    private array $vars = [];

    /** @var list<string> - Template directories searched when resolving bare filenames. */
    private array $template_dirs = [];

    /** @var string[] - Content to add before </head> tag */
    public array $html_head_elements = [];

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

        if (!Config::has('data_dir_checked')) {
            $dir = Kernel::service(Paths::class)->root . Config::dataLocation();
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

        $compile_dir = Kernel::service(Paths::class)->root . Config::dataLocation() . 'templates_c';
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
            and (((bool) ($themeconf['use_standard_pages'] ?? false)) or Kernel::service(ConfigService::class)->useStandardPages())
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
        $compile_dir = Kernel::service(Paths::class)->root . Config::dataLocation() . 'templates_c';
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
     *
     * @param ((\Piwigo\Image\DerivativeImage|array|bool|int|mixed|null)[]|(int|string)|Html|\Piwigo\Image\DerivativeImage|\Piwigo\Image\SrcImage|\Piwigo\Menu\RegisteredBlock|bool|float|mixed|null|numeric)[]|Html|null|string $value
     *
     * @psalm-param Html|array{items?: array|mixed, previous?: array{LABEL: string, URL: string}|mixed, next?: array{LABEL: string, URL: string}|mixed, NAME?: mixed|string, TYPE?: mixed|string, CATEGORIES?: int|mixed, IMAGES?: int|mixed, U_SYNCHRONIZE?: mixed|string, U_DELETE?: mixed|string, plugin_links?: array|mixed, ELEMENT?: mixed|string, LABEL?: mixed|string, ID?: int|mixed|numeric|string, EXT_NAME?: mixed|string, EXT_URL?: mixed|string, SMALL_DESC?: mixed|string, BIG_DESC?: mixed|string, VERSION?: mixed|string, REVISION_DATE?: mixed|null|string, REVISION_FORMATED_DATE?: mixed|string, AUTHOR?: mixed|string, DOWNLOADS?: mixed|null, URL_INSTALL?: mixed|string, CERTIFICATION?: int|mixed, RATING?: mixed|null, NB_RATINGS?: mixed|null, SCREENSHOT?: ''|mixed, TAGS?: list<array<string, mixed>>|mixed, original_resize_quality?: mixed|string, original_resize_maxheight?: mixed|string, original_resize_maxwidth?: mixed|string, original_resize?: bool|mixed|string, name?: mixed, thumbnail?: ''|mixed, screenshot?: ''|mixed, install_url?: mixed|string, EXT_DESC?: mixed|string, VER_DESC?: mixed|string, DATE?: mixed|string, URL_DOWNLOAD?: mixed|string, replacer?: array-key|mixed, url_parameter?: list{'----------', 'category', 'favorites', 'most_visited', 'best_rated', 'recent_pics', 'recent_cats', 'created-monthly-calendar', 'posted-monthly-calendar', 'search', 'flat', 'list', 'tags',...}|mixed, original_tpl?: mixed|non-empty-list<'----------'|'about.tpl'|'comment_list.tpl'|'comments.tpl'|'footer.tpl'|'header.tpl'|'identification.tpl'|'index.tpl'|'mainpage_categories.tpl'|'menubar.tpl'|'menubar_categories.tpl'|'menubar_identification.tpl'|'menubar_links.tpl'|'menubar_menu.tpl'|'menubar_specials.tpl'|'menubar_tags.tpl'|'month_calendar.tpl'|'navigation_bar.tpl'|'nbm.tpl'|'notification.tpl'|'password.tpl'|'picture.tpl'|'picture_content.tpl'|'picture_nav_buttons.tpl'|'popuphelp.tpl'|'profile.tpl'|'profile_content.tpl'|'redirect.tpl'|'register.tpl'|'search.tpl'|'slideshow.tpl'|'tags.tpl'|'thumbnails.tpl'>, bound_tpl?: array{'N/A': string,...}|mixed, selected_tpl?: mixed|string, selected_url?: mixed|string, selected_bound?: mixed|string, tags?: list{0?: array{URL: string,...},...}|mixed, TITLE?: Html|mixed|null|string, CHANGE_COLUMN?: mixed|true, URL?: mixed|string, U_DOWNLOAD?: mixed|string, formats?: list{0: array{download_url: string, ext: string, filesize: int|null}, 1?: array<string, mixed>,...}|mixed, U_TAG_IMAGE?: mixed|string, selected_derivative?: mixed|null, unique_derivatives?: array<\Piwigo\Image\DerivativeImage>|mixed, show_correction_fct?: mixed, can_select?: mixed, NB_PHOTOS?: int|mixed, NB_SUB_PHOTOS?: int<min, max>|mixed, NB_SUB_ALBUMS?: int<0, max>|mixed, RANK?: int|mixed, U_JUMPTO?: mixed|null|string, U_CHILDREN?: mixed|string, U_EDIT?: mixed|string, U_ADD_PHOTOS_ALBUM?: mixed|string, U_MOVE?: mixed|string, IS_VIRTUAL?: bool|mixed, CAT_ADMIN_ACCESS?: bool|mixed, U_SYNC?: mixed|string, group_name?: mixed|string, group_users?: mixed|string, TN_SRC?: array|mixed|string, SIZE?: array<int>|mixed|null, lines?: array|mixed, U_PICTURE?: mixed|string, src_image?: \Piwigo\Image\SrcImage|mixed, ALT?: mixed|string, WEBSITE_URL?: mixed|null|string, CONTENT?: Html|mixed|null|string, EMAIL?: mixed|null|string, IN_EDIT?: mixed|true, KEY?: mixed|string, IMAGE_ID?: int|mixed, PWG_TOKEN?: mixed|string, U_CANCEL?: mixed|string, U_VALIDATE?: mixed|string, HTML_DATA?: mixed|string, VALUE?: mixed|string, SELECTED?: bool|mixed, thumb?: \Piwigo\Image\DerivativeImage|mixed, FILE_SRC?: array|mixed|string, LEGEND?: mixed|string, LEVEL?: '0'|mixed, DESCRIPTION?: mixed|string, DATE_CREATION?: mixed, is_svg?: bool|mixed, DIMENSIONS?: mixed|string, FORMAT?: 0|1|mixed, FILESIZE?: mixed|string, REGISTRATION_DATE?: mixed|string, EXT?: mixed|string, POST_DATE?: mixed|string, AGE?: mixed|string, ADDED_BY?: mixed|string, STATS?: mixed|string, FILE?: mixed|string, related_categories?: array<int, array{name: string, unlinkable: bool}>|mixed, related_category_ids?: false|mixed|string, tag_selection?: list<array<string, mixed>>|mixed, U_HISTORY?: mixed|string, U_ACTIVITY?: mixed|string, PATH?: mixed, level_options_selected?: list{mixed|null}|mixed, allow_user_registration?: bool|mixed, obligatory_user_mail_address?: bool|mixed, rate?: bool|mixed, rate_anonymous?: bool|mixed, allow_user_customization?: bool|mixed, log?: bool|mixed, history_admin?: bool|mixed, history_guest?: bool|mixed, show_mobile_app_banner_in_gallery?: bool|mixed, show_mobile_app_banner_in_admin?: bool|mixed, upload_detect_duplicate?: bool|mixed, activate_comments?: bool|mixed, comments_forall?: bool|mixed, comments_validation?: bool|mixed, email_admin_on_comment?: mixed|string, email_admin_on_comment_validation?: mixed|string, user_can_delete_comment?: bool|mixed, user_can_edit_comment?: bool|mixed, email_admin_on_comment_edition?: mixed|string, email_admin_on_comment_deletion?: mixed|string, comments_author_mandatory?: bool|mixed, comments_email_mandatory?: bool|mixed, comments_enable_website?: bool|mixed, menubar_filter_icon?: bool|mixed, index_search_in_set_button?: bool|mixed, index_search_in_set_action?: mixed|string, index_sort_order_input?: mixed|string, index_flat_icon?: bool|mixed, index_posted_date_icon?: bool|mixed, index_created_date_icon?: bool|mixed, index_slideshow_icon?: bool|mixed, index_sizes_icon?: bool|mixed, index_new_icon?: bool|mixed, index_edit_icon?: bool|mixed, index_caddie_icon?: bool|mixed, display_fromto?: bool|mixed, picture_metadata_icon?: bool|mixed, picture_slideshow_icon?: bool|mixed, picture_favorite_icon?: bool|mixed, picture_sizes_icon?: bool|mixed, picture_download_icon?: bool|mixed, picture_edit_icon?: bool|mixed, picture_caddie_icon?: bool|mixed, picture_representative_icon?: bool|mixed, picture_navigation_icons?: bool|mixed, picture_navigation_thumb?: bool|mixed, picture_menu?: bool|mixed, picture_informations?: array<string, bool>|mixed, NB_CATEGORIES_PAGE?: int|mixed, U_IMG?: mixed|string, HTM_SIZE?: mixed|string, DISPLAY?: mixed|string, IS_DEFAULT?: mixed|string, NB_MEMBERS?: int<0, max>|mixed, L_MEMBERS?: mixed|string, MEMBERS?: mixed|string, U_PERM?: mixed|string, U_USERS?: mixed|string, U_ISDEFAULT?: mixed|string, pos?: float|int|mixed, reg?: \Piwigo\Menu\RegisteredBlock|mixed, id?: mixed|numeric|string, U_THUMB?: array|mixed|string, U_URL?: mixed|string, SCORE_RATE?: mixed, AVG_RATE?: mixed, SUM_RATE?: mixed, NB_RATES?: int|mixed, NB_RATES_TOTAL?: int<0, max>|mixed, rates?: list{0?: array{USER: string,...},...}|mixed, load_css?: bool|mixed, local_head?: false|mixed|string, colorscheme?: mixed|string,...}|null|string $value
     */
    public function append(string $tpl_var, array|string|Html|null $value = null, bool $merge = false): void
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
        // WS_URL/U_SEARCH come from UrlGenerator → UrlService → Connection;
        // pre-install (no db_base set) the DB credentials don't exist yet,
        // so skip these assigns. install.latte doesn't reference either var.
        if (Config::dbName() !== '') {
            $wsBase = Kernel::service(UrlGenerator::class)->ws();
            $this->assign('WS_URL', $wsBase . (str_contains($wsBase, '?') ? '&' : '?'));
            $this->assign('U_SEARCH', Kernel::service(UrlGenerator::class)->searchPage());
        }

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
        // ConfigService exposes typed accessors for the standard_pages
        // plugin-persisted keys (ExtensionsController writes them via
        // the same service).
        $configService = Kernel::service(ConfigService::class);
        $logo = $configService->standardPagesSelectedLogo();
        $skin = $configService->standardPagesSelectedSkin();

        $this->assign([
            'STD_PGS_SELECTED_SKIN' => $skin,
            'STD_PGS_SELECTED_LOGO' => $logo,
            'GALLERY_TITLE'         => Config::galleryTitle(),
        ]);
        if ($logo === 'custom_logo') {
            $this->assign([
                'STD_PGS_SELECTED_LOGO_PATH' => $configService->standardPagesSelectedLogoPath(),
            ]);
        }
    }

    /**
     * Registers a button to be displayed on picture page.
     */
    public function addPictureButton(mixed $content, int $rank = self::BUTTONS_RANK_NEUTRAL): void
    {
        $this->picture_buttons[$rank][] = $content;
    }

    /**
     * Registers a button to be displayed on index pages.
     */
    public function addIndexButton(mixed $content, int $rank = self::BUTTONS_RANK_NEUTRAL): void
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
