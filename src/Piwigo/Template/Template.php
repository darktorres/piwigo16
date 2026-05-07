<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageStdParams;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\PermissionService;
use Smarty\Debug;
use Smarty\Smarty;
use Smarty\TemplateBase;

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
    /** @var array<string, array<int, list<array{0: string, 1: mixed}>>> - Templates prefilter from external sources (plugins) */
    public array $external_filters = [];

    /** @var string[] - Content to add before </head> tag */
    public array $html_head_elements = [];
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

    /** @var array<int, list<mixed>> - Runtime buttons on picture page */
    public array $picture_buttons = [];
    /** @var array<int, list<mixed>> - Runtime buttons on index page */
    public array $index_buttons = [];


    /**
     * @param string $root
     * @param string $theme
     */
    public function __construct($root = '.', $theme = '', string $path = 'template')
    {
        $lang_info = is_array($GLOBALS['lang_info'] ?? null) ? $GLOBALS['lang_info'] : [];

        // \Smarty\Exception::$escape = false;

        $this->scriptLoader = new ScriptLoader();
        $this->cssLoader = new CssLoader();
        $this->smarty = new Smarty();
        $this->smarty->escape_html = false;
        $this->smarty->debugging = Config::debugTemplate();
        if (!$this->smarty->debugging) {
            $this->smarty->error_reporting = error_reporting() & ~E_NOTICE;
        }
        $this->smarty->compile_check = (int)Config::templateCompileCheck();
        $this->smarty->force_compile = Config::templateForceCompile();

        if (!Config::has('data_dir_checked')) {
            $dir = PHPWG_ROOT_PATH.Config::dataLocation();
            mkgetdir($dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR);
            if (!is_writable($dir)) {
                load_language('admin.lang');
                fatal_error(
                    l10n(
                        'Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation',
                        Config::dataLocation()
                    ),
                    l10n('an error happened'),
                    false // show trace
                );
            }
            if (Config::dbName() !== '') {
                conf_update_param('data_dir_checked', 1);
            }
        }

        $compile_dir = PHPWG_ROOT_PATH.Config::dataLocation().'templates_c';
        mkgetdir($compile_dir);

        $this->smarty->setCompileDir($compile_dir);

        $this->smarty->assign('pwg', new PwgTemplateAdapter());
        $this->smarty->registerPlugin('modifiercompiler', 'translate', self::modcompilerTranslate(...));
        $this->smarty->registerPlugin('modifiercompiler', 'translate_dec', self::modcompilerTranslateDec(...));
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
        $this->smarty->registerPlugin('modifier', 'url_is_remote', 'url_is_remote');
        $this->smarty->registerPlugin('modifier', 'is_null', 'is_null');
        $this->smarty->registerPlugin('modifier', 'l10n', Lang::t(...));
        $this->smarty->registerPlugin('modifier', 'str_replace', 'str_replace');
        $this->smarty->registerPlugin('modifier', 'is_admin', fn (string $s = ''): bool => PermissionService::get()->isAdmin($s));
        $this->smarty->registerPlugin('modifier', 'is_classic_user', fn (string $s = ''): bool => PermissionService::get()->isClassicUser($s));
        $this->smarty->registerPlugin('modifier', 'get_device', 'get_device');
        $this->smarty->registerPlugin('modifier', 'is_file', 'is_file');
        $this->smarty->registerPlugin('modifier', 'strpos', 'strpos');
        $this->smarty->registerPlugin('modifier', 'preg_match', 'preg_match');
        $this->smarty->registerPlugin('modifier', 'get_gallery_home_url', fn (mixed ...$_): string => ServiceLocator::get(UrlGenerator::class)->gallery());
        $this->smarty->registerPlugin('modifier', 'sizeOf', 'sizeOf');
        $this->smarty->registerPlugin('modifier', 'array_key_exists', 'array_key_exists');

        if (Config::compiledTemplateCacheLanguage()) {
            $this->smarty->registerFilter('post', self::postfilterLanguage(...));
        }

        $this->smarty->setTemplateDir([]);
        if (!empty($theme)) {
            $this->setTheme($root, $theme, $path);
            if (!defined('IN_ADMIN')) {
                $this->setPrefilter('header', [self::class, 'prefilterLocalCss']);
            }
        } else {
            $this->setTemplateDir($root);
        }

        $this->smarty->assign('lang_info', $lang_info);

        if (!defined('IN_ADMIN') and Config::extentsForTemplates() !== null) {
            $tpl_extents = unserialize((string)Config::extentsForTemplates());
            $this->setExtents(is_array($tpl_extents) ? $tpl_extents : [], './template-extension/', true, $theme);
        }
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
            and in_array(script_basename(), ['identification', 'register', 'password', 'profile'])
            and (($themeconf['use_standard_pages'] ?? false) or conf_get_param('use_standard_pages', false))
        ) {
            $theme = 'standard_pages';
            $themeconf = $this->loadThemeconf($root.'/'.$theme);
        }

        $this->setTemplateDir($root.'/'.$theme.'/'.$path);

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

        $this->smarty->append('themes', $tpl_var);
        $this->smarty->append('themeconf', $themeconf, true);
    }

    /**
     * Adds template directory for this Template object.
     * Also set compile id if not exists.
     *
     * @param string $dir
     */
    public function setTemplateDir($dir): void
    {
        $this->smarty->addTemplateDir($dir);

        if ($this->smarty->compile_id === '') {
            $compile_id = '1';
            $compile_id .= ($real_dir = realpath($dir)) === false ? $dir : $real_dir;
            $this->smarty->compile_id = base_convert(hash('crc32b', $compile_id), 16, 36);
        }
    }

    /**
     * Gets the template root directory for this Template object.
     *
     * @return string|array<string>
     */
    public function getTemplateDir(): string|array
    {
        return $this->smarty->getTemplateDir();
    }

    /**
     * Deletes all compiled templates.
     */
    public function deleteCompiledTemplates(): void
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
     */
    public function getThemeconf($val): mixed
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
    public function setFilename($handle, $filename): bool
    {
        return $this->setFilenames([$handle => $filename]);
    }

    /**
     * Sets the template filenames for handles.
     *
     * @param string[] $filename_array hashmap of handle=>filename
     */
    public function setFilenames($filename_array): bool
    {
        reset($filename_array);
        foreach ($filename_array as $handle => $filename) {
            $this->files[$handle] = $this->getExtent($filename, $handle);
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
    public function setExtent($filename, $param, string $dir = '', $overwrite = true, $theme = 'N/A'): bool
    {
        return $this->setExtents([$filename => $param], $dir, $overwrite);
    }

    /**
     * Sets template extentions filenames for handles.
     *
     * @param array<mixed> $filename_array hashmap of handle=>filename
     * @param bool $overwrite
     * @param string $theme
     */
    public function setExtents($filename_array, string $dir = '', $overwrite = true, $theme = 'N/A'): bool
    {
        foreach ($filename_array as $filename => $value) {
            if (is_array($value)) {
                $h = $value[0];
                $handle = is_string($h) ? $h : '';
                $p = $value[1];
                $param = is_string($p) ? $p : 'N/A';
                $t = $value[2];
                $thm = is_string($t) ? $t : 'N/A';
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
                $rp = realpath($dir . $filename);
                if ($rp !== false) {
                    $this->extents[$handle] = $rp;
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
    public function getExtent($filename = '', $handle = '')
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
    /** @param string|array<string,mixed> $tpl_var */
    public function assign(string|array $tpl_var, mixed $value = null): void
    {
        $this->smarty->assign($tpl_var, $value);
    }

    /**
     * Defines _$varname_ as the compiled result of _$handle_.
     * This can be used to effectively include a template in another template.
     * This is equivalent to assign($varname, $this->parse($handle, true)).
     *
     * @param string|string[] $varname
     * @param string $handle
     * @return true
     */
    public function assignVarFromHandle(string|array $varname, $handle): bool
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
        $existing = $this->smarty->getTemplateVars($tpl_var);
        $existingStr = is_string($existing) ? $existing : '';
        $this->assign(
            $tpl_var,
            $existingStr . $value
        );
    }

    /**
     * Removes an assigned template variable.
     * @see http://www.smarty.net/manual/en/api.clear_assign.php
     *
     * @param string $tpl_var
     */
    public function clearAssign($tpl_var): void
    {
        $this->smarty->clearAssign($tpl_var);
    }

    /**
     * Returns an assigned template variable.
     * @see http://www.smarty.net/manual/en/api.get_template_vars.php
     *
     * @param string $tpl_var
     */
    /** @return array<mixed>|mixed */
    public function getTemplateVars(?string $tpl_var = null): mixed
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
        $wsBase = ServiceLocator::get(UrlGenerator::class)->ws();
        $this->smarty->assign('WS_URL', $wsBase . (str_contains($wsBase, '?') ? '&' : '?'));
        $this->smarty->assign('U_SEARCH', ServiceLocator::get(UrlGenerator::class)->searchPage());

        $save_compile_id = $this->smarty->compile_id;
        $this->loadExternalFilters($handle);

        $lang_info = is_array($GLOBALS['lang_info'] ?? null) ? $GLOBALS['lang_info'] : [];
        if (Config::compiledTemplateCacheLanguage() and isset($lang_info['code']) && is_scalar($lang_info['code'])) {
            $this->smarty->compile_id .= '_'.$lang_info['code'];
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
            } //else maybe error or warning ?
        }

        $css = $this->cssLoader->getCss();

        $content = [];
        foreach ($css as $combi) {
            $href = embellish_url(get_root_url().$combi->path);
            if (!is_string($href)) {
                $href = get_root_url().$combi->path;
            }
            $href .= '?v' . ($combi->version ?: AppInfo::VERSION);
            // trigger the event for eventual use of a cdn
            $href = EventDispatcher::dispatch('combined_css', $href, $combi);
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
            $t2 = is_numeric($GLOBALS['t2'] ?? null) ? (float) $GLOBALS['t2'] : 0.0;
            $this->smarty->assign(
                [
        'AAAA_DEBUG_TOTAL_TIME__' => get_elapsed_time($t2, get_moment()),
        ]
            );
            new Debug()->display_debug($this->smarty);
        }
    }

    /**
     * Eval a temp string to retrieve the original PHP value.
     */
    public static function getPhpStrVal(string $str): ?string
    {
        if (strlen($str) > 1) {
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
    /** @param array<mixed> $params */
    public static function modcompilerTranslate(array $params): string
    {
        $p0 = is_string($params[0] ?? null) ? (string) $params[0] : '';
        switch (count($params)) {
            case 1:
                if (Config::compiledTemplateCacheLanguage()
                  && ($key = self::getPhpStrVal($p0)) !== null
                  && Lang::has($key)
                ) {
                    return var_export(Lang::t($key), true);
                }
                return 'l10n('.$p0.')';

            default:
                $rest = array_slice($params, 1);
                $restStr = array_map(fn ($x): string => is_string($x) ? $x : (is_int($x) || is_float($x) ? (string) $x : ''), $rest);
                if (Config::compiledTemplateCacheLanguage()) {
                    $ret = 'sprintf(';
                    $ret .= self::modcompilerTranslate([$p0]);
                    $ret .= ','. implode(',', $restStr);
                    $ret .= ')';
                    return $ret;
                }
                return 'l10n('.$p0.','.implode(',', $restStr).')';
        }
    }

    /**
     * "translate_dec" variable modifier.
     * Usage :
     *    - {$count|translate_dec:'%d comment':'%d comments'}
     * @see l10n_dec()
     */
    /** @param array<mixed> $params */
    public static function modcompilerTranslateDec(array $params): string
    {
        $lang_info = is_array($GLOBALS['lang_info'] ?? null) ? $GLOBALS['lang_info'] : [];
        $p0 = is_string($params[0] ?? null) ? (string) $params[0] : '';
        $p1 = is_string($params[1] ?? null) ? (string) $params[1] : '';
        $p2 = is_string($params[2] ?? null) ? (string) $params[2] : '';
        if (Config::compiledTemplateCacheLanguage()) {
            $ret = 'sprintf(';
            if (!empty($lang_info['zero_plural'])) {
                $ret .= '($tmp=('.$p0.'))>1||$tmp==0';
            } else {
                $ret .= '($tmp=('.$p0.'))>1';
            }
            $ret .= '?';
            $ret .= self::modcompilerTranslate([$p2]);
            $ret .= ':';
            $ret .= self::modcompilerTranslate([$p1]);
            $ret .= ',$tmp';
            $ret .= ')';
            return $ret;
        }
        return 'l10n_dec('.$p1.','.$p2.','.$p0.')';
    }

    /**
     * "explode" variable modifier.
     * Usage :
     *    - {assign var=valueExploded value=$value|explode:','}
     *
     * @param string $text
     * @param string $delimiter
     */
    /** @return string[] */
    public static function modExplode(string $text, string $delimiter = ','): array
    {
        return explode($delimiter ?: ',', $text);
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
    public static function modTernary($param, $true, $false)
    {
        return $param ? $true : $false;
    }

    /**
     * The "html_head" block allows to add content just before
     * </head> element in the output after the head has been parsed.
     *
     * @param array $params (unused)
     * @param string|null $content
     */
    /** @param array<mixed> $params */
    public function blockHtmlHead(array $params, string|null $content): void
    {
        $content = trim($content ?? '');
        if (!empty($content)) { // second call
            $this->html_head_elements[] = $content;
        }
    }

    /**
     * The "html_style" block allows to add CSS juste before
     * </head> element in the output after the head has been parsed.
     *
     * @param array $params (unused)
     * @param string|null $content
     */
    /** @param array<mixed> $params */
    public function blockHtmlStyle(array $params, string|null $content): void
    {
        $content = trim($content ?? '');
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
    /** @param array<mixed> $params */
    public function funcDefineDerivative(array $params, mixed $smarty): void
    {
        !empty($params['name']) or fatal_error('define_derivative missing name');
        if (isset($params['type'])) {
            $typeVal = $params['type'];
            $typeStr = is_string($typeVal) ? $typeVal : '';
            $derivative = ImageStdParams::getByType($typeStr);
            if ($smarty instanceof Smarty) {
                $nameVal = $params['name'];
                $nameStr = is_string($nameVal) ? $nameVal : '';
                $smarty->assign($nameStr, $derivative);
            }
            return;
        }
        !empty($params['width']) or fatal_error('define_derivative missing width');
        !empty($params['height']) or fatal_error('define_derivative missing height');

        $widthVal = $params['width'];
        $heightVal = $params['height'];
        $w = is_int($widthVal) ? $widthVal : (is_numeric($widthVal) ? (int) $widthVal : 0);
        $h = is_int($heightVal) ? $heightVal : (is_numeric($heightVal) ? (int) $heightVal : 0);
        $crop = 0;
        $minw = null;
        $minh = null;

        if (isset($params['crop'])) {
            $cropVal = $params['crop'];
            if (is_bool($cropVal)) {
                $crop = $cropVal ? 1 : 0;
            } elseif (is_int($cropVal) || is_float($cropVal)) {
                $crop = round($cropVal / 100, 2);
            } elseif (is_string($cropVal) && is_numeric($cropVal)) {
                $crop = round((float) $cropVal / 100, 2);
            }

            if ($crop) {
                $minWidthVal = $params['min_width'] ?? null;
                $minHeightVal = $params['min_height'] ?? null;
                $minw = empty($minWidthVal) ? $w : (is_int($minWidthVal) ? $minWidthVal : (is_numeric($minWidthVal) ? (int) $minWidthVal : $w));
                $minw <= $w or fatal_error('define_derivative invalid min_width');
                $minh = empty($minHeightVal) ? $h : (is_int($minHeightVal) ? $minHeightVal : (is_numeric($minHeightVal) ? (int) $minHeightVal : $h));
                $minh <= $h or fatal_error('define_derivative invalid min_height');
            }
        }

        if ($smarty instanceof Smarty) {
            $nameVal2 = $params['name'];
            $nameStr2 = is_string($nameVal2) ? $nameVal2 : '';
            $smarty->assign($nameStr2, ImageStdParams::getCustom($w, $h, $crop, $minw, $minh));
        }
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
    /** @param array<mixed> $params */
    public function funcCombineScript(array $params): void
    {
        if (!isset($params['id'])) {
            trigger_error("combine_script: missing 'id' parameter", E_USER_ERROR);
        }
        $load = match ($params['load'] ?? 'header') {
            'header' => 0,
            'footer' => 1,
            'async'  => 2,
            default  => throw new \ValueError("combine_script: invalid 'load' parameter"),
        };

        $scriptId = $params['id'];
        $scriptIdStr = is_string($scriptId) ? $scriptId : '';
        $scriptPath = $params['path'] ?? null;
        $scriptPathVal = is_string($scriptPath) ? $scriptPath : null;
        $scriptVersion = $params['version'] ?? 0;
        $scriptVersionVal = (is_string($scriptVersion) || is_int($scriptVersion)) ? $scriptVersion : 0;
        $scriptTemplate = $params['template'] ?? false;
        $scriptTemplateVal = (bool) $scriptTemplate;
        $requireRaw = $params['require'] ?? null;
        $requireArr = empty($requireRaw) ? [] : explode(',', is_string($requireRaw) ? $requireRaw : '');
        $this->scriptLoader->add(
            $scriptIdStr,
            $load,
            $requireArr,
            $scriptPathVal,
            $scriptVersionVal,
            $scriptTemplateVal
        );

        // Auto-register stylesheets bundled into this entry by Vite. The
        // manifest's "css" array enumerates side-effect CSS imports
        // (e.g. `import './tree.css'` inside an entry module); without
        // this, those styles wouldn't reach the page.
        $manifest = ScriptLoader::getManifest();
        $manifestEntry = ($manifest !== null && is_array($manifest[$scriptIdStr] ?? null)) ? $manifest[$scriptIdStr] : null;
        if ($manifestEntry !== null) {
            $cssList = is_array($manifestEntry['css'] ?? null) ? $manifestEntry['css'] : [];
            foreach ($cssList as $i => $cssPath) {
                $cssPathStr = is_scalar($cssPath) ? (string) $cssPath : '';
                if ($cssPathStr !== '') {
                    $this->cssLoader->add(
                        $scriptIdStr . '-vite-css-' . $i,
                        'dist/' . $cssPathStr,
                        $scriptVersionVal
                    );
                }
            }
        }
    }

    /**
     * The "get_combined_scripts" function returns HTML tag of combined scripts.
     * It can returns a placeholder for delayed JS files combination and minification.
     *
     * @param array $params
     *    - load (required)
     */
    /** @param array<mixed> $params */
    public function funcGetCombinedScripts(array $params): string
    {
        if (!isset($params['load'])) {
            trigger_error("get_combined_scripts: missing 'load' parameter", E_USER_ERROR);
        }
        $load = $params['load'] == 'header' ? 0 : 1;
        $content = [];

        if ($load == 0) {
            return self::COMBINED_SCRIPTS_TAG;
        } else {
            $scripts = $this->scriptLoader->getFooterScripts();
            foreach ($scripts[0] as $script) {
                $src0 = self::makeScriptSrc($script);
                $type = self::isModuleScript($script) ? 'module' : 'text/javascript';
                $content[] =
                  '<script type="' . $type . '" src="'
                  . (is_string($src0) ? $src0 : '')
                  .'"></script>';
            }
            if (count($this->scriptLoader->inline_scripts)) {
                $content[] = '<script type="module">';
                $content = array_merge($content, $this->scriptLoader->inline_scripts);
                $content[] = '</script>';
            }

            if (count($scripts[1])) {
                $content[] = '<script type="text/javascript">';
                $content[] = '(function() {
var s,after = document.getElementsByTagName(\'script\')[document.getElementsByTagName(\'script\').length-1];';
                foreach ($scripts[1] as $id => $script) {
                    $src1 = self::makeScriptSrc($script);
                    $stype = self::isModuleScript($script) ? 'module' : 'text/javascript';
                    $content[] =
                      's=document.createElement(\'script\'); s.type=\'' . $stype . '\'; s.async=true; s.src=\''
                      . (is_string($src1) ? $src1 : '')
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
     */
    private static function isModuleScript(Combinable $script): bool
    {
        return str_starts_with($script->path, 'dist/');
    }

    /** @return string|array<mixed> */
    private static function makeScriptSrc(Combinable $script): string|array
    {
        $ret = '';
        if ($script->isRemote()) {
            $ret = $script->path;
        } else {
            $ret = get_root_url().$script->path;
            $ret .= '?v'. ($script->version ?: AppInfo::VERSION);
        }
        // trigger the event for eventual use of a cdn
        $ret = EventDispatcher::dispatch('combined_script', $ret, $script);
        return embellish_url($ret);
    }

    /**
     * The "footer_script" block allows to add runtime script in the HTML page.
     *
     * @param array $params
     *    - require (optional) comma separated list of script ids
     * @param string|null $content
     */
    /** @param array<mixed> $params */
    public function blockFooterScript(array $params, string|null $content): void
    {
        $content = trim($content ?? '');
        if (!empty($content)) { // second call

            $requireFooter = $params['require'] ?? null;
            $requireFooterArr = empty($requireFooter) ? [] : explode(',', is_string($requireFooter) ? $requireFooter : '');
            $this->scriptLoader->addInline(
                $content,
                $requireFooterArr
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
    /** @param array<mixed> $params */
    public function funcCombineCss(array $params): void
    {
        if (empty($params['path'])) {
            fatal_error('combine_css missing path');
        }

        if (!isset($params['id'])) {
            $pathForId = $params['path'];
            $params['id'] = md5(is_string($pathForId) ? $pathForId : '');
        }

        $cssId = $params['id'];
        $cssIdStr = is_string($cssId) ? $cssId : '';
        $cssPath = $params['path'];
        $cssPathStr = is_string($cssPath) ? $cssPath : '';
        $cssVersion = $params['version'] ?? 0;
        $cssVersionVal = (is_string($cssVersion) || is_int($cssVersion)) ? $cssVersion : 0;
        $cssOrder = $params['order'] ?? 0;
        $cssOrderInt = is_int($cssOrder) ? $cssOrder : (is_numeric($cssOrder) ? (int) $cssOrder : 0);
        $cssTemplate = $params['template'] ?? false;
        $cssTemplateBool = (bool) $cssTemplate;
        $this->cssLoader->add($cssIdStr, $cssPathStr, $cssVersionVal, $cssOrderInt, $cssTemplateBool);
    }

    /**
     * The "get_combined_scripts" function returns a placeholder for delayed
     * CSS files combination and minification.
     *
     * @param array $params (unused)
     */
    /** @param array<mixed> $params */
    public function funcGetCombinedCss(array $params): string
    {
        return self::COMBINED_CSS_TAG;
    }

    /**
     * Declares a Smarty prefilter from a plugin, allowing it to modify template
     * source before compilation and without changing core files.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.prefilters.php
     *
     * @param Callable $callback
     */
    public function setPrefilter(string $handle, mixed $callback, int $weight = 50): void
    {
        $this->external_filters[$handle][$weight][] = ['pre', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Declares a Smarty postfilter.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.postfilters.php
     *
     * @param Callable $callback
     */
    public function setPostfilter(string $handle, mixed $callback, int $weight = 50): void
    {
        $this->external_filters[$handle][$weight][] = ['post', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Declares a Smarty outputfilter.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.outputfilters.php
     *
     * @param Callable $callback
     */
    public function setOutputfilter(string $handle, mixed $callback, int $weight = 50): void
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
                        $compile_id .= $type.implode('', array_map(fn ($v): string => is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : ''), $callback));
                    } elseif ($callback instanceof \Closure) {
                        // Reflect the closure's function name for a stable compile_id contribution.
                        $compile_id .= $type.new \ReflectionFunction($callback)->getName();
                    } elseif (is_string($callback)) {
                        $compile_id .= $type.$callback;
                    }
                    if (is_callable($callback)) {
                        $this->smarty->registerFilter($type, $callback);
                    }
                }
            }
            $this->smarty->compile_id .= '.'.base_convert(hash('crc32b', $compile_id), 16, 36);
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
                    // Compute filter name as Smarty does: string → itself, array → "Class_method"
                    if (is_string($callback)) {
                        $this->smarty->unregisterFilter($type, $callback);
                    } elseif (is_array($callback) && count($callback) >= 2) {
                        $cls = is_object($callback[0]) ? $callback[0]::class : (is_string($callback[0]) ? $callback[0] : '');
                        $meth = is_string($callback[1]) ? $callback[1] : '';
                        if ($cls !== '' && $meth !== '') {
                            $this->smarty->unregisterFilter($type, $cls.'_'.$meth);
                        }
                    }
                    // Closures without a name cannot be unregistered via Smarty API
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
    /** @return string|array<mixed>|null */
    public static function prefilterWhiteSpace(string $source, mixed $smarty): string|array|null
    {
        $ld = ($smarty instanceof Smarty) ? $smarty->getLeftDelimiter() : '{';
        $rd = ($smarty instanceof Smarty) ? $smarty->getRightDelimiter() : '}';
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
     * Postfilter used when \Piwigo\Config\Config::compiledTemplateCacheLanguage() is true.
     *
     * @param string $source
     * @param Smarty $smarty
     */
    /** @return string|array<mixed>|null */
    public static function postfilterLanguage(string $source, mixed $smarty): string|array|null
    {
        // replaces echo PHP_STRING_LITERAL; with the string literal value
        $source = preg_replace_callback(
            '/\\<\\?php echo ((?:\'(?:(?:\\\\.)|[^\'])*\')|(?:"(?:(?:\\\\.)|[^"])*"));\\?\\>\\n/',
            function (array $matches): string {
                $tmp = null;
                eval('$tmp='.$matches[1].';');
                return (string)$tmp;
            },
            $source
        );
        return $source;
    }

    /**
     * Prefilter used to add theme local CSS files.
     *
     * Smarty 5 passes Smarty\Template here (not Smarty\Smarty); both
     * extend Smarty\TemplateBase, which exposes getTemplateVars().
     */
    public static function prefilterLocalCss(string $source, TemplateBase $smarty): string
    {
        $css = [];
        $themes = $smarty->getTemplateVars('themes');
        if (is_array($themes)) {
            foreach ($themes as $theme) {
                $themeId = is_array($theme) ? ($theme['id'] ?? '') : '';
                $themeIdStr = is_string($themeId) ? $themeId : '';
                $f = PWG_LOCAL_DIR.'css/'.$themeIdStr.'-rules.css';
                if (file_exists(PHPWG_ROOT_PATH.$f)) {
                    $css[] = "{combine_css path='$f' order=10}";
                }
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
    /** @return array<mixed> */
    public function loadThemeconf(string $dir): array
    {
        $themeconfs = &$GLOBALS['themeconfs'];
        if (!is_array($themeconfs)) {
            $themeconfs = [];
        }

        $dir = realpath($dir);
        if (!isset($themeconfs[$dir])) {
            $themeconf = [];
            require($dir.'/themeconf.inc.php');
            // Put themeconf in cache
            $themeconfs[$dir] = $themeconf;
        }
        return is_array($themeconfs[$dir]) ? $themeconfs[$dir] : [];
    }

    /**
     * Registers a button to be displayed on picture page.
     *
     * @param string $content
     */
    public function addPictureButton(mixed $content, int $rank = BUTTONS_RANK_NEUTRAL): void
    {
        $this->picture_buttons[$rank][] = $content;
    }

    /**
     * Registers a button to be displayed on index pages.
     *
     * @param string $content
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
    public function parseIndexButtons(): void
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
