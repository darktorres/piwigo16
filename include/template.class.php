<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Smarty\Smarty;

/** default rank for buttons */
define('BUTTONS_RANK_NEUTRAL', 50);

/**
 * This a wrapper arround Smarty classes proving various custom mechanisms for templates.
 */
class Template
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

    /**
     * @const string
     */
    public const COMBINED_SCRIPTS_TAG = '<!-- COMBINED_SCRIPTS -->';

    /**
     * @var ScriptLoader
     */
    public $scriptLoader;

    /**
     * @const string
     */
    public const COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';

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
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $lang_info
         */
        global $conf, $lang_info;

        // \Smarty\Exception::$escape = false;

        $this->scriptLoader = new ScriptLoader();
        $this->cssLoader = new CssLoader();
        $this->smarty = new Smarty();
        $this->smarty->escape_html = false;
        // config_default.inc.php ships these as plain bool, but Smarty's own
        // $debugging accepts bool|int -- a hand-edited config is allowed to
        // set debug_template=2 for Smarty's "per-template debug window"
        // mode (vendor/smarty/smarty/src/Smarty.php), so int must survive
        // as-is and only non-int values fall back to a bool coercion.
        $debug_template = $conf['debug_template'];
        $this->smarty->debugging = is_int($debug_template) ? $debug_template : (bool) $debug_template;
        if (! (bool) $this->smarty->debugging) {
            $this->smarty->error_reporting = error_reporting() & ~E_NOTICE;
        }
        // compile_check/force_compile mirror Smarty's own setCompileCheck()/
        // setForceCompile() coercions (vendor/smarty/smarty/src/TemplateBase.php,
        // vendor/smarty/smarty/src/Smarty.php), whose own @var docblocks
        // (int / boolean respectively) don't carry the same bool|int
        // flexibility as $debugging above.
        $compile_check = $conf['template_compile_check'];
        $this->smarty->compile_check = is_scalar($compile_check) ? (int) $compile_check : Smarty::COMPILECHECK_ON;
        $this->smarty->force_compile = (bool) $conf['template_force_compile'];

        $conf_data_location = $conf['data_location'] ?? null;
        if (! is_string($conf_data_location)) {
            fatal_error("Invalid \$conf['data_location'] configuration: expected a string.");
        }

        if (! isset($conf['data_dir_checked'])) {
            $dir = PHPWG_ROOT_PATH . $conf_data_location;
            mkgetdir($dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR);
            if (! is_writable($dir)) {
                load_language('admin.lang');
                fatal_error(
                    l10n(
                        'Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation',
                        $conf_data_location
                    ),
                    l10n('an error happened'),
                    false // show trace
                );
            }
            if (function_exists('pwg_query')) {
                conf_update_param('data_dir_checked', 1);
            }
        }

        $compile_dir = PHPWG_ROOT_PATH . $conf_data_location . 'templates_c';
        mkgetdir($compile_dir);

        $this->smarty->setCompileDir($compile_dir);

        $this->smarty->assign('pwg', new PwgTemplateAdapter());
        $this->smarty->registerPlugin('modifiercompiler', 'translate', ['Template', 'modcompiler_translate']);
        $this->smarty->registerPlugin('modifiercompiler', 'translate_dec', ['Template', 'modcompiler_translate_dec']);
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
        $this->smarty->registerPlugin('modifier', 'explode', ['Template', 'mod_explode']);
        $this->smarty->registerPlugin('modifier', 'ternary', ['Template', 'mod_ternary']);
        $this->smarty->registerPlugin('modifier', 'get_extent', $this->get_extent(...));
        $this->smarty->registerPlugin('block', 'html_head', $this->block_html_head(...));
        $this->smarty->registerPlugin('block', 'html_style', $this->block_html_style(...));
        $this->smarty->registerPlugin('function', 'combine_script', $this->func_combine_script(...));
        $this->smarty->registerPlugin('function', 'get_combined_scripts', $this->func_get_combined_scripts(...));
        $this->smarty->registerPlugin('function', 'combine_css', $this->func_combine_css(...));
        $this->smarty->registerPlugin('function', 'define_derivative', $this->func_define_derivative(...));
        $this->smarty->registerPlugin('compiler', 'get_combined_css', $this->func_get_combined_css(...));
        $this->smarty->registerPlugin('block', 'footer_script', $this->block_footer_script(...));
        $this->smarty->registerFilter('pre', ['Template', 'prefilter_white_space']);
        $this->smarty->registerPlugin('modifier', 'url_is_remote', 'url_is_remote');
        $this->smarty->registerPlugin('modifier', 'is_null', 'is_null');
        $this->smarty->registerPlugin('modifier', 'l10n', 'l10n');
        $this->smarty->registerPlugin('modifier', 'str_replace', 'str_replace');
        $this->smarty->registerPlugin('modifier', 'is_admin', 'is_admin');
        $this->smarty->registerPlugin('modifier', 'is_classic_user', 'is_classic_user');
        $this->smarty->registerPlugin('modifier', 'get_device', 'get_device');
        $this->smarty->registerPlugin('modifier', 'is_file', 'is_file');
        $this->smarty->registerPlugin('modifier', 'strpos', 'strpos');
        $this->smarty->registerPlugin('modifier', 'preg_match', 'preg_match');
        $this->smarty->registerPlugin('modifier', 'get_gallery_home_url', 'get_gallery_home_url');
        $this->smarty->registerPlugin('modifier', 'sizeOf', 'sizeOf');
        $this->smarty->registerPlugin('modifier', 'array_key_exists', 'array_key_exists');

        if ((bool) $conf['compiled_template_cache_language']) {
            $this->smarty->registerFilter('post', ['Template', 'postfilter_language']);
        }

        $this->smarty->setTemplateDir([]);
        if (! empty($theme)) {
            $this->set_theme($root, $theme, $path);
            if (! defined('IN_ADMIN')) {
                $this->set_prefilter('header', ['Template', 'prefilter_local_css']);
            }
        } else {
            $this->set_template_dir($root);
        }

        if (isset($lang_info['code']) and ! isset($lang_info['jquery_code'])) {
            $lang_info['jquery_code'] = $lang_info['code'];
        }

        if (isset($lang_info['jquery_code']) and is_string($lang_info['jquery_code']) and ! isset($lang_info['plupload_code'])) {
            $lang_info['plupload_code'] = str_replace('-', '_', $lang_info['jquery_code']);
        }

        $this->smarty->assign('lang_info', $lang_info);

        if (! defined('IN_ADMIN') and isset($conf['extents_for_templates']) and is_string($conf['extents_for_templates'])) {
            $tpl_extents = unserialize($conf['extents_for_templates']);
            $this->set_extents($tpl_extents, './template-extension/', true, $theme);
        }
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
            $theme != 'default'
            and in_array(script_basename(), ['identification', 'register', 'password', 'profile'])
            and ((bool) ($themeconf['use_standard_pages'] ?? false) or (bool) conf_get_param('use_standard_pages', false))
        ) {
            $theme = 'standard_pages';
            $themeconf = $this->load_themeconf($root . '/' . $theme);
        }

        $this->set_template_dir($root . '/' . $theme . '/' . $path);

        if (isset($themeconf['parent']) and is_string($themeconf['parent']) and $themeconf['parent'] != $theme) {
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
        if (! empty($themeconf['local_head']) and $load_local_head and is_string($themeconf['local_head'])) {
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
     * Sets the template filename for handle.
     *
     * @param string $handle
     * @param string $filename
     */
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
     * @param mixed $filename_array hashmap of handle=>filename; the real
     *   caller (__construct()) passes unserialize($conf['extents_for_templates']),
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

            if ((stripos(implode('', array_keys($_GET)), '/' . $param) !== false or $param == 'N/A')
              and ($thm == $theme or $thm == 'N/A')
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
    public function clear_assign($tpl_var): void
    {
        $this->smarty->clearAssign($tpl_var);
    }

    /**
     * Returns an assigned template variable.
     * @see http://www.smarty.net/manual/en/api.get_template_vars.php
     *
     * @param string $tpl_var
     */
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
            fatal_error("Template->parse(): Couldn't load template file for handle {$handle}");
        }

        $this->smarty->assign('ROOT_URL', get_root_url());

        $save_compile_id = $this->smarty->compile_id;
        $this->load_external_filters($handle);

        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $lang_info
         */
        global $conf, $lang_info;
        if ((bool) $conf['compiled_template_cache_language'] and isset($lang_info['code']) and is_string($lang_info['code'])) {
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

        $css = $this->cssLoader->get_css();

        $content = [];
        foreach ($css as $combi) {
            $href = embellish_url(get_root_url() . $combi->path);
            if ($combi->version !== false) {
                $href .= '?v' . ((bool) $combi->version ? $combi->version : PHPWG_VERSION);
            }
            // trigger the event for eventual use of a cdn
            $href = trigger_change('combined_css', $href, $combi);
            if (! is_string($href)) {
                throw new Exception("flush(): a 'combined_css' event listener returned a non-string value");
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

        echo $this->output;
        $this->output = '';
    }

    /**
     * Same as flush() but with optional debugging.
     * @see Template::flush()
     */
    public function p(): void
    {
        $this->flush();

        if ((bool) $this->smarty->debugging) {
            /** @var float $t2 */
            global $t2;
            $this->smarty->assign(
                [
                    'AAAA_DEBUG_TOTAL_TIME__' => get_elapsed_time($t2, get_moment()),
                ]
            );
            Smarty_Internal_Debug::display_debug($this->smarty);
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
            if (($str[0] == '\'' && $str[strlen($str) - 1] == '\'')
              || ($str[0] == '"' && $str[strlen($str) - 1] == '"')) {
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
     * @see l10n()
     * @param array<int, string> $params
     */
    public static function modcompiler_translate(array $params): string
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $lang
         */
        global $conf, $lang;

        switch (count($params)) {
            case 1:
                $key = self::get_php_str_val($params[0]);
                // get_php_str_val() evaluates a quoted PHP string literal
                // via eval(), which PHPStan can't trace the return type of
                // -- it's always a real string here since $params[0] is a
                // template-compiled string literal expression, but narrow
                // explicitly since the callee's return type is opaque.
                if ((bool) $conf['compiled_template_cache_language']
                  && is_string($key)
                  && isset($lang[$key])
                ) {
                    return var_export($lang[$key], true);
                }
                return 'l10n(' . $params[0] . ')';

            default:
                if ((bool) $conf['compiled_template_cache_language']) {
                    $ret = 'sprintf(';
                    $ret .= self::modcompiler_translate([$params[0]]);
                    $ret .= ',' . implode(',', array_slice($params, 1));
                    $ret .= ')';
                    return $ret;
                }
                return 'l10n(' . $params[0] . ',' . implode(',', array_slice($params, 1)) . ')';
        }
    }

    /**
     * "translate_dec" variable modifier.
     * Usage :
     *    - {$count|translate_dec:'%d comment':'%d comments'}
     * @see l10n_dec()
     * @param array<int, string> $params
     */
    public static function modcompiler_translate_dec(array $params): string
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $lang_info
         */
        global $conf, $lang, $lang_info;
        if ((bool) $conf['compiled_template_cache_language']) {
            $ret = 'sprintf(';
            if ((bool) $lang_info['zero_plural']) {
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
        return 'l10n_dec(' . $params[1] . ',' . $params[2] . ',' . $params[0] . ')';
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
            throw new Exception('mod_explode(): delimiter must not be empty');
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
        if (! empty($content)) { // second call
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
        if (! empty($content)) { // second call
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
        (! empty($name) && is_string($name)) or fatal_error('define_derivative missing name');
        if (isset($params['type'])) {
            $type = $params['type'];
            is_string($type) or fatal_error('define_derivative type must be a string');
            $derivative = ImageStdParams::get_by_type($type);
            $smarty->assign($name, $derivative);
            return;
        }
        ! empty($params['width']) or fatal_error('define_derivative missing width');
        ! empty($params['height']) or fatal_error('define_derivative missing height');
        $width = $params['width'];
        $height = $params['height'];
        is_scalar($width) or fatal_error('define_derivative width must be scalar');
        is_scalar($height) or fatal_error('define_derivative height must be scalar');

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
                is_numeric($crop_val) or fatal_error('define_derivative crop must be numeric');
                $crop = round((float) $crop_val / 100, 2);
            }

            if ((bool) $crop) {
                if (empty($params['min_width'])) {
                    $minw = $w;
                } else {
                    $min_width = $params['min_width'];
                    is_scalar($min_width) or fatal_error('define_derivative min_width must be scalar');
                    $minw = intval($min_width);
                }
                $minw <= $w or fatal_error('define_derivative invalid min_width');
                if (empty($params['min_height'])) {
                    $minh = $h;
                } else {
                    $min_height = $params['min_height'];
                    is_scalar($min_height) or fatal_error('define_derivative min_height must be scalar');
                    $minh = intval($min_height);
                }
                $minh <= $h or fatal_error('define_derivative invalid min_height');
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
        $require_list = (! empty($require) && is_scalar($require)) ? explode(',', (string) $require) : [];

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
        $load = $params['load'] == 'header' ? 0 : 1;
        $content = [];

        if ($load == 0) {
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
        if ($script->is_remote()) {
            $ret = $script->path;
        } else {
            $ret = get_root_url() . $script->path;
            if ($script->version !== false) {
                $ret .= '?v' . ((bool) $script->version ? $script->version : PHPWG_VERSION);
            }
        }
        // trigger the event for eventual use of a cdn — no in-tree listener
        // registers for 'combined_script', so $ret is always still a string
        // here, but a plugin listener could theoretically return something
        // else, which would be a plugin bug worth surfacing loudly
        $ret = trigger_change('combined_script', $ret, $script);
        if (! is_string($ret)) {
            throw new Exception("make_script_src(): a 'combined_script' event listener returned a non-string value");
        }
        return embellish_url($ret);
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
        if (! empty($content)) { // second call
            $require = $params['require'] ?? null;
            $require_list = (! empty($require) && is_scalar($require)) ? explode(',', (string) $require) : [];

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
        if (empty($params['path']) || ! is_string($params['path'])) {
            fatal_error('combine_css missing path');
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
     * @return string|array<int|string, string|null>|null
     */
    public static function prefilter_white_space($source, $smarty): string|array|null
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
     * Postfilter used when $conf['compiled_template_cache_language'] is true.
     *
     * @param string $source
     * @param Smarty $smarty
     * @return string|array<int|string, string|null>|null
     */
    public static function postfilter_language($source, $smarty): string|array|null
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
                // @phpstan-ignore cast.string, isset.variable
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
        $css = [];
        $themes = $smarty->getTemplateVars('themes');
        if (is_array($themes)) {
            foreach ($themes as $theme) {
                if (! is_array($theme) || ! isset($theme['id']) || ! is_string($theme['id'])) {
                    continue;
                }
                $f = PWG_LOCAL_DIR . 'css/' . $theme['id'] . '-rules.css';
                if (file_exists(PHPWG_ROOT_PATH . $f)) {
                    $css[] = "{combine_css path='{$f}' order=10}";
                }
            }
        }
        $f = PWG_LOCAL_DIR . 'css/rules.css';
        if (file_exists(PHPWG_ROOT_PATH . $f)) {
            $css[] = "{combine_css path='{$f}' order=10}";
        }

        if (! empty($css)) {
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
        /** @var array<string, array<string, mixed>> $themeconfs */
        global $themeconfs, $conf;

        $real_dir = realpath($dir);
        if ($real_dir === false) {
            // Theme directory doesn't actually exist on disk -- don't cache
            // under a coerced-to-0 array key (every broken $dir would
            // collide on the same cache slot) or attempt to include a
            // bogus root-relative path.
            return [];
        }
        $dir = $real_dir;
        if (! isset($themeconfs[$dir])) {
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
            $themeconfs[$dir] = $themeconf;
        }
        return $themeconfs[$dir];
    }

    /**
     * Registers a button to be displayed on picture page.
     *
     * @param string $content
     */
    public function add_picture_button($content, int $rank = BUTTONS_RANK_NEUTRAL): void
    {
        $this->picture_buttons[$rank][] = $content;
    }

    /**
     * Registers a button to be displayed on index pages.
     *
     * @param string $content
     */
    public function add_index_button($content, int $rank = BUTTONS_RANK_NEUTRAL): void
    {
        $this->index_buttons[$rank][] = $content;
    }

    /**
     * Assigns PLUGIN_PICTURE_BUTTONS template variable with registered picture buttons.
     */
    public function parse_picture_buttons(): void
    {
        if (! empty($this->picture_buttons)) {
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
        if (! empty($this->index_buttons)) {
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

/**
 * This class contains basic functions that can be called directly from the
 * templates in the form $pwg->l10n('edit')
 */
class PwgTemplateAdapter
{
    #[\Deprecated(message: 'use "translate" modifier')]
    public function l10n(string $text): string
    {
        return l10n($text);
    }

    #[\Deprecated(message: 'use "translate_dec" modifier')]
    public function l10n_dec(string $s, string $p, int $v): string
    {
        return l10n_dec($s, $p, $v);
    }

    #[\Deprecated(message: 'use "translate" or "sprintf" modifier')]
    public function sprintf(): mixed
    {
        $args = func_get_args();
        return call_user_func_array(sprintf(...), $args);
    }

    /**
     * @param string $type
     * @param array<string, mixed>|SrcImage $img
     */
    public function derivative($type, $img): DerivativeImage
    {
        // Mirrors derivative_url()/DerivativeImage::url()'s own
        // is_object($infos) ? $infos : new SrcImage($infos) handling — the
        // constructor itself only accepts a real SrcImage.
        return new DerivativeImage($type, is_object($img) ? $img : new SrcImage($img));
    }

    /**
     * @param string $type
     * @param array<string, mixed> $img
     * @return string|array<int|string, mixed>
     */
    public function derivative_url($type, $img): string|array
    {
        return DerivativeImage::url($type, $img);
    }
}

/**
 * A Combinable represents a JS or CSS file ready for cobination and minification.
 */
class Combinable
{
    /**
     * @var string
     */
    public $path;

    /**
     * @var bool
     */
    public $is_template;

    /**
     * @param string $id
     * @param string|null $path null leaves $path unset -- callers that pass
     *   null (e.g. ScriptLoader::add()'s UI-core-dependency recursion) rely
     *   on a well-known path being filled in afterwards
     * @param string|false $version false disables version-based cache busting
     */
    public function __construct(
        public $id,
        $path,
        public $version = '0'
    ) {
        $this->set_path($path);
        $this->is_template = false;
    }

    /**
     * @param string|null $path a null/empty path is a deliberate no-op
     */
    public function set_path($path): void
    {
        if (! empty($path)) {
            $this->path = $path;
        }
    }

    public function is_remote(): bool
    {
        return url_is_remote($this->path) || str_starts_with($this->path, '//');
    }
}

/**
 * Implementation of Combinable for JS files.
 */
final class Script extends Combinable
{
    /**
     * @var array{order?: int}
     */
    public $extra;

    /**
     * @param int $load_mode 0,1,2
     * @param string $id
     * @param string|null $path see Combinable::__construct()
     * @param string|false $version false disables version-based cache
     *   busting, mirroring Combinable::$version's own contract
     * @param string[] $precedents
     */
    public function __construct(
        public $load_mode,
        $id,
        $path,
        $version = '0',
        public $precedents = []
    ) {
        parent::__construct($id, $path, $version);
        $this->extra = [];
    }
}

/**
 * Implementation of Combinable for CSS files.
 */
final class Css extends Combinable
{
    /**
     * @param string $id
     * @param string $path
     * @param string|false $version false disables version-based cache
     *   busting, mirroring Combinable::$version's own contract
     * @param int $order
     */
    public function __construct(
        $id,
        $path,
        $version = '0',
        public $order = 0
    ) {
        parent::__construct($id, $path, $version);
    }
}

/**
 * Manages a list of CSS files and combining them in a unique file.
 */
class CssLoader
{
    /**
     * @var Css[]
     */
    private array $registered_css;

    /**
     * @var int used to keep declaration order
     */
    private int $counter;

    public function __construct()
    {
        $this->clear();
    }

    public function clear(): void
    {
        $this->registered_css = [];
        $this->counter = 0;
    }

    /**
     * @return Combinable[] array of combined CSS.
     */
    public function get_css(): array
    {
        uasort($this->registered_css, ['CssLoader', 'cmp_by_order']);
        $combiner = new FileCombiner('css', $this->registered_css);
        return $combiner->combine();
    }

    /**
     * Callback for CSS files sorting.
     */
    private static function cmp_by_order(Css $a, Css $b): int
    {
        return $a->order - $b->order;
    }

    /**
     * Adds a new file, if a file with the same $id already exsists, the one with
     * the higher $order or higher $version is kept.
     *
     * @param string $id
     * @param string $path
     * @param string|false $version false disables version-based cache
     *   busting, mirroring Combinable::$version's own contract; no current
     *   .tpl passes version=, but func_combine_css() forwards it verbatim
     * @param int $order
     * @param bool $is_template
     */
    public function add($id, $path, $version = '0', $order = 0, $is_template = false): void
    {
        if (! isset($this->registered_css[$id])) {
            // costum order as an higher impact than declaration order
            $css = new Css($id, $path, $version, $order * 1000 + $this->counter);
            $css->is_template = $is_template;
            $this->registered_css[$id] = $css;
            $this->counter++;
        } else {
            $css = $this->registered_css[$id];
            if ($css->order < $order * 1000
                || $css->version === false
                || $version === false
                || version_compare($css->version, $version) < 0) {
                unset($this->registered_css[$id]);
                $this->add($id, $path, $version, $order, $is_template);
            }
        }
    }
}

/**
 * Manage a list of required scripts for a page, by optimizing their loading location (head, footer, async)
 * and later on by combining them in a unique file respecting at the same time dependencies.
 */
class ScriptLoader
{
    /**
     * @var array<string, Script>
     */
    private array $registered_scripts;

    /**
     * @var string[]
     */
    public $inline_scripts;

    private bool $did_head;

    /**
     * @var array<string, Script>
     */
    private array $head_done_scripts;

    private ?bool $did_footer = null;

    /**
     * @var array<string, string>
     */
    private static array $known_paths = [
        'core.scripts' => 'themes/default/js/scripts.js',
        'jquery' => 'themes/default/js/jquery.min.js',
        'jquery.ui' => 'themes/default/js/ui/minified/jquery.ui.core.min.js',
        'jquery.ui.effect' => 'themes/default/js/ui/minified/jquery.ui.effect.min.js',
    ];

    /**
     * @var array<string, string[]>
     */
    private static array $ui_core_dependencies = [
        'jquery.ui.widget' => ['jquery'],
        'jquery.ui.position' => ['jquery'],
        'jquery.ui.mouse' => ['jquery', 'jquery.ui', 'jquery.ui.widget'],
    ];

    public function __construct()
    {
        $this->clear();
    }

    public function clear(): void
    {
        $this->registered_scripts = [];
        $this->inline_scripts = [];
        $this->head_done_scripts = [];
        $this->did_head = $this->did_footer = false;
    }

    public function did_head(): bool
    {
        return $this->did_head;
    }

    /**
     * @return array<string, Script>
     */
    public function get_all(): array
    {
        return $this->registered_scripts;
    }

    /**
     * @param string $code
     * @param string[] $require
     */
    public function add_inline($code, $require): void
    {
        ! (bool) $this->did_footer || trigger_error('Attempt to add inline script but the footer has been written', E_USER_WARNING);
        if (! empty($require)) {
            foreach ($require as $id) {
                if (! isset($this->registered_scripts[$id])) {
                    $this->load_known_required_script($id, 1) or fatal_error("inline script not found require {$id}");
                }
                $s = $this->registered_scripts[$id];
                if ($s->load_mode == 2) {
                    $s->load_mode = 1;
                } // until now the implementation does not allow executing inline script depending on another async script
            }
        }
        $this->inline_scripts[] = $code;
    }

    /**
     * @param string $id
     * @param int $load_mode
     * @param string[] $require
     * @param string|null $path null defers to fill_well_known()'s
     *   self::$known_paths lookup by $id — this method's own UI-core-dependency
     *   recursion below passes null deliberately
     * @param string $version
     */
    public function add($id, $load_mode, $require, $path, $version = '0', bool $is_template = false): void
    {
        if ($this->did_head && $load_mode == 0) {
            trigger_error("Attempt to add script {$id} but the head has been written", E_USER_WARNING);
        } elseif ((bool) $this->did_footer) {
            trigger_error("Attempt to add script {$id} but the footer has been written", E_USER_WARNING);
        }
        if (! isset($this->registered_scripts[$id])) {
            $script = new Script($load_mode, $id, $path, $version, $require);
            $script->is_template = $is_template;
            self::fill_well_known($id, $script);
            $this->registered_scripts[$id] = $script;

            // Load or modify all UI core files
            if ($id == 'jquery.ui' and $script->path == self::$known_paths['jquery.ui']) {
                foreach (self::$ui_core_dependencies as $script_id => $required_ids) {
                    $this->add($script_id, $load_mode, $required_ids, null, $version);
                }
            }

            // Try to load undefined required script
            foreach ($script->precedents as $script_id) {
                if (! isset($this->registered_scripts[$script_id])) {
                    $this->load_known_required_script($script_id, $load_mode);
                }
            }
        } else {
            $script = $this->registered_scripts[$id];
            if ((bool) count($require)) {
                $script->precedents = array_unique(array_merge($script->precedents, $require));
            }
            $script->set_path($path);
            if ((bool) $version && $script->version !== false && version_compare($script->version, $version) < 0) {
                $script->version = $version;
            }
            if ($load_mode < $script->load_mode) {
                $script->load_mode = $load_mode;
            }
        }
    }

    /**
     * Returns combined scripts loaded in header.
     *
     * @return Combinable[]
     */
    public function get_head_scripts(): array
    {
        self::check_load_dep($this->registered_scripts);
        foreach (array_keys($this->registered_scripts) as $id) {
            $this->compute_script_topological_order($id);
        }

        uasort($this->registered_scripts, ['ScriptLoader', 'cmp_by_mode_and_order']);

        foreach ($this->registered_scripts as $id => $script) {
            if ($script->load_mode > 0) {
                break;
            }
            if (! empty($script->path)) {
                $this->head_done_scripts[$id] = $script;
            } else {
                trigger_error("Script {$id} has an undefined path", E_USER_WARNING);
            }
        }
        $this->did_head = true;
        return self::do_combine($this->head_done_scripts, 0);
    }

    /**
     * Returns combined scripts loaded in footer.
     *
     * @return array{0: Combinable[], 1: Combinable[]}
     */
    public function get_footer_scripts(): array
    {
        if (! $this->did_head) {
            self::check_load_dep($this->registered_scripts);
        }
        $this->did_footer = true;
        $todo = [];
        foreach ($this->registered_scripts as $id => $script) {
            if (! isset($this->head_done_scripts[$id])) {
                $todo[$id] = $script;
            }
        }

        foreach (array_keys($todo) as $id) {
            $this->compute_script_topological_order($id);
        }

        uasort($todo, ['ScriptLoader', 'cmp_by_mode_and_order']);

        $result = [[], []];
        foreach ($todo as $id => $script) {
            // load_mode 0 (head) scripts are handled by get_head_scripts();
            // only 1 (footer-sync) and 2 (footer-async) belong here.
            if ($script->load_mode > 0) {
                $result[$script->load_mode - 1][$id] = $script;
            }
        }
        return [self::do_combine($result[0], 1), self::do_combine($result[1], 2)];
    }

    /**
     * @param Script[] $scripts
     * @return Combinable[]
     */
    private static function do_combine(array $scripts, int $load_mode): array
    {
        $combiner = new FileCombiner('js', $scripts);
        return $combiner->combine();
    }

    /**
     * Checks dependencies among Scripts.
     * Checks that if B depends on A, then B->load_mode >= A->load_mode in order to respect execution order.
     *
     * @param Script[] $scripts
     */
    private static function check_load_dep(array $scripts): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        do {
            $changed = false;
            foreach ($scripts as $id => $script) {
                $load = $script->load_mode;
                foreach ($script->precedents as $precedent) {
                    if (! isset($scripts[$precedent])) {
                        continue;
                    }
                    if ($scripts[$precedent]->load_mode > $load) {
                        $scripts[$precedent]->load_mode = $load;
                        $changed = true;
                    }
                    if ($load == 2 && $scripts[$precedent]->load_mode == 2 && ($scripts[$precedent]->is_remote() or ! (bool) $conf['template_combine_files'])) {// we are async -> a predecessor cannot be async unlesss it can be merged; otherwise script execution order is not guaranteed
                        $scripts[$precedent]->load_mode = 1;
                        $changed = true;
                    }
                }
            }
        } while ($changed);
    }

    /**
     * Fill a script dependancies with the known jQuery UI scripts.
     *
     * @param string $id in FileCombiner::$known_paths
     */
    private static function fill_well_known($id, \Script $script): void
    {
        if (empty($script->path) && isset(self::$known_paths[$id])) {
            $script->path = self::$known_paths[$id];
        }
        if (str_starts_with($id, 'jquery.')) {
            $required_ids = ['jquery'];

            if (str_starts_with($id, 'jquery.ui.effect-')) {
                $required_ids = ['jquery', 'jquery.ui.effect'];

                if (empty($script->path)) {
                    $script->path = dirname(self::$known_paths['jquery.ui.effect']) . "/{$id}.min.js";
                }
            } elseif (str_starts_with($id, 'jquery.ui.')) {
                if (! isset(self::$ui_core_dependencies[$id])) {
                    $required_ids = array_merge(['jquery', 'jquery.ui'], array_keys(self::$ui_core_dependencies));
                }

                if (empty($script->path)) {
                    $script->path = dirname(self::$known_paths['jquery.ui']) . "/{$id}.min.js";
                }
            }

            foreach ($required_ids as $required_id) {
                if (! in_array($required_id, $script->precedents)) {
                    $script->precedents[] = $required_id;
                }
            }
        }
    }

    /**
     * Add a known jQuery UI script to loaded scripts.
     *
     * @param string $id in FileCombiner::$known_paths
     * @param int $load_mode
     */
    private function load_known_required_script($id, $load_mode): bool
    {
        if (isset(self::$known_paths[$id]) or str_starts_with($id, 'jquery.ui.')) {
            $this->add($id, $load_mode, [], null);
            return true;
        }
        return false;
    }

    /**
     * Compute script order depending on dependencies.
     * Assigned to $script->extra['order'].
     *
     * @param string $script_id
     */
    private function compute_script_topological_order($script_id, int $recursion_limiter = 0): int
    {
        if (! isset($this->registered_scripts[$script_id])) {
            trigger_error("Undefined script {$script_id} is required by someone", E_USER_WARNING);
            return 0;
        }
        $recursion_limiter < 5 or fatal_error('combined script circular dependency');
        $script = $this->registered_scripts[$script_id];
        if (isset($script->extra['order'])) {
            return $script->extra['order'];
        }
        if (count($script->precedents) == 0) {
            return $script->extra['order'] = 0;
        }
        $max = 0;
        foreach ($script->precedents as $precedent) {
            $max = max($max, $this->compute_script_topological_order($precedent, $recursion_limiter + 1));
        }
        $max++;
        return $script->extra['order'] = $max;
    }

    /**
     * Callback for scripts sorter.
     */
    private static function cmp_by_mode_and_order(Script $s1, Script $s2): int
    {
        // both scripts always went through compute_script_topological_order()
        // in the loop right before the uasort() call that uses this
        // comparator (get_head_scripts()/get_footer_scripts()), which always
        // sets extra['order'] before returning
        assert(isset($s1->extra['order']) && isset($s2->extra['order']));

        $ret = intval($s1->load_mode) - intval($s2->load_mode);
        if ((bool) $ret) {
            return $ret;
        }

        $ret = $s1->extra['order'] - $s2->extra['order'];
        if ((bool) $ret) {
            return $ret;
        }

        if ($s1->extra['order'] == 0 and ($s1->is_remote() xor $s2->is_remote())) {
            return $s1->is_remote() ? -1 : 1;
        }
        return strcmp($s1->id, $s2->id);
    }
}

/**
 * Allows merging of javascript and css files into a single one.
 */
final class FileCombiner
{
    private readonly bool $is_css;

    /**
     * @param string $type 'js' or 'css'
     * @param Combinable[] $combinables
     */
    public function __construct(
        private $type,
        private $combinables = []
    ) {
        $this->is_css = $this->type == 'css';
    }

    /**
     * Deletes all combined files from cache directory.
     */
    public static function clear_combined_files(): void
    {
        $dir = opendir(PHPWG_ROOT_PATH . PWG_COMBINED_DIR);
        if ($dir === false) {
            return;
        }
        while ((bool) ($file = readdir($dir))) {
            if (get_extension($file) == 'js' || get_extension($file) == 'css') {
                unlink(PHPWG_ROOT_PATH . PWG_COMBINED_DIR . $file);
            }
        }
        closedir($dir);
    }

    /**
     * @param Combinable|Combinable[] $combinable
     */
    public function add($combinable): void
    {
        if (is_array($combinable)) {
            $this->combinables = array_merge($this->combinables, $combinable);
        } else {
            $this->combinables[] = $combinable;
        }
    }

    /**
     * @return Combinable[]
     */
    public function combine(): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        $force = false;
        if (is_admin() && ($this->is_css || ! (bool) $conf['template_compile_check'])) {
            $force = (isset($_SERVER['HTTP_CACHE_CONTROL']) && is_string($_SERVER['HTTP_CACHE_CONTROL']) && str_contains($_SERVER['HTTP_CACHE_CONTROL'], 'max-age=0'))
              || (isset($_SERVER['HTTP_PRAGMA']) && is_string($_SERVER['HTTP_PRAGMA']) && (bool) strpos($_SERVER['HTTP_PRAGMA'], 'no-cache'));
        }

        $result = [];
        $pending = [];
        $ini_key = $this->is_css ? [get_absolute_root_url(false)] : []; // because for css we modify bg url;
        $key = $ini_key;

        foreach ($this->combinables as $combinable) {
            if ($combinable->is_remote()) {
                $this->flush_pending($result, $pending, $key, $force);
                $key = $ini_key;
                $result[] = $combinable;
                continue;
            }
            if (! (bool) $conf['template_combine_files']) {
                $this->flush_pending($result, $pending, $key, $force);
                $key = $ini_key;
            }

            $key[] = $combinable->path;
            $key[] = (string) $combinable->version;
            if ((bool) $conf['template_compile_check']) {
                $key[] = (string) filemtime(PHPWG_ROOT_PATH . $combinable->path);
            }
            $pending[] = $combinable;
        }
        $this->flush_pending($result, $pending, $key, $force);
        return $result;
    }

    /**
     * Process a set of pending files.
     *
     * @param Combinable[] $result
     * @param Combinable[] $pending
     * @param string[] $key
     */
    private function flush_pending(array &$result, array &$pending, array $key, bool $force): void
    {
        if (count($pending) > 1) {
            $key = join('>', $key);
            $file = PWG_COMBINED_DIR . base_convert(hash('crc32b', $key), 16, 36) . '.' . $this->type;
            if ($force || ! file_exists(PHPWG_ROOT_PATH . $file)) {
                $output = '';
                $header = '';
                foreach ($pending as $combinable) {
                    $output .= "/*BEGIN {$combinable->path} */\n";
                    $output .= $this->process_combinable($combinable, true, $force, $header);
                    $output .= "\n";
                }
                $output = "/*BEGIN header */\n" . $header . "\n" . $output;
                mkgetdir(dirname(PHPWG_ROOT_PATH . $file));
                file_put_contents(PHPWG_ROOT_PATH . $file, $output);
                @chmod(PHPWG_ROOT_PATH . $file, 0644);
            }
            $result[] = new Combinable('combi', $file, false);
        } elseif (count($pending) == 1) {
            $header = '';
            $this->process_combinable($pending[0], false, $force, $header);
            $result[] = $pending[0];
        }
        $key = [];
        $pending = [];
    }

    /**
     * Process one combinable file.
     *
     * @param Combinable $combinable
     * @param string $header CSS directives that must appear first in
     *                       the minified file (only used when
     *                       $return_content===true)
     */
    private function process_combinable($combinable, bool $return_content, bool $force, string &$header): ?string
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        if ($combinable->is_template) {
            if (! $return_content) {
                $key = [$combinable->path, $combinable->version];
                if ((bool) $conf['template_compile_check']) {
                    $key[] = filemtime(PHPWG_ROOT_PATH . $combinable->path);
                }
                $file = PWG_COMBINED_DIR . 't' . base_convert(hash('crc32b', implode(',', $key)), 16, 36) . '.' . $this->type;
                if (! $force && file_exists(PHPWG_ROOT_PATH . $file)) {
                    $combinable->path = $file;
                    $combinable->version = false;

                    return null;
                }
            }

            /** @var \Template $template */
            global $template;
            $handle = $this->type . '.' . $combinable->id;
            $real_path = realpath(PHPWG_ROOT_PATH . $combinable->path);
            if ($real_path === false) {
                throw new Exception("process_combinable(): file not found for {$combinable->path}");
            }
            $template->set_filename($handle, $real_path);
            trigger_notify('combinable_preparse', $template, $combinable, $this); // allow themes and plugins to set their own vars to template ...
            // parse($handle, true) is always string (never null) since we
            // always pass true here (see Template::parse()'s conditional
            // return type).
            $content = $template->parse($handle, true);

            if ($this->is_css) {
                $content = self::process_css($content, $combinable->path, $header);
            } else {
                $content = self::process_js($content);
            }

            if ($return_content) {
                return $content;
            }
            file_put_contents(PHPWG_ROOT_PATH . $file, $content);
            $combinable->path = $file;

            return null;
        }
        if ($return_content) {
            $content = file_get_contents(PHPWG_ROOT_PATH . $combinable->path);
            if ($content === false) {
                throw new Exception('do_combine(): unable to read ' . $combinable->path);
            }
            if ($this->is_css) {
                $content = self::process_css($content, $combinable->path, $header);
            } else {
                $content = self::process_js($content);
            }
            return $content;
        }

        return null;
    }

    /**
     * Process a JS file.
     *
     * @param string $js file content
     */
    private static function process_js($js): string
    {
        return trim($js, " \t\r\n;") . ";\n";
    }

    /**
     * Process a CSS file.
     *
     * @param string $css file content
     * @param string $file
     * @param string $header CSS directives that must appear first in
     *                       the minified file.
     */
    private static function process_css($css, $file, string &$header): string
    {
        $css = self::process_css_rec($css, dirname($file), $header);
        $css = trigger_change('combined_css_postfilter', $css);
        if (! is_string($css)) {
            throw new Exception("process_css(): a 'combined_css_postfilter' event listener returned a non-string value");
        }
        return $css;
    }

    /**
     * Resolves relative links in CSS file.
     *
     * @param string $css file content
     * @param string $header CSS directives that must appear first in
     *                       the minified file.
     * @return string
     */
    private static function process_css_rec($css, string $dir, &$header)
    {
        /** @var string */
        static $PATTERN_URL = "#url\(\s*['|\"]{0,1}(.*?)['|\"]{0,1}\s*\)#";
        /** @var string */
        static $PATTERN_IMPORT = "#@import\s*['|\"]{0,1}(.*?)['|\"]{0,1};#";

        if ((bool) preg_match_all($PATTERN_URL, $css, $matches, PREG_SET_ORDER)) {
            $search = $replace = [];
            foreach ($matches as $match) {
                if (! url_is_remote($match[1]) && $match[1][0] != '/' && ! str_contains($match[1], 'data:image/')) {
                    $relative = $dir . "/{$match[1]}";
                    $search[] = $match[0];
                    $replace[] = 'url(' . embellish_url(get_absolute_root_url(false) . $relative) . ')';
                }
            }
            $css = str_replace($search, $replace, $css);
        }

        if ((bool) preg_match_all($PATTERN_IMPORT, $css, $matches, PREG_SET_ORDER)) {
            $search = $replace = [];

            foreach ($matches as $match) {
                $search[] = $match[0];

                if (
                    str_contains($match[1], '..') // Possible attempt to get out of Piwigo's dir
                    or str_contains($match[1], '://') // Remote URL
                    or ! is_readable(PHPWG_ROOT_PATH . $dir . '/' . $match[1])
                ) {
                    // If anything is suspicious, don't try to process the
                    // @import. Since @import need to be first and we are
                    // concatenating several CSS files, remove it from here and return
                    // it through $header.
                    $header .= $match[0];
                    $replace[] = '';
                } else {
                    $sub_css = file_get_contents(PHPWG_ROOT_PATH . $dir . "/{$match[1]}");
                    if ($sub_css === false) {
                        throw new Exception('process_css_rec(): unable to read ' . $dir . "/{$match[1]}");
                    }
                    $replace[] = self::process_css_rec($sub_css, dirname($dir . "/{$match[1]}"), $header);
                }
            }
            $css = str_replace($search, $replace, $css);
        }
        return $css;
    }
}
