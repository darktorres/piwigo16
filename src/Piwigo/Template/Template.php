<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AdminContext;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\DeviceHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Url\UrlService;
use Smarty\Smarty;

/**
 * Legacy Coupling Retirement Phase 8, 8d: the data_dir_checked write
 * inside __construct() goes through CurrentConfigService::get() (Tier 2)
 * -- safe even though the write fires from the constructor itself, not a
 * method called later, because every real construction site
 * (Bootstrap\RequestBootstrap.php x2, Admin\Install\InstallWizard.php) now
 * runs after its own path has already activated CurrentConfigService
 * (RequestBootstrap::connect() resolves one before finalize() ever
 * constructs a Template; InstallWizard is only ever constructed after
 * InstallBootstrap::activateConfigService()).
 */
final class Template implements \Piwigo\Core\ThemeConfProviderInterface, \Piwigo\Core\TemplateInterface
{
    /**
     * @var Smarty
     */
    public $smarty;

    /**
     * @var string
     */
    public $output = '';

    /**
     * @var string[] - Hash of filenames for each template handle.
     */
    public $files = [];

    /**
     * @var string[] - Template extents filenames for each template handle.
     */
    public $extents = [];

    /**
     * @var array<string, array<int, array<int, array{0: string, 1: callable}>>> - Templates prefilter from external sources (plugins)
     */
    public $external_filters = [];

    /**
     * @var string[] - Content to add before </head> tag
     */
    public $html_head_elements = [];

    /**
     * @var string - Runtime CSS rules
     */
    private string $html_style = '';

    public const string COMBINED_SCRIPTS_TAG = '<!-- COMBINED_SCRIPTS -->';

    /**
     * @var ScriptLoader
     */
    public $scriptLoader;

    public const string COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';

    /**
     * @var CssLoader
     */
    public $cssLoader;

    /**
     * @var array<int, string[]> - Runtime buttons on picture page
     */
    public $picture_buttons = [];

    /**
     * @var array<int, string[]> - Runtime buttons on index page
     */
    public $index_buttons = [];

    public function __construct(
        string $root = '.',
        string $theme = '',
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
        $this->smarty->debugging = \Piwigo\Config\CurrentConfig::debugTemplate();
        if (! $this->smarty->debugging) {
            $this->smarty->error_reporting = error_reporting() & ~E_NOTICE;
        }
        // compile_check/force_compile mirror Smarty's own setCompileCheck()/
        // setForceCompile() coercions (vendor/smarty/smarty/src/TemplateBase.php,
        // vendor/smarty/smarty/src/Smarty.php), whose own @var docblocks
        // (int / boolean respectively) don't carry the same bool|int
        // flexibility as $debugging above.
        $compile_check = \Piwigo\Config\CurrentConfig::templateCompileCheck();
        $this->smarty->compile_check = (int) $compile_check;
        $this->smarty->force_compile = \Piwigo\Config\CurrentConfig::templateForceCompile();

        $conf_data_location = \Piwigo\Config\CurrentConfig::dataLocation();

        if (\Piwigo\Config\CurrentConfig::dataDirChecked() === null) {
            $dir = CurrentPaths::get()->root . $conf_data_location;
            \Piwigo\Core\FilesystemHelper::mkgetdir($dir, \Piwigo\Core\FilesystemHelper::MKGETDIR_DEFAULT & ~\Piwigo\Core\FilesystemHelper::MKGETDIR_DIE_ON_ERROR);
            if (! is_writable($dir)) {
                Lang::load('admin.lang');
                new HtmlService()
                    ->fatalError(
                        Lang::t(
                            'Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation',
                            $conf_data_location
                        ),
                        Lang::t('an error happened'),
                        false // show trace
                    );
            }
            // Legacy Coupling Retirement Phase 8, 8d: the former
            // `if (function_exists('pwg_query'))` guard here was provably
            // dead (pwg_query is never defined anywhere in this codebase,
            // confirmed by a full-repo grep) -- meaning this write never
            // actually persisted, and the mkgetdir()/is_writable() check
            // above silently re-ran on every single request instead of
            // once, since CurrentConfig::has('data_dir_checked') could never
            // become true. Removed the guard and retargeted onto
            // CurrentConfigService::get() (Tier 2 -- constructed throwaway
            // at ~8 real sites).
            //
            // The try/catch is real, not defensive-for-its-own-sake: found
            // live via composer test:fixture-regen. Admin\Install\InstallWizard::boot()
            // constructs a Template (this class) *before*
            // performInstall() creates the schema -- on a genuinely fresh
            // install, the config table doesn't exist yet at this exact
            // call site (confirmed via a real TableNotFoundException, not
            // assumed). Every other real construction site
            // (Bootstrap\RequestBootstrap.php x2) always has an existing
            // config table by the time it runs.
            // Harmless to skip here: this write is purely a "don't repeat
            // this filesystem check" cache -- the very next real request
            // (post-install, table now exists) sees CurrentConfig::has('data_dir_checked')
            // still false and simply redoes the cheap mkgetdir/is_writable
            // check once more, no different from before this write existed.
            //
            // Widened from the narrower TableNotFoundException to the full
            // Doctrine\DBAL\Exception interface -- found live via
            // composer test:install against a genuinely fresh DB (a state
            // fixture-regen's own DB always already had real credentials
            // for, so it never exercised this): on the *first* GET to
            // install.php, before the form is ever submitted,
            // InstallWizard::boot()'s own DbCredentials::seed(['PIWIGO_DB_USER' => $this->dbuser, ...])
            // (needed so a *submitted* form's real credentials win over
            // stale ones -- see that call site's own docblock) runs with
            // $this->dbuser === '' (no $_POST yet), which overwrites
            // whatever valid credentials were already loaded (e.g. a test
            // run's .env.test-sourced ones) with empty strings. The
            // resulting connection attempt here fails at the credential
            // stage itself (Doctrine\DBAL\Exception\ConnectionException,
            // "Access denied for user ''@'localhost'"), not the
            // table-lookup stage -- a sibling failure mode this call site
            // must tolerate for the exact same reason it already tolerates
            // a missing table.
            try {
                \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('data_dir_checked', 1);
            } catch (\Doctrine\DBAL\Exception) {
            }
        }

        $compile_dir = CurrentPaths::get()->root . $conf_data_location . 'templates_c';
        \Piwigo\Core\FilesystemHelper::mkgetdir($compile_dir);

        $this->smarty->setCompileDir($compile_dir);

        $this->smarty->assign('pwg', new PwgTemplateAdapter());
        $this->smarty->registerPlugin('modifiercompiler', 'translate', self::modcompiler_translate(...));
        $this->smarty->registerPlugin('modifiercompiler', 'translate_dec', self::modcompiler_translate_dec(...));
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
        $this->smarty->registerPlugin('modifier', 'explode', self::mod_explode(...));
        $this->smarty->registerPlugin('modifier', 'ternary', self::mod_ternary(...));
        $this->smarty->registerPlugin('modifier', 'get_extent', $this->get_extent(...));
        $this->smarty->registerPlugin('block', 'html_head', $this->block_html_head(...));
        $this->smarty->registerPlugin('block', 'html_style', $this->block_html_style(...));
        $this->smarty->registerPlugin('function', 'combine_script', $this->func_combine_script(...));
        $this->smarty->registerPlugin('function', 'get_combined_scripts', $this->func_get_combined_scripts(...));
        $this->smarty->registerPlugin('function', 'combine_css', $this->func_combine_css(...));
        $this->smarty->registerPlugin('function', 'define_derivative', $this->func_define_derivative(...));
        $this->smarty->registerPlugin('compiler', 'get_combined_css', $this->func_get_combined_css(...));
        $this->smarty->registerPlugin('block', 'footer_script', $this->block_footer_script(...));
        $this->smarty->registerFilter('pre', self::prefilter_white_space(...));
        $this->smarty->registerPlugin('modifier', 'url_is_remote', self::urlService()->urlIsRemote(...));
        $this->smarty->registerPlugin('modifier', 'is_null', 'is_null');
        $this->smarty->registerPlugin('modifier', 'l10n', Lang::t(...));
        $this->smarty->registerPlugin('modifier', 'str_replace', 'str_replace');
        $this->smarty->registerPlugin('modifier', 'is_admin', AccessControl::isAdmin(...));
        $this->smarty->registerPlugin('modifier', 'is_classic_user', AccessControl::isClassicUser(...));
        $this->smarty->registerPlugin('modifier', 'get_device', DeviceHelper::getDevice(...));
        $this->smarty->registerPlugin('modifier', 'is_file', 'is_file');
        $this->smarty->registerPlugin('modifier', 'strpos', 'strpos');
        $this->smarty->registerPlugin('modifier', 'preg_match', 'preg_match');
        $this->smarty->registerPlugin('modifier', 'get_gallery_home_url', self::urlService()->getGalleryHomeUrl(...));
        $this->smarty->registerPlugin('modifier', 'sizeOf', 'sizeOf');
        $this->smarty->registerPlugin('modifier', 'array_key_exists', 'array_key_exists');

        if (\Piwigo\Config\CurrentConfig::compiledTemplateCacheLanguage()) {
            $this->smarty->registerFilter('post', self::postfilter_language(...));
        }

        $this->smarty->setTemplateDir([]);
        if ($theme !== '') {
            $this->set_theme($root, $theme, $path);
            if (! AdminContext::isActive()) {
                $this->set_prefilter('header', self::prefilter_local_css(...));
            }
        } else {
            $this->set_template_dir($root);
        }

        $lang_info = Lang::langInfo();
        if (isset($lang_info['code']) and ! isset($lang_info['jquery_code'])) {
            $lang_info['jquery_code'] = $lang_info['code'];
        }

        if (isset($lang_info['jquery_code']) and is_string($lang_info['jquery_code']) and ! isset($lang_info['plupload_code'])) {
            $lang_info['plupload_code'] = str_replace('-', '_', $lang_info['jquery_code']);
        }

        Lang::setLangInfo($lang_info);
        $this->smarty->assign('lang_info', $lang_info);

        if (! AdminContext::isActive()) {
            $this->set_extents(\Piwigo\Config\CurrentConfig::extentsForTemplates(), './template-extension/', true, $theme);
        }
    }

    /**
     * Throwaway construction, not a constructor property -- this
     * constructor takes only 3 plain strings and has 8 real construction
     * sites; none of its own methods run during construction, so a new
     * required dependency would ripple for no benefit. `private static`
     * (not `private`) so it's reachable from make_script_src() below,
     * which is itself static. Legacy Coupling Retirement Phase 4c.
     */
    private static function urlService(): UrlServiceInterface
    {
        return new UrlService(new HtmlService());
    }

    /**
     * Loads theme's parameters.
     *
     * @param string $root
     * @param string $theme
     * @param string $path
     * @param bool $load_css
     * @param bool $load_local_head
     */
    public function set_theme($root, $theme, $path, $load_css = true, $load_local_head = true, string $colorscheme = 'dark'): void
    {
        // we need themeconf before std_pgs to see what themes use_standard_pages
        $themeconf = $this->load_themeconf($root . '/' . $theme);

        // We loop over the theme and the parent theme, so if we exclude default,
        // standard pages can't get the header to load the html header
        if (
            $theme !== 'default'
            and in_array(\Piwigo\Core\PageFilterHelper::scriptBasename(), ['identification', 'register', 'password', 'profile'], true)
            and ((bool) ($themeconf['use_standard_pages'] ?? false) or \Piwigo\Config\CurrentConfig::useStandardPages())
        ) {
            $theme = 'standard_pages';
            $themeconf = $this->load_themeconf($root . '/' . $theme);
        }

        $this->set_template_dir($root . '/' . $theme . '/' . $path);

        if (isset($themeconf['parent']) and is_string($themeconf['parent']) and $themeconf['parent'] !== $theme) {
            $load_parent_css = $themeconf['load_parent_css'] ?? $load_css;
            $load_parent_local_head = $themeconf['load_parent_local_head'] ?? $load_local_head;
            $this->set_theme(
                $root,
                $themeconf['parent'],
                $path,
                is_bool($load_parent_css) ? $load_parent_css : $load_css,
                is_bool($load_parent_local_head) ? $load_parent_local_head : $load_local_head
            );
        }

        $tpl_var = [
            'id' => $theme,
            'load_css' => $load_css,
        ];
        if (! in_array($themeconf['local_head'] ?? null, [null, false, 0, '0', '', []], true) and $load_local_head and is_string($themeconf['local_head'])) {
            $tpl_var['local_head'] = realpath($root . '/' . $theme . '/' . $themeconf['local_head']);
        }
        $themeconf['id'] = $theme;

        if (! isset($themeconf['colorscheme'])) {
            $themeconf['colorscheme'] = $colorscheme;
        }

        $this->smarty->append('themes', $tpl_var);
        $this->smarty->append('themeconf', $themeconf, true);
    }

    /**
     * Adds template directory for this Template object.
     * Also set compile id if not exists.
     *
     * @param string $dir
     */
    public function set_template_dir($dir): void
    {
        $this->smarty->addTemplateDir($dir);

        // Smarty's own @var string on $compile_id contradicts its own
        // `= null` default (vendor/smarty/smarty/src/TemplateBase.php) —
        // not a native type, and not ours to fix.
        // @phpstan-ignore isset.property
        if (! isset($this->smarty->compile_id)) {
            $compile_id = '1';
            $compile_id .= ($real_dir = realpath($dir)) === false ? $dir : $real_dir;
            $this->smarty->compile_id = base_convert(hash('crc32b', $compile_id), 16, 36);
        }
    }

    /**
     * Gets the template root directory for this Template object.
     */
    public function get_template_dir(): string
    {
        $dir = $this->smarty->getTemplateDir(0);
        return is_string($dir) ? $dir : '';
    }

    /**
     * Deletes all compiled templates.
     */
    public function delete_compiled_templates(): void
    {
        $save_compile_id = $this->smarty->compile_id;
        // Smarty's own @var string on $compile_id contradicts its own
        // `= null` default (vendor/smarty/smarty/src/TemplateBase.php) —
        // not a native type, and not ours to fix.
        // @phpstan-ignore assign.propertyType
        $this->smarty->compile_id = null;
        $this->smarty->clearCompiledTemplate();
        $this->smarty->compile_id = $save_compile_id;
        file_put_contents($this->smarty->getCompileDir() . '/index.htm', 'Not allowed!');
    }

    /**
     * Returns theme's parameter.
     *
     * @param string $val
     * @return mixed
     */
    public function get_themeconf($val)
    {
        $tc = $this->smarty->getTemplateVars('themeconf');
        return is_array($tc) ? ($tc[$val] ?? '') : '';
    }

    /**
     * P23 batch 8f-4: string-narrowed variant with the exact contract of
     * the deleted get_themeconf() free function (include/functions.inc.php)
     * -- the corresponding $themeconf value if existing and a string,
     * otherwise an empty string. Implements
     * Piwigo\Core\ThemeConfProviderInterface so L2a callers (SrcImage) can
     * reach it without depending on this L3 class; see that interface's
     * own docblock.
     */
    #[\Override]
    public function themeConf(string $key): string
    {
        $value = $this->get_themeconf($key);

        return is_string($value) ? $value : '';
    }

    /**
     * Sets the template filename for handle.
     *
     * @param string $handle
     * @param string $filename
     */
    #[\Override]
    public function set_filename($handle, $filename): bool
    {
        return $this->set_filenames([
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
    #[\Override]
    public function set_filenames($filename_array): bool
    {
        reset($filename_array);
        foreach ($filename_array as $handle => $filename) {
            if ($filename === null) {
                unset($this->files[$handle]);
            } else {
                $this->files[$handle] = $this->get_extent($filename, $handle);
            }
        }
        return true;
    }

    /**
     * Sets template extention filename for handles.
     *
     * @param string $filename
     * @param mixed $param
     * @param string $dir
     * @param bool $overwrite
     * @param string $theme
     */
    public function set_extent($filename, $param, $dir = '', $overwrite = true, $theme = 'N/A'): bool
    {
        return $this->set_extents([
            $filename => $param,
        ], $dir, $overwrite);
    }

    /**
     * Sets template extentions filenames for handles.
     *
     * @param mixed $filename_array hashmap of handle=>filename; also called
     *   directly with a plugin-supplied value (set_extent()'s caller),
     *   which is not guaranteed to be an array
     * @param string $dir
     * @param bool $overwrite
     * @param string $theme
     */
    public function set_extents($filename_array, $dir = '', $overwrite = true, $theme = 'N/A'): bool
    {
        if (! is_array($filename_array)) {
            return false;
        }
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

            if ((stripos(implode('', array_keys($_GET)), '/' . (string) $param) !== false or (is_string($param) and $param === 'N/A'))
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
     * @param string $handle
     * @return string
     */
    public function get_extent($filename = '', $handle = '')
    {
        if (isset($this->extents[$handle])) {
            $filename = $this->extents[$handle];
        }
        return $filename;
    }

    /**
     * Assigns a template variable.
     * @see http://www.smarty.net/manual/en/api.assign.php
     *
     * @param string|array<string, mixed> $tpl_var can be a var name or a hashmap of variables
     *    (in this case, do not use the _$value_ parameter)
     * @param mixed $value
     */
    #[\Override]
    public function assign($tpl_var, $value = null): void
    {
        $this->smarty->assign($tpl_var, $value);
    }

    /**
     * Defines _$varname_ as the compiled result of _$handle_.
     * This can be used to effectively include a template in another template.
     * This is equivalent to assign($varname, $this->parse($handle, true)).
     *
     * @param string $varname
     * @return true
     */
    #[\Override]
    public function assign_var_from_handle($varname, string $handle): bool
    {
        $this->assign($varname, $this->parse($handle, true));
        return true;
    }

    /**
     * Appends a new value in a template array variable, the variable is created if needed.
     * @see http://www.smarty.net/manual/en/api.append.php
     *
     * @param string $tpl_var
     * @param mixed $value
     * @param bool $merge
     */
    #[\Override]
    public function append($tpl_var, $value = null, $merge = false): void
    {
        $this->smarty->append($tpl_var, $value, $merge);
    }

    /**
     * Performs a string concatenation.
     *
     * @param string $tpl_var
     * @param string $value
     */
    public function concat($tpl_var, $value): void
    {
        $current = $this->smarty->getTemplateVars($tpl_var);
        $this->assign(
            $tpl_var,
            (is_string($current) ? $current : '') . $value
        );
    }

    /**
     * Removes an assigned template variable.
     * @see http://www.smarty.net/manual/en/api.clear_assign.php
     *
     * @param string $tpl_var
     */
    #[\Override]
    public function clear_assign($tpl_var): void
    {
        $this->smarty->clearAssign($tpl_var);
    }

    /**
     * Returns an assigned template variable.
     * @see http://www.smarty.net/manual/en/api.get_template_vars.php
     *
     * @param string|null $tpl_var
     */
    #[\Override]
    public function get_template_vars($tpl_var = null): mixed
    {
        return $this->smarty->getTemplateVars($tpl_var);
    }

    /**
     * Loads the template file of the handle, compiles it and appends the result to the output
     * (or returns it if _$return_ is true).
     *
     * @phpstan-return ($return is true ? string : null)
     */
    public function parse(string $handle, bool $return = false): ?string
    {
        if (! isset($this->files[$handle])) {
            new HtmlService()
                ->fatalError("Template->parse(): Couldn't load template file for handle {$handle}");
        }

        $this->smarty->assign('ROOT_URL', self::urlService()->getRootUrl());
        // Legacy Coupling Retirement gap-closure (entry-shell define()/
        // include round): the .tpl-side equivalent of PHPWG_ROOT_PATH for
        // the handful of templates that need a real filesystem existence
        // check (datepicker.inc.tpl/photos_add_direct.tpl's own
        // `{if $ROOT_PATH|@cat:...|@file_exists}`), not a URL -- ROOT_URL
        // above is request-relative and wrong for file_exists().
        $this->smarty->assign('ROOT_PATH', CurrentPaths::get()->root);

        $save_compile_id = $this->smarty->compile_id;
        $this->load_external_filters($handle);

        $lang_info = Lang::langInfo();
        if (\Piwigo\Config\CurrentConfig::compiledTemplateCacheLanguage() and isset($lang_info['code']) and is_string($lang_info['code'])) {
            $this->smarty->compile_id .= '_' . $lang_info['code'];
        }

        $v = $this->smarty->fetch($this->files[$handle]);

        $this->smarty->compile_id = $save_compile_id;
        $this->unload_external_filters($handle);

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
        if (! $this->scriptLoader->did_head()) {
            $pos = strpos($this->output, self::COMBINED_SCRIPTS_TAG);
            if ($pos !== false) {
                $scripts = $this->scriptLoader->get_head_scripts();
                $content = [];
                foreach ($scripts as $script) {
                    $content[] =
                        '<script type="text/javascript" src="'
                        . self::make_script_src($script)
                        . '"></script>';
                }

                $this->output = substr_replace($this->output, implode("\n", $content), $pos, strlen(self::COMBINED_SCRIPTS_TAG));
            } // else maybe error or warning ?
        }

        $css = $this->cssLoader->get_css(self::urlService());

        $content = [];
        foreach ($css as $combi) {
            $href = self::urlService()->embellishUrl(self::urlService()->getRootUrl() . $combi->path);
            if ($combi->version !== false) {
                $href .= '?v' . ((bool) $combi->version ? $combi->version : AppInfo::VERSION);
            }
            // trigger the event for eventual use of a cdn
            $href = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('combined_css', $href, $combi);
            if (! is_string($href)) {
                throw new \Exception("flush(): a 'combined_css' event listener returned a non-string value");
            }
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
     * value (Legacy Coupling Retirement Workstream D: controllers
     * returning a real PSR-7 Response instead of echoing directly) rather
     * than as a side effect.
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
                    'AAAA_DEBUG_TOTAL_TIME__' => \Piwigo\Core\TimingHelper::getElapsedTime(\Piwigo\Core\PageState::current()->requestStart, \Piwigo\Core\TimingHelper::getMoment()),
                ]
            );
            // Pre-existing dead code, unchanged by this extraction: class
            // \Smarty_Internal_Debug doesn't exist in the installed Smarty
            // 5.x package (that's Smarty\Debug now, an instance method, not
            // static) — this call already fatals with "Class not found"
            // whenever \Piwigo\Config\CurrentConfig::debugTemplate() is enabled. Not in scope for
            // a pure extraction; preserved verbatim (still backslash-
            // qualified so it keeps failing the same way, not silently
            // resolving to a new Piwigo\Template\Smarty_Internal_Debug
            // lookup).
            // @phpstan-ignore class.notFound
            \Smarty_Internal_Debug::display_debug($this->smarty);
        }
    }

    /**
     * Eval a temp string to retrieve the original PHP value.
     *
     * @param string $str
     * @return mixed
     */
    public static function get_php_str_val($str)
    {
        if (strlen($str) > 1) {
            if (($str[0] === '\'' && $str[strlen($str) - 1] === '\'')
              || ($str[0] === '"' && $str[strlen($str) - 1] === '"')) {
                eval('$tmp=' . $str . ';');
                // Same eval() blind spot as prefilter_white_space() below:
                // PHPStan treats variables only ever assigned inside eval()
                // as undefined in the enclosing scope.
                // @phpstan-ignore variable.undefined
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
     * @see Lang::t()
     * @param array<int, string> $params
     */
    public static function modcompiler_translate(array $params): string
    {

        switch (count($params)) {
            case 1:
                $key = self::get_php_str_val($params[0]);
                // get_php_str_val() evaluates a quoted PHP string literal
                // via eval(), which PHPStan can't trace the return type of
                // -- it's always a real string here since $params[0] is a
                // template-compiled string literal expression, but narrow
                // explicitly since the callee's return type is opaque.
                if (\Piwigo\Config\CurrentConfig::compiledTemplateCacheLanguage()
                  && is_string($key)
                  && Lang::has($key)
                ) {
                    return var_export(Lang::t($key), true);
                }
                return '\Piwigo\Core\Lang::t(' . $params[0] . ')';

            default:
                if (\Piwigo\Config\CurrentConfig::compiledTemplateCacheLanguage()) {
                    $ret = 'sprintf(';
                    $ret .= self::modcompiler_translate([$params[0]]);
                    $ret .= ',' . implode(',', array_slice($params, 1));
                    $ret .= ')';
                    return $ret;
                }
                return '\Piwigo\Core\Lang::t(' . $params[0] . ',' . implode(',', array_slice($params, 1)) . ')';
        }
    }

    /**
     * "translate_dec" variable modifier.
     * Usage :
     *    - {$count|translate_dec:'%d comment':'%d comments'}
     * @see Lang::plural()
     * @param array<int, string> $params
     */
    public static function modcompiler_translate_dec(array $params): string
    {
        if (\Piwigo\Config\CurrentConfig::compiledTemplateCacheLanguage()) {
            $ret = 'sprintf(';
            if ((bool) Lang::langInfo()['zero_plural']) {
                $ret .= '($tmp=(' . $params[0] . '))>1||$tmp==0';
            } else {
                $ret .= '($tmp=(' . $params[0] . '))>1';
            }
            $ret .= '?';
            $ret .= self::modcompiler_translate([$params[2]]);
            $ret .= ':';
            $ret .= self::modcompiler_translate([$params[1]]);
            $ret .= ',$tmp';
            $ret .= ')';
            return $ret;
        }
        return '\Piwigo\Core\Lang::plural(' . $params[1] . ',' . $params[2] . ',' . $params[0] . ')';
    }

    /**
     * "explode" variable modifier.
     * Usage :
     *    - {assign var=valueExploded value=$value|explode:','}
     *
     * @param string $text
     * @param string $delimiter
     * @return string[]
     */
    public static function mod_explode($text, $delimiter = ','): array
    {
        if ($delimiter === '') {
            throw new \Exception('mod_explode(): delimiter must not be empty');
        }
        return explode($delimiter, $text);
    }

    /**
     * ternary variable modifier.
     * Usage :
     *    - {$variable|ternary:'yes':'no'}
     *
     * @param mixed $param
     * @param mixed $true
     * @param mixed $false
     * @return mixed
     */
    public static function mod_ternary($param, $true, $false)
    {
        return (bool) $param ? $true : $false;
    }

    /**
     * The "html_head" block allows to add content just before
     * </head> element in the output after the head has been parsed.
     *
     * @param array<int, mixed> $params (unused)
     * @param string|null $content
     */
    public function block_html_head($params, $content): void
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
     * @param string|null $content
     */
    public function block_html_style($params, $content): void
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
     * @param array $params
     *    - name (required)
     *    - type (optional)
     *    - width (required if type is empty)
     *    - height (required if type is empty)
     *    - crop (optional, used if type is empty)
     *    - min_height (optional, used with crop)
     *    - min_height (optional, used with crop)
     * @param array<string, mixed> $params
     * @param Smarty $smarty
     */
    public function func_define_derivative(array $params, $smarty): void
    {
        $name = $params['name'] ?? null;
        (! in_array($name, [null, false, 0, '0', '', []], true) && is_string($name)) or new HtmlService()
            ->fatalError('define_derivative missing name');
        if (isset($params['type'])) {
            $type = $params['type'];
            is_string($type) or new HtmlService()
                ->fatalError('define_derivative type must be a string');
            $derivative = ImageStdParams::get_by_type($type);
            $smarty->assign($name, $derivative);
            return;
        }
        ! in_array($params['width'] ?? null, [null, false, 0, '0', '', []], true) or new HtmlService()->fatalError('define_derivative missing width');
        ! in_array($params['height'] ?? null, [null, false, 0, '0', '', []], true) or new HtmlService()->fatalError('define_derivative missing height');
        $width = $params['width'];
        $height = $params['height'];
        is_scalar($width) or new HtmlService()
            ->fatalError('define_derivative width must be scalar');
        is_scalar($height) or new HtmlService()
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
                is_numeric($crop_val) or new HtmlService()
                    ->fatalError('define_derivative crop must be numeric');
                $crop = round((float) $crop_val / 100.0, 2);
            }

            if ((bool) $crop) {
                if (in_array($params['min_width'] ?? null, [null, false, 0, '0', '', []], true)) {
                    $minw = $w;
                } else {
                    $min_width = $params['min_width'];
                    is_scalar($min_width) or new HtmlService()
                        ->fatalError('define_derivative min_width must be scalar');
                    $minw = intval($min_width);
                }
                $minw <= $w or new HtmlService()
                    ->fatalError('define_derivative invalid min_width');
                if (in_array($params['min_height'] ?? null, [null, false, 0, '0', '', []], true)) {
                    $minh = $h;
                } else {
                    $min_height = $params['min_height'];
                    is_scalar($min_height) or new HtmlService()
                        ->fatalError('define_derivative min_height must be scalar');
                    $minh = intval($min_height);
                }
                $minh <= $h or new HtmlService()
                    ->fatalError('define_derivative invalid min_height');
            }
        }

        $smarty->assign($name, ImageStdParams::get_custom($w, $h, $crop, $minw, $minh));
    }

    /**
     * The "combine_script" functions allows inclusion of a javascript file in the current page.
     * The engine will combine several js files into a single one.
     *
     * @param array $params
     *   - id (required)
     *   - path (required)
     *   - load (optional) 'header', 'footer' or 'async'
     *   - require (optional) comma separated list of script ids required to be loaded
     *     and executed before this one
     *   - version (optional) used to force a browser refresh
     * @param array<string, mixed> $params
     */
    public function func_combine_script(array $params): void
    {
        if (! isset($params['id']) || ! is_string($params['id'])) {
            trigger_error("combine_script: missing 'id' parameter", E_USER_ERROR);
            // Genuinely reachable, not just defensive: include/error_collector.inc.php
            // installs a set_error_handler() that intercepts E_USER_ERROR and returns
            // true (suppressing PHP's normal fatal-and-terminate behavior), so this
            // return prevents $params['id'] from being read below when it's genuinely
            // missing/non-string — static analysis has no way to know
            // set_error_handler() changes trigger_error()'s termination behavior.
            // @phpstan-ignore deadCode.unreachable
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
                default: trigger_error("combine_script: invalid 'load' parameter", E_USER_ERROR);
            }
        }

        $require = $params['require'] ?? null;
        $require_list = (! in_array($require, [null, false, 0, '0', '', []], true) && is_scalar($require)) ? explode(',', (string) $require) : [];

        $path = $params['path'] ?? null;
        $path = is_string($path) ? $path : null;

        $version = $params['version'] ?? '0';
        $version = is_string($version) ? $version : '0';

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
    public function func_get_combined_scripts(array $params): string
    {
        if (! isset($params['load'])) {
            trigger_error("get_combined_scripts: missing 'load' parameter", E_USER_ERROR);
        }
        $load = $params['load'] === 'header' ? 0 : 1;
        $content = [];

        if ($load === 0) {
            return self::COMBINED_SCRIPTS_TAG;
        } else {
            $scripts = $this->scriptLoader->get_footer_scripts();
            foreach ($scripts[0] as $script) {
                $content[] =
                  '<script type="text/javascript" src="'
                  . self::make_script_src($script)
                  . '"></script>';
            }
            if ((bool) count($this->scriptLoader->inline_scripts)) {
                $content[] = '<script type="text/javascript">//<![CDATA[
';
                $content = array_merge($content, $this->scriptLoader->inline_scripts);
                $content[] = '//]]></script>';
            }

            if ((bool) count($scripts[1])) {
                $content[] = '<script type="text/javascript">';
                $content[] = '(function() {
var s,after = document.getElementsByTagName(\'script\')[document.getElementsByTagName(\'script\').length-1];';
                foreach ($scripts[1] as $id => $script) {
                    $content[] =
                      's=document.createElement(\'script\'); s.type=\'text/javascript\'; s.async=true; s.src=\''
                      . self::make_script_src($script)
                      . '\';';
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
     *
     * @param Combinable $script
     */
    private static function make_script_src($script): string
    {
        $ret = '';
        if ($script->is_remote(self::urlService())) {
            $ret = $script->path;
        } else {
            $ret = self::urlService()->getRootUrl() . $script->path;
            if ($script->version !== false) {
                $ret .= '?v' . ((bool) $script->version ? $script->version : AppInfo::VERSION);
            }
        }
        // trigger the event for eventual use of a cdn — no in-tree listener
        // registers for 'combined_script', so $ret is always still a string
        // here, but a plugin listener could theoretically return something
        // else, which would be a plugin bug worth surfacing loudly
        $ret = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('combined_script', $ret, $script);
        if (! is_string($ret)) {
            throw new \Exception("make_script_src(): a 'combined_script' event listener returned a non-string value");
        }
        return self::urlService()->embellishUrl($ret);
    }

    /**
     * The "footer_script" block allows to add runtime script in the HTML page.
     *
     * @param array $params
     *    - require (optional) comma separated list of script ids
     * @param array<string, mixed> $params
     * @param string|null $content
     */
    public function block_footer_script(array $params, $content): void
    {
        // Smarty calls block plugins twice: null $content on the opening
        // tag, real content on the closing tag ("second call" below).
        $content = trim((string) $content);
        if ($content !== '') { // second call
            $require = $params['require'] ?? null;
            $require_list = (! in_array($require, [null, false, 0, '0', '', []], true) && is_scalar($require)) ? explode(',', (string) $require) : [];

            $this->scriptLoader->add_inline(
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
    public function func_combine_css(array $params): void
    {
        if (in_array($params['path'] ?? null, [null, false, 0, '0', '', []], true) || ! is_string($params['path'])) {
            new HtmlService()
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
     * The "get_combined_scripts" function returns a placeholder for delayed
     * CSS files combination and minification.
     *
     * @param array<int, mixed> $params (unused)
     */
    public function func_get_combined_css($params): string
    {
        return self::COMBINED_CSS_TAG;
    }

    /**
     * Declares a Smarty prefilter from a plugin, allowing it to modify template
     * source before compilation and without changing core files.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.prefilters.php
     *
     * @param string $handle
     * @param callable $callback
     */
    public function set_prefilter($handle, $callback, int $weight = 50): void
    {
        $this->external_filters[$handle][$weight][] = ['pre', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Declares a Smarty postfilter.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.postfilters.php
     *
     * @param string $handle
     * @param callable $callback
     */
    public function set_postfilter($handle, $callback, int $weight = 50): void
    {
        $this->external_filters[$handle][$weight][] = ['post', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Declares a Smarty outputfilter.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.outputfilters.php
     *
     * @param string $handle
     * @param callable $callback
     */
    public function set_outputfilter($handle, $callback, int $weight = 50): void
    {
        $this->external_filters[$handle][$weight][] = ['output', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Register the filters for the tpl file.
     *
     * @param string $handle
     */
    public function load_external_filters($handle): void
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
     *
     * @param string $handle
     */
    public function unload_external_filters($handle): void
    {
        if (isset($this->external_filters[$handle])) {
            foreach ($this->external_filters[$handle] as $filters) {
                foreach ($filters as $filter) {
                    [$type, $callback] = $filter;
                    // Smarty\Smarty::unregisterFilter()'s own docblock types
                    // its 2nd param as `callback|string` -- `callback` (no
                    // trailing e) isn't a recognized PHPStan pseudo-type, so
                    // it resolves as the unrelated class Smarty\callback
                    // instead of PHP's native `callable`. The implementation
                    // (vendor/smarty/smarty/src/Smarty.php) itself accepts
                    // any real callable via is_string()/_getFilterName()
                    // fallback -- this is a vendor docblock typo, not a bug
                    // in our code.
                    // @phpstan-ignore argument.type
                    $this->smarty->unregisterFilter($type, $callback);
                }
            }
        }
    }

    /**
     * @toto : description of Template::prefilter_white_space
     *
     * @param string $source
     * @param Smarty $smarty
     */
    public static function prefilter_white_space($source, $smarty): ?string
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
     * @param string $source
     * @param Smarty $smarty
     */
    public static function postfilter_language($source, $smarty): ?string
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
                // evaluated string) -- there's no provable guard possible,
                // this is a genuine static-analysis blind spot on eval().
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
     * @param string $source
     * @param Smarty $smarty
     * @return string
     */
    public static function prefilter_local_css($source, $smarty)
    {
        $paths = CurrentPaths::get();
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
     * @param string $dir
     * @return array<string, mixed>
     */
    public function load_themeconf($dir)
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
        if (! ProcessCache::has($cache_key)) {
            $themeconf = [];
            // themeconf.inc.php may set this to push extra template
            // variables, instead of reaching for $this/$template directly
            // (this file is included from many distinct Template instances,
            // not only the global $template). assign() on an empty array is
            // a no-op, so no need to guard the common case where it's unset.
            $theme_template_vars = [];
            include $dir . '/themeconf.inc.php';
            $this->assign($theme_template_vars);
            // Put themeconf in cache
            ProcessCache::set($cache_key, $themeconf);
        }

        /** @var array<string, mixed> $cached */
        $cached = ProcessCache::get($cache_key);

        return $cached;
    }

    /**
     * Registers a button to be displayed on picture page.
     *
     * @param string $content
     */
    public function add_picture_button($content, int $rank = 50): void
    {
        $this->picture_buttons[$rank][] = $content;
    }

    /**
     * Registers a button to be displayed on index pages.
     *
     * @param string $content
     */
    public function add_index_button($content, int $rank = 50): void
    {
        $this->index_buttons[$rank][] = $content;
    }

    /**
     * Assigns PLUGIN_PICTURE_BUTTONS template variable with registered picture buttons.
     */
    public function parse_picture_buttons(): void
    {
        if ($this->picture_buttons !== []) {
            ksort($this->picture_buttons);
            $buttons = [];
            foreach ($this->picture_buttons as $k => $row) {
                $buttons = array_merge($buttons, $row);
            }
            $this->assign('PLUGIN_PICTURE_BUTTONS', $buttons);

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
    public function parse_index_buttons(): void
    {
        if ($this->index_buttons !== []) {
            ksort($this->index_buttons);
            $buttons = [];
            foreach ($this->index_buttons as $k => $row) {
                $buttons = array_merge($buttons, $row);
            }
            $this->assign('PLUGIN_INDEX_BUTTONS', $buttons);

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
