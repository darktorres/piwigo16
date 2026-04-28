<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Image\ImageStdParams;
use Smarty\Smarty;

/** default rank for buttons */
define('BUTTONS_RANK_NEUTRAL', 50);

/**
 * This a wrapper arround Smarty classes proving various custom mechanisms for templates.
 */
class Template
{
    /** @var Smarty */
    public $smarty;
    /** @var string */
    public $output = '';

    /** @var string[] - Hash of filenames for each template handle. */
    public $files = [];
    /** @var string[] - Template extents filenames for each template handle. */
    public $extents = [];
    /** @var array - Templates prefilter from external sources (plugins) */
    public $external_filters = [];

    /** @var array - Content to add before </head> tag */
    public $html_head_elements = [];
    /** @var string - Runtime CSS rules */
    private string $html_style = '';

    /** @const string */
    public const COMBINED_SCRIPTS_TAG = '<!-- COMBINED_SCRIPTS -->';
    /** @var ScriptLoader */
    public $scriptLoader;

    /** @const string */
    public const COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';
    /** @var CssLoader */
    public $cssLoader;

    /** @var array - Runtime buttons on picture page */
    public $picture_buttons = [];
    /** @var array - Runtime buttons on index page */
    public $index_buttons = [];


    /**
     * @param string $root
     * @param string $theme
     * @param string $path
     */
    public function __construct($root = '.', $theme = '', string $path = 'template')
    {
        global $lang_info;

        // \Smarty\Exception::$escape = false;

        $this->scriptLoader = new ScriptLoader();
        $this->cssLoader = new CssLoader();
        $this->smarty = new Smarty();
        $this->smarty->escape_html = false;
        $this->smarty->debugging = \Piwigo\Core\Config::debugTemplate();
        if (!$this->smarty->debugging) {
            $this->smarty->error_reporting = error_reporting() & ~E_NOTICE;
        }
        $this->smarty->compile_check = (int)\Piwigo\Core\Config::templateCompileCheck();
        $this->smarty->force_compile = \Piwigo\Core\Config::templateForceCompile();

        if (!\Piwigo\Core\Config::has('data_dir_checked')) {
            $dir = PHPWG_ROOT_PATH.\Piwigo\Core\Config::dataLocation();
            mkgetdir($dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR);
            if (!is_writable($dir)) {
                load_language('admin.lang');
                fatal_error(
                    l10n(
                        'Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation',
                        \Piwigo\Core\Config::dataLocation()
                    ),
                    l10n('an error happened'),
                    false // show trace
                );
            }
            if (function_exists('pwg_query')) {
                conf_update_param('data_dir_checked', 1);
            }
        }

        $compile_dir = PHPWG_ROOT_PATH.\Piwigo\Core\Config::dataLocation().'templates_c';
        mkgetdir($compile_dir);

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

        if (\Piwigo\Core\Config::compiledTemplateCacheLanguage()) {
            $this->smarty->registerFilter('post', self::postfilter_language(...));
        }

        $this->smarty->setTemplateDir([]);
        if (!empty($theme)) {
            $this->set_theme($root, $theme, $path);
            if (!defined('IN_ADMIN')) {
                $this->set_prefilter('header', [self::class, 'prefilter_local_css']);
            }
        } else {
            $this->set_template_dir($root);
        }

        if (isset($lang_info['code']) and !isset($lang_info['jquery_code'])) {
            $lang_info['jquery_code'] = $lang_info['code'];
        }

        if (isset($lang_info['jquery_code']) and !isset($lang_info['plupload_code'])) {
            $lang_info['plupload_code'] = str_replace('-', '_', $lang_info['jquery_code']);
        }

        $this->smarty->assign('lang_info', $lang_info);

        if (!defined('IN_ADMIN') and \Piwigo\Core\Config::extentsForTemplates() !== null) {
            $tpl_extents = unserialize(\Piwigo\Core\Config::extentsForTemplates() ?? '');
            $this->set_extents($tpl_extents, './template-extension/', true, $theme);
        }
    }

    /**
     * Loads theme's parameters.
     *
     * @param string $theme
     * @param bool $load_css
     * @param bool $load_local_head
     */
    public function set_theme(string $root, $theme, string $path, $load_css = true, $load_local_head = true, $colorscheme = 'dark'): void
    {
        //we need themeconf before std_pgs to see what themes use_standard_pages
        $themeconf = $this->load_themeconf($root.'/'.$theme);

        // We loop over the theme and the parent theme, so if we exclude default,
        // standard pages can't get the header to load the html header
        if (
            'default' != $theme
            and in_array(script_basename(), ['identification', 'register', 'password', 'profile'])
            and (($themeconf['use_standard_pages'] ?? false) or conf_get_param('use_standard_pages', false))
        ) {
            $theme = 'standard_pages';
            $themeconf = $this->load_themeconf($root.'/'.$theme);
        }

        $this->set_template_dir($root.'/'.$theme.'/'.$path);

        if (isset($themeconf['parent']) and $themeconf['parent'] != $theme) {
            $this->set_theme(
                $root,
                $themeconf['parent'],
                $path,
                $themeconf['load_parent_css'] ?? $load_css,
                $themeconf['load_parent_local_head'] ?? $load_local_head
            );
        }

        $tpl_var = [
          'id' => $theme,
          'load_css' => $load_css,
        ];
        if (!empty($themeconf['local_head']) and $load_local_head) {
            $tpl_var['local_head'] = realpath($root.'/'.$theme.'/'.$themeconf['local_head']);
        }
        $themeconf['id'] = $theme;

        if (!isset($themeconf['colorscheme'])) {
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

        if (!isset($this->smarty->compile_id)) {
            $compile_id = '1';
            $compile_id .= ($real_dir = realpath($dir)) === false ? $dir : $real_dir;
            $this->smarty->compile_id = base_convert(hash('crc32b', $compile_id), 16, 36);
        }
    }

    /**
     * Gets the template root directory for this Template object.
     *
     * @return string
     */
    public function get_template_dir()
    {
        return $this->smarty->getTemplateDir();
    }

    /**
     * Deletes all compiled templates.
     */
    public function delete_compiled_templates(): void
    {
        $save_compile_id = $this->smarty->compile_id;
        $this->smarty->compile_id = '';
        $this->smarty->clearCompiledTemplate();
        $this->smarty->compile_id = $save_compile_id;
        file_put_contents($this->smarty->getCompileDir().'/index.htm', 'Not allowed!');
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
        return $tc[$val] ?? '';
    }

    /**
     * Sets the template filename for handle.
     *
     * @param string $handle
     * @param string $filename
     */
    public function set_filename($handle, $filename): bool
    {
        return $this->set_filenames([$handle => $filename]);
    }

    /**
     * Sets the template filenames for handles.
     *
     * @param string[] $filename_array hashmap of handle=>filename
     * @return bool
     */
    public function set_filenames($filename_array): bool
    {
        if (!is_array($filename_array)) {
            return false;
        }
        reset($filename_array);
        foreach ($filename_array as $handle => $filename) {
            if (is_null($filename)) {
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
     * @param bool $overwrite
     * @param string $theme
     */
    public function set_extent($filename, $param, string $dir = '', $overwrite = true, $theme = 'N/A'): bool
    {
        return $this->set_extents([$filename => $param], $dir, $overwrite);
    }

    /**
     * Sets template extentions filenames for handles.
     *
     * @param string[] $filename_array hashmap of handle=>filename
     * @param bool $overwrite
     * @param string $theme
     */
    public function set_extents($filename_array, string $dir = '', $overwrite = true, $theme = 'N/A'): bool
    {
        if (!is_array($filename_array)) {
            return false;
        }
        foreach ($filename_array as $filename => $value) {
            if (is_array($value)) {
                $handle = $value[0];
                $param = $value[1];
                $thm = $value[2];
            } elseif (is_string($value)) {
                $handle = $value;
                $param = 'N/A';
                $thm = 'N/A';
            } else {
                return false;
            }

            if ((stripos(implode('', array_keys($_GET)), '/'.$param) !== false or $param == 'N/A')
              and ($thm == $theme or $thm == 'N/A')
              and (!isset($this->extents[$handle]) or $overwrite)
              and file_exists($dir . $filename)) {
                $this->extents[$handle] = realpath($dir . $filename);
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
     * @param string|array $tpl_var can be a var name or a hashmap of variables
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
     * @param string $handle
     * @return true
     */
    public function assign_var_from_handle($varname, $handle): bool
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
     */
    public function concat($tpl_var, string $value): void
    {
        $this->assign(
            $tpl_var,
            $this->smarty->getTemplateVars($tpl_var) . $value
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
    public function get_template_vars($tpl_var = null)
    {
        return $this->smarty->getTemplateVars($tpl_var);
    }

    /**
     * Loads the template file of the handle, compiles it and appends the result to the output
     * (or returns it if _$return_ is true).
     *
     * @param string $handle
     * @param bool $return
     * @return null|string
     */
    public function parse($handle, $return = false)
    {
        if (!isset($this->files[$handle])) {
            fatal_error("Template->parse(): Couldn't load template file for handle $handle");
        }

        $this->smarty->assign('ROOT_URL', get_root_url());

        $save_compile_id = $this->smarty->compile_id;
        $this->load_external_filters($handle);

        global $lang_info;
        if (\Piwigo\Core\Config::compiledTemplateCacheLanguage() and isset($lang_info['code'])) {
            $this->smarty->compile_id .= '_'.$lang_info['code'];
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
     *
     * @param string $handle
     */
    public function pparse($handle): void
    {
        $this->parse($handle, false);
        $this->flush();
    }

    /**
     * Load and compile JS & CSS into the template and sends the output to the browser.
     */
    public function flush(): void
    {
        if (!$this->scriptLoader->did_head()) {
            $pos = strpos($this->output, self::COMBINED_SCRIPTS_TAG);
            if ($pos !== false) {
                $scripts = $this->scriptLoader->get_head_scripts();
                $content = [];
                foreach ($scripts as $script) {
                    $content[] =
                        '<script type="text/javascript" src="'
                        . self::make_script_src($script)
                        .'"></script>';
                }

                $this->output = substr_replace($this->output, implode("\n", $content), $pos, strlen(self::COMBINED_SCRIPTS_TAG));
            } //else maybe error or warning ?
        }

        $css = $this->cssLoader->get_css();

        $content = [];
        foreach ($css as $combi) {
            $href = embellish_url(get_root_url().$combi->path);
            if ($combi->version !== false) {
                $href .= '?v' . ($combi->version ?: PHPWG_VERSION);
            }
            // trigger the event for eventual use of a cdn
            $href = trigger_change('combined_css', $href, $combi);
            $content[] = '<link rel="stylesheet" type="text/css" href="'.$href.'">';
        }
        $this->output = str_replace(
            self::COMBINED_CSS_TAG,
            implode("\n", $content),
            $this->output
        );
        $this->cssLoader->clear();

        if (count($this->html_head_elements) || strlen($this->html_style)) {
            $search = "\n</head>";
            $pos = strpos($this->output, $search);
            if ($pos !== false) {
                $rep = "\n".implode("\n", $this->html_head_elements);
                if (strlen($this->html_style)) {
                    $rep .= '<style type="text/css">'.$this->html_style.'</style>';
                }
                $this->output = substr_replace($this->output, $rep, $pos, 0);
            } //else maybe error or warning ?
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

        if ($this->smarty->debugging) {
            global $t2;
            $this->smarty->assign(
                [
        'AAAA_DEBUG_TOTAL_TIME__' => get_elapsed_time($t2, get_moment()),
        ]
            );
            (new \Smarty\Debug())->display_debug($this->smarty);
        }
    }

    /**
     * Eval a temp string to retrieve the original PHP value.
     *
     * @return mixed
     */
    public static function get_php_str_val($str)
    {
        if (is_string($str) && strlen($str) > 1) {
            if (($str[0] == '\'' && $str[strlen($str) - 1] == '\'')
              || ($str[0] == '"' && $str[strlen($str) - 1] == '"')) {
                $tmp = null;
            eval('$tmp='.$str.';');
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
     */
    public static function modcompiler_translate(array $params): string
    {
        switch (count($params)) {
            case 1:
                if (\Piwigo\Core\Config::compiledTemplateCacheLanguage()
                  && ($key = self::get_php_str_val($params[0])) !== null
                  && \Piwigo\Core\Lang::has($key)
                ) {
                    return var_export(\Piwigo\Core\Lang::t($key), true);
                }
                return 'l10n('.$params[0].')';

            default:
                if (\Piwigo\Core\Config::compiledTemplateCacheLanguage()) {
                    $ret = 'sprintf(';
                    $ret .= self::modcompiler_translate([$params[0]]);
                    $ret .= ','. implode(',', array_slice($params, 1));
                    $ret .= ')';
                    return $ret;
                }
                return 'l10n('.$params[0].','.implode(',', array_slice($params, 1)).')';
        }
    }

    /**
     * "translate_dec" variable modifier.
     * Usage :
     *    - {$count|translate_dec:'%d comment':'%d comments'}
     * @see l10n_dec()
     */
    public static function modcompiler_translate_dec(array $params): string
    {
        global $lang_info;
        if (\Piwigo\Core\Config::compiledTemplateCacheLanguage()) {
            $ret = 'sprintf(';
            if ($lang_info['zero_plural']) {
                $ret .= '($tmp=('.$params[0].'))>1||$tmp==0';
            } else {
                $ret .= '($tmp=('.$params[0].'))>1';
            }
            $ret .= '?';
            $ret .= self::modcompiler_translate([$params[2]]);
            $ret .= ':';
            $ret .= self::modcompiler_translate([$params[1]]);
            $ret .= ',$tmp';
            $ret .= ')';
            return $ret;
        }
        return 'l10n_dec('.$params[1].','.$params[2].','.$params[0].')';
    }

    /**
     * "explode" variable modifier.
     * Usage :
     *    - {assign var=valueExploded value=$value|explode:','}
     *
     * @param string $text
     * @param string $delimiter
     */
    public static function mod_explode($text, $delimiter = ','): array
    {
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
        return $param ? $true : $false;
    }

    /**
     * The "html_head" block allows to add content just before
     * </head> element in the output after the head has been parsed.
     *
     * @param array $params (unused)
     * @param string $content
     */
    public function block_html_head($params, $content): void
    {
        $content = isset($content) ? trim($content) : '';
        if (!empty($content)) { // second call
            $this->html_head_elements[] = $content;
        }
    }

    /**
     * The "html_style" block allows to add CSS juste before
     * </head> element in the output after the head has been parsed.
     *
     * @param array $params (unused)
     * @param string $content
     */
    public function block_html_style($params, $content): void
    {
        $content = isset($content) ? trim($content) : '';
        if (!empty($content)) { // second call
            $this->html_style .= "\n".$content;
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
     * @param Smarty $smarty
     */
    public function func_define_derivative(array $params, $smarty): void
    {
        !empty($params['name']) or fatal_error('define_derivative missing name');
        if (isset($params['type'])) {
            $derivative = ImageStdParams::get_by_type($params['type']);
            $smarty->assign($params['name'], $derivative);
            return;
        }
        !empty($params['width']) or fatal_error('define_derivative missing width');
        !empty($params['height']) or fatal_error('define_derivative missing height');

        $w = intval($params['width']);
        $h = intval($params['height']);
        $crop = 0;
        $minw = null;
        $minh = null;

        if (isset($params['crop'])) {
            if (is_bool($params['crop'])) {
                $crop = $params['crop'] ? 1 : 0;
            } else {
                $crop = round($params['crop'] / 100, 2);
            }

            if ($crop) {
                $minw = empty($params['min_width']) ? $w : intval($params['min_width']);
                $minw <= $w or fatal_error('define_derivative invalid min_width');
                $minh = empty($params['min_height']) ? $h : intval($params['min_height']);
                $minh <= $h or fatal_error('define_derivative invalid min_height');
            }
        }

        $smarty->assign($params['name'], ImageStdParams::get_custom($w, $h, $crop, $minw, $minh));
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
     */
    public function func_combine_script(array $params): void
    {
        if (!isset($params['id'])) {
            trigger_error("combine_script: missing 'id' parameter", E_USER_ERROR);
        }
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

        $this->scriptLoader->add(
            $params['id'],
            $load,
            empty($params['require']) ? [] : explode(',', (string) $params['require']),
            @$params['path'],
            $params['version'] ?? 0,
            @$params['template']
        );
    }

    /**
     * The "get_combined_scripts" function returns HTML tag of combined scripts.
     * It can returns a placeholder for delayed JS files combination and minification.
     *
     * @param array $params
     *    - load (required)
     */
    public function func_get_combined_scripts(array $params): string
    {
        if (!isset($params['load'])) {
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
                  .'"></script>';
            }
            if (count($this->scriptLoader->inline_scripts)) {
                $content[] = '<script type="text/javascript">//<![CDATA[
';
                $content = array_merge($content, $this->scriptLoader->inline_scripts);
                $content[] = '//]]></script>';
            }

            if (count($scripts[1])) {
                $content[] = '<script type="text/javascript">';
                $content[] = '(function() {
var s,after = document.getElementsByTagName(\'script\')[document.getElementsByTagName(\'script\').length-1];';
                foreach ($scripts[1] as $id => $script) {
                    $content[] =
                      's=document.createElement(\'script\'); s.type=\'text/javascript\'; s.async=true; s.src=\''
                      . self::make_script_src($script)
                      .'\';';
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
     * @return string
     */
    private static function make_script_src($script): string|array
    {
        $ret = '';
        if ($script->is_remote()) {
            $ret = $script->path;
        } else {
            $ret = get_root_url().$script->path;
            if ($script->version !== false) {
                $ret .= '?v'. ($script->version ?: PHPWG_VERSION);
            }
        }
        // trigger the event for eventual use of a cdn
        $ret = trigger_change('combined_script', $ret, $script);
        return embellish_url($ret);
    }

    /**
     * The "footer_script" block allows to add runtime script in the HTML page.
     *
     * @param array $params
     *    - require (optional) comma separated list of script ids
     * @param string $content
     */
    public function block_footer_script(array $params, $content): void
    {
        $content = isset($content) ? trim($content) : '';
        if (!empty($content)) { // second call

            $this->scriptLoader->add_inline(
                $content,
                empty($params['require']) ? [] : explode(',', (string) $params['require'])
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
     */
    public function func_combine_css(array $params): void
    {
        if (empty($params['path'])) {
            fatal_error('combine_css missing path');
        }

        if (!isset($params['id'])) {
            $params['id'] = md5((string) $params['path']);
        }

        $this->cssLoader->add($params['id'], $params['path'], $params['version'] ?? 0, (int)@$params['order'], (bool)@$params['template']);
    }

    /**
     * The "get_combined_scripts" function returns a placeholder for delayed
     * CSS files combination and minification.
     *
     * @param array $params (unused)
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
     * @param Callable $callback
     * @param int $weight
     */
    public function set_prefilter($handle, $callback, $weight = 50): void
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
     * @param Callable $callback
     * @param int $weight
     */
    public function set_postfilter($handle, $callback, $weight = 50): void
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
     * @param Callable $callback
     * @param int $weight
     */
    public function set_outputfilter($handle, $callback, $weight = 50): void
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
                        $compile_id .= $type.implode('', $callback);
                    } elseif ($callback instanceof \Closure) {
                        // Reflect the closure's function name for a stable compile_id contribution.
                        $compile_id .= $type.new \ReflectionFunction($callback)->getName();
                    } else {
                        $compile_id .= $type.$callback;
                    }
                    $this->smarty->registerFilter($type, $callback);
                }
            }
            $this->smarty->compile_id .= '.'.base_convert(hash('crc32b', $compile_id), 16, 36);
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
    public static function prefilter_white_space($source, $smarty): string|array|null
    {
        $ld = $smarty->getLeftDelimiter();
        $rd = $smarty->getRightDelimiter();
        // $ld = $smarty->left_delimiter;
        // $rd = $smarty->right_delimiter;
        $ldq = preg_quote($ld, '#');
        $rdq = preg_quote($rd, '#');

        $regex = [];
        $tags = ['if','foreach','section','footer_script'];
        foreach ($tags as $tag) {
            $regex[] = "#^[ \t]+($ldq$tag"."[^$ld$rd]*$rdq)\s*$#m";
            $regex[] = "#^[ \t]+($ldq/$tag$rdq)\s*$#m";
        }
        $tags = ['include','else','combine_script','html_head'];
        foreach ($tags as $tag) {
            $regex[] = "#^[ \t]+($ldq$tag"."[^$ld$rd]*$rdq)\s*$#m";
        }
        $source = preg_replace($regex, '$1', $source);
        return $source;
    }

    /**
     * Postfilter used when \Piwigo\Core\Config::compiledTemplateCacheLanguage() is true.
     *
     * @param string $source
     * @param Smarty $smarty
     */
    public static function postfilter_language($source, $smarty): string|array|null
    {
        // replaces echo PHP_STRING_LITERAL; with the string literal value
        $source = preg_replace_callback(
            '/\\<\\?php echo ((?:\'(?:(?:\\\\.)|[^\'])*\')|(?:"(?:(?:\\\\.)|[^"])*"));\\?\\>\\n/',
            function (array $matches) {
                $tmp = null;
                eval('$tmp='.$matches[1].';');
                return $tmp;
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
     */
    public static function prefilter_local_css($source, $smarty)
    {
        $css = [];
        foreach ($smarty->getTemplateVars('themes') as $theme) {
            $f = PWG_LOCAL_DIR.'css/'.$theme['id'].'-rules.css';
            if (file_exists(PHPWG_ROOT_PATH.$f)) {
                $css[] = "{combine_css path='$f' order=10}";
            }
        }
        $f = PWG_LOCAL_DIR.'css/rules.css';
        if (file_exists(PHPWG_ROOT_PATH.$f)) {
            $css[] = "{combine_css path='$f' order=10}";
        }

        if (!empty($css)) {
            $source = str_replace('{get_combined_css}', implode("\n", $css)."\n{get_combined_css}", $source);
        }

        return $source;
    }

    /**
     * Loads the configuration file from a theme directory and returns it.
     *
     * @param string $dir
     * @return array
     */
    public function load_themeconf($dir)
    {
        global $themeconfs;

        $dir = realpath($dir);
        if (!isset($themeconfs[$dir])) {
            $themeconf = [];
            include($dir.'/themeconf.inc.php');
            // Put themeconf in cache
            $themeconfs[$dir] = $themeconf;
        }
        return $themeconfs[$dir];
    }

    /**
     * Registers a button to be displayed on picture page.
     *
     * @param string $content
     * @param int $rank
     */
    public function add_picture_button($content, $rank = BUTTONS_RANK_NEUTRAL): void
    {
        $this->picture_buttons[$rank][] = $content;
    }

    /**
     * Registers a button to be displayed on index pages.
     *
     * @param string $content
     * @param int $rank
     */
    public function add_index_button($content, $rank = BUTTONS_RANK_NEUTRAL): void
    {
        $this->index_buttons[$rank][] = $content;
    }

    /**
     * Assigns PLUGIN_PICTURE_BUTTONS template variable with registered picture buttons.
     */
    public function parse_picture_buttons(): void
    {
        if (!empty($this->picture_buttons)) {
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
        if (!empty($this->index_buttons)) {
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
