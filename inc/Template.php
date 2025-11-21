<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Closure;
use Smarty;

set_error_handler(function (
    int $errno,
    string $errstr,
    string $errfile,
    int $errline
): bool {
    // Define error types and corresponding prefixes
    $error_types = [
        E_ERROR => 'error',
        E_WARNING => 'warn',
        E_PARSE => 'error',
        E_NOTICE => 'info',
        E_CORE_ERROR => 'error',
        E_CORE_WARNING => 'warn',
        E_COMPILE_ERROR => 'error',
        E_COMPILE_WARNING => 'warn',
        E_USER_ERROR => 'error',
        E_USER_WARNING => 'warn',
        E_USER_NOTICE => 'info',
        E_RECOVERABLE_ERROR => 'error',
        E_DEPRECATED => 'warn',
        E_USER_DEPRECATED => 'warn',
    ];

    // Determine the error type
    $error_type = $error_types[$errno] ?? 'Unknown Error';

    // Construct the error message
    $errorMessage = json_encode("PHP: {$errstr} in {$errfile} on line {$errline}");

    // Store in global var
    global $custom_error_log;
    $custom_error_log .= "console.{$error_type}({$errorMessage});\n";

    // Ensure PHP's internal error handler is not bypassed
    return false;
});

// Catch fatal errors that set_error_handler doesn't catch
register_shutdown_function(function () {
    $error = error_get_last();

    if ($error !== null && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR))) {
        global $custom_error_log;

        if ($custom_error_log === null) {
            $custom_error_log = '';
        }

        $errorMessage = json_encode("PHP FATAL: {$error['message']} in {$error['file']} on line {$error['line']}");
        $custom_error_log .= "console.error({$errorMessage});\n";
    }
});

/** default rank for buttons */
define('BUTTONS_RANK_NEUTRAL', 50);

/**
 * This a wrapper around Smarty classes proving various custom mechanisms for templates.
 */
final class Template
{
    public const string COMBINED_SCRIPTS_TAG = '<!-- COMBINED_SCRIPTS -->';

    public const string COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';

    public Smarty\Smarty $smarty;

    public string $output = '';

    /**
     * @var array<string> - Hash of filenames for each template handle.
     */
    public array $files = [];

    /**
     * @var array<string> - Template extents filenames for each template handle.
     */
    public array $extents = [];

    /**
     * Templates prefilter from external sources (plugins)
     */
    public array $external_filters = [];

    /**
     * Content to add before </head> tag
     */
    public array $html_head_elements = [];

    public ScriptLoader $scriptLoader;

    public CssLoader $cssLoader;

    /**
     * Runtime buttons on picture page
     */
    public array $picture_buttons = [];

    /**
     * Runtime buttons on index page
     */
    public array $index_buttons = [];

    /**
     * Runtime CSS rules
     */
    private string $html_style = '';

    /**
     * @throws Smarty\Exception
     */
    public function __construct(
        string $root = '.',
        string $theme = '',
        string $path = 'template'
    ) {
        global $conf, $lang_info;

        $this->scriptLoader = new ScriptLoader();
        $this->cssLoader = new CssLoader();
        $this->smarty = new Smarty\Smarty();
        $this->smarty->debugging = $conf->debug_template;

        if (! $this->smarty->debugging) {
            $this->smarty->error_reporting = error_reporting() & ~E_NOTICE;
        }

        $this->smarty->compile_check = $conf->template_compile_check;
        $this->smarty->force_compile = $conf->template_force_compile;

        if (! isset($conf->data_dir_checked)) {
            $dir = './' . $conf->data_location;
            functions::mkgetdir($dir, functions::MKGETDIR_DEFAULT & ~functions::MKGETDIR_DIE_ON_ERROR);

            if (! is_writable($dir)) {
                functions::load_language('admin.lang');
                functions_html::fatal_error(
                    functions::l10n(
                        'Give write access (chmod 777) to "%s" directory at the root of your Piwigo installation',
                        $conf->data_location
                    ),
                    functions::l10n('an error happened'),
                    false // show trace
                );
            }

            if (class_exists($conf->sql_backend, false)) {
                functions::conf_update_param('data_dir_checked', 1);
            }
        }

        $compile_dir = './' . $conf->data_location . 'templates_c';
        functions::mkgetdir($compile_dir);

        $this->smarty->setCompileDir($compile_dir);

        $this->smarty->assign('pwg', new PwgTemplateAdapter());
        $this->smarty->registerClass('functions_admin', '\Piwigo\admin\inc\functions_admin');
        $this->smarty->registerClass('functions_url', '\Piwigo\inc\functions_url');
        $this->smarty->registerClass('functions_user', '\Piwigo\inc\functions_user');
        $this->smarty->registerClass('functions', '\Piwigo\inc\functions');
        $this->smarty->registerPlugin('modifiercompiler', 'translate', self::modcompiler_translate(...));
        $this->smarty->registerPlugin('modifiercompiler', 'translate_dec', self::modcompiler_translate_dec(...));
        $this->smarty->registerPlugin('modifier', 'sprintf', sprintf(...));
        $this->smarty->registerPlugin('modifier', 'urlencode', urlencode(...));
        $this->smarty->registerPlugin('modifier', 'intval', intval(...));
        $this->smarty->registerPlugin('modifier', 'file_exists', file_exists(...));
        $this->smarty->registerPlugin('modifier', 'constant', constant(...));
        $this->smarty->registerPlugin('modifier', 'json_encode', json_encode(...));
        $this->smarty->registerPlugin('modifier', 'htmlspecialchars', htmlspecialchars(...));
        $this->smarty->registerPlugin('modifier', 'implode', implode(...));
        $this->smarty->registerPlugin('modifier', 'stripslashes', stripslashes(...));
        $this->smarty->registerPlugin('modifier', 'in_array', in_array(...));
        $this->smarty->registerPlugin('modifier', 'ucfirst', ucfirst(...));
        $this->smarty->registerPlugin('modifier', 'strstr', strstr(...));
        $this->smarty->registerPlugin('modifier', 'trim', trim(...));
        $this->smarty->registerPlugin('modifier', 'md5', md5(...));
        $this->smarty->registerPlugin('modifier', 'strtolower', strtolower(...));
        $this->smarty->registerPlugin('modifier', 'get_extent', $this->get_extent(...));
        $this->smarty->registerPlugin('modifier', 'strpos', strpos(...));
        $this->smarty->registerPlugin('modifier', 'preg_match', preg_match(...));
        $this->smarty->registerPlugin('modifier', 'array_key_exists', array_key_exists(...));
        $this->smarty->registerPlugin('modifier', 'is_null', is_null(...));
        $this->smarty->registerPlugin('modifier', 'gettype', gettype(...));
        $this->smarty->registerPlugin('modifier', 'reset', reset(...));
        $this->smarty->registerPlugin('block', 'html_head', $this->block_html_head(...));
        $this->smarty->registerPlugin('block', 'html_style', $this->block_html_style(...));
        $this->smarty->registerPlugin('function', 'combine_script', $this->func_combine_script(...));
        $this->smarty->registerPlugin('function', 'get_combined_scripts', $this->func_get_combined_scripts(...));
        $this->smarty->registerPlugin('function', 'combine_css', $this->func_combine_css(...));
        $this->smarty->registerPlugin('function', 'define_derivative', $this->func_define_derivative(...));
        $this->smarty->registerPlugin('compiler', 'get_combined_css', $this->func_get_combined_css(...));
        $this->smarty->registerPlugin('block', 'footer_script', $this->block_footer_script(...));
        $this->smarty->registerFilter('pre', self::prefilter_white_space(...));

        if ($conf->compiled_template_cache_language) {
            $this->smarty->registerFilter('post', self::postfilter_language(...));
        }

        $this->smarty->setTemplateDir([]);

        if (! empty($theme)) {
            $this->set_theme($root, $theme, $path);

            if (! defined('IN_ADMIN')) {
                $this->set_prefilter('header', self::prefilter_local_css(...));
            }
        } else {
            $this->set_template_dir($root);
        }

        if (isset($lang_info['code']) &&
            ! isset($lang_info['jquery_code'])
        ) {
            $lang_info['jquery_code'] = $lang_info['code'];
        }

        if (isset($lang_info['jquery_code']) &&
            ! isset($lang_info['plupload_code'])
        ) {
            $lang_info['plupload_code'] = str_replace('-', '_', $lang_info['jquery_code']);
        }

        $this->smarty->assign('lang_info', $lang_info);

        if (! defined('IN_ADMIN') &&
            isset($conf->extents_for_templates)
        ) {
            $tpl_extents = $conf->extents_for_templates;
            $this->set_extents($tpl_extents, './template-extension/', true, $theme);
        }
    }

    /**
     * Loads theme's parameters.
     */
    public function set_theme(
        string $root,
        string $theme,
        string $path,
        bool $load_css = true,
        bool $load_local_head = true,
        string $colorscheme = 'dark'
    ): void {
        $this->set_template_dir($root . '/' . $theme . '/' . $path);

        $themeconf = $this->load_themeconf($root . '/' . $theme);

        if (isset($themeconf['parent']) &&
            $themeconf['parent'] != $theme
        ) {
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

        if (! empty($themeconf['local_head']) &&
            $load_local_head
        ) {
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
     */
    public function set_template_dir(
        string $dir
    ): void {
        $this->smarty->addTemplateDir($dir);

        if ($this->smarty->compile_id === null) {
            $compile_id = '1';
            $compile_id .= ($real_dir = realpath($dir)) === false ? $dir : $real_dir;
            $this->smarty->compile_id = base_convert(hash('crc32b', $compile_id), 16, 36);
        }
    }

    /**
     * Gets the template root directory for this Template object.
     */
    public function get_template_dir(): array|string
    {
        return $this->smarty->getTemplateDir();
    }

    /**
     * Deletes all compiled templates.
     */
    public function delete_compiled_templates(): void
    {
        $save_compile_id = $this->smarty->compile_id;
        $this->smarty->compile_id = null;
        $this->smarty->clearCompiledTemplate();
        $this->smarty->compile_id = $save_compile_id;
        file_put_contents($this->smarty->getCompileDir() . '/index.htm', 'Not allowed!');
    }

    /**
     * Returns theme's parameter.
     */
    public function get_themeconf(
        string $val
    ): array|bool|string {
        $tc = $this->smarty->getTemplateVars('themeconf');
        return $tc[$val] ?? '';
    }

    /**
     * Sets the template filename for handle.
     */
    public function set_filename(
        string $handle,
        string $filename
    ): bool {
        return $this->set_filenames([
            $handle => $filename,
        ]);
    }

    /**
     * Sets the template filenames for handles.
     *
     * @param array<string, string> $filename_array hashmap of handle=>filename
     */
    public function set_filenames(
        array $filename_array
    ): bool {
        if (! is_array($filename_array)) {
            return false;
        }

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
     * Sets template extension filename for handles.
     */
    public function set_extent(
        string $filename,
        string|array $param,
        string $dir = '',
        bool $overwrite = true,
        string $theme = 'N/A'
    ): bool {
        return $this->set_extents([
            $filename => $param,
        ], $dir, $overwrite);
    }

    /**
     * Sets template extensions filenames for handles.
     *
     * @param array<string> $filename_array hashmap of handle=>filename
     */
    public function set_extents(
        array $filename_array,
        string $dir = '',
        bool $overwrite = true,
        string $theme = 'N/A'
    ): bool {
        if (! is_array($filename_array)) {
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

            if ((stripos(implode('', array_keys($_GET)), '/' . $param) !== false || $param === 'N/A') &&
                ($thm === $theme || $thm === 'N/A') &&
                (! isset($this->extents[$handle]) || $overwrite) &&
                file_exists($dir . $filename)
            ) {
                $this->extents[$handle] = realpath($dir . $filename);
            }
        }

        return true;
    }

    /**
     * Returns template extension if exists.
     *
     * @param string $filename should be empty!
     */
    public function get_extent(
        string $filename = '',
        string $handle = ''
    ): string {
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
     */
    public function assign(
        string|array $tpl_var,
        array|bool|string|int|float|null|object $value = null
    ): void {
        $this->smarty->assign($tpl_var, $value);
    }

    /**
     * Defines _$varname_ as the compiled result of _$handle_.
     * This can be used to effectively include a template in another template.
     * This is equivalent to assign($varname, $this->parse($handle, true)).
     * @throws Smarty\Exception
     */
    public function assign_var_from_handle(
        string $varname,
        string $handle
    ): bool {
        $this->assign($varname, $this->parse($handle, true));
        return true;
    }

    /**
     * Appends a new value in a template array variable, the variable is created if needed.
     * @see http://www.smarty.net/manual/en/api.append.php
     */
    public function append(
        string $tpl_var,
        array|string|null $value = null,
        bool $merge = false
    ): void {
        $this->smarty->append($tpl_var, $value, $merge);
    }

    /**
     * Performs a string concatenation.
     */
    public function concat(
        string $tpl_var,
        string $value
    ): void {
        $this->assign(
            $tpl_var,
            $this->smarty->getTemplateVars($tpl_var) . $value
        );
    }

    /**
     * Removes an assigned template variable.
     * @see http://www.smarty.net/manual/en/api.clear_assign.php
     */
    public function clear_assign(
        array|string $tpl_var
    ): void {
        $this->smarty->clearAssign($tpl_var);
    }

    /**
     * Returns an assigned template variable.
     * @see http://www.smarty.net/manual/en/api.get_template_vars.php
     */
    public function get_template_vars(
        ?string $tpl_var = null
    ): array|string|int|float|bool|null|object {
        return $this->smarty->getTemplateVars($tpl_var);
    }

    /**
     * Loads the template file of the handle, compiles it and appends the result to the output
     * (or returns it if _$return_ is true).
     * @throws Smarty\Exception
     */
    public function parse(
        string $handle,
        bool $return = false
    ): ?string {
        if (! isset($this->files[$handle])) {
            functions_html::fatal_error("Template->parse(): Couldn't load template file for handle {$handle}");
        }

        $this->smarty->assign('ROOT_URL', functions_url::get_root_url());

        $save_compile_id = $this->smarty->compile_id;
        $this->load_external_filters($handle);

        global $conf, $lang_info;

        if ($conf->compiled_template_cache_language &&
            isset($lang_info['code'])
        ) {
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
     * @throws Smarty\Exception
     */
    public function pparse(
        string $handle
    ): void {
        $this->parse($handle);
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
                        '<script src="'
                        . $this->make_script_src($script)
                        . '"></script>';
                }

                $this->output = substr_replace($this->output, implode("\n", $content), $pos, strlen(self::COMBINED_SCRIPTS_TAG));
            }
        }

        $css = $this->cssLoader->get_css();

        $content = [];

        foreach ($css as $combi) {
            $href = functions_url::embellish_url(functions_url::get_root_url() . $combi->path);

            if ($combi->version !== false) {
                $href .= '?v' . ($combi->version ?: PHPWG_VERSION);
            }

            // trigger the event for eventual use of a cdn
            $href = functions_plugins::trigger_change('combined_css', $href, $combi);
            $content[] = '<link rel="stylesheet" href="' . $href . '">';
        }

        $this->output = str_replace(
            self::COMBINED_CSS_TAG,
            implode("\n", $content),
            $this->output
        );
        $this->cssLoader->clear();

        if (count($this->html_head_elements) ||
            strlen($this->html_style)
        ) {
            $search = '</head>';
            $pos = strpos($this->output, $search);

            if ($pos !== false) {
                $rep = "\n" . implode("\n", $this->html_head_elements);

                if (strlen($this->html_style) !== 0) {
                    $rep .= $this->html_style;
                }

                $this->output = substr_replace($this->output, $rep, $pos, 0);
            }

            $this->html_head_elements = [];
            $this->html_style = '';
        }

        global $custom_error_log;

        if (! empty($custom_error_log)) {
            $search = '</body>';
            $pos = strpos($this->output, $search);

            if ($pos !== false) {
                $rep = <<<JS
                <script>
                    window.addEventListener("load", function() {
                        {$custom_error_log}
                    });
                </script>
                JS;
                $this->output = substr_replace($this->output, $rep, $pos, 0);
            }
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
                    'AAAA_DEBUG_TOTAL_TIME__' => functions::get_elapsed_time($t2, functions::get_moment()),
                ]
            );
            $this->smarty->display(__DIR__ . '/../vendor/smarty/smarty/src/debug.tpl');
        }
    }

    /**
     * Eval a temp string to retrieve the original PHP value.
     */
    public static function get_php_str_val(
        string $str
    ): string|null {
        if (is_string($str) && strlen($str) > 1 && ($str[0] === "'" && $str[strlen($str) - 1] === "'" || $str[0] === '"' && $str[strlen($str) - 1] === '"')) {
            eval('$tmp=' . $str . ';');
            return $tmp;
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
    public static function modcompiler_translate(
        array $params
    ): string {
        global $conf, $lang;

        switch (count($params)) {
            case 1:
                if ($conf->compiled_template_cache_language) {
                    $key = self::get_php_str_val($params[0]);

                    if ($key !== null && isset($lang[$key])) {
                        return var_export($lang[$key], true);
                    }
                }

                return '\Piwigo\inc\functions::l10n(' . $params[0] . ')';

            default:
                if ($conf->compiled_template_cache_language) {
                    $ret = 'sprintf(';
                    $ret .= self::modcompiler_translate([$params[0]]);
                    $ret .= ',' . implode(',', array_slice($params, 1));
                    $ret .= ')';
                    return $ret;
                }

                return '\Piwigo\inc\functions::l10n(' . $params[0] . ',' . implode(',', array_slice($params, 1)) . ')';
        }
    }

    /**
     * "translate_dec" variable modifier.
     * Usage :
     *    - {$count|translate_dec:'%d comment':'%d comments'}
     * @see l10n_dec()
     */
    public static function modcompiler_translate_dec(
        array $params
    ): string {
        global $conf, $lang, $lang_info;

        if ($conf->compiled_template_cache_language) {
            $ret = 'sprintf(';

            if ($lang_info['zero_plural']) {
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

        return '\Piwigo\inc\functions::l10n_dec(' . $params[1] . ',' . $params[2] . ',' . $params[0] . ')';
    }

    // /**
    //  * "explode" variable modifier.
    //  * Usage :
    //  *    - {assign var=valueExploded value=$value|explode:','}
    //  */
    // public static function mod_explode(
    //     string $text,
    //     string $delimiter = ','
    // ): array {
    //     return explode($delimiter, $text);
    // }

    // /**
    //  * ternary variable modifier.
    //  * Usage :
    //  *    - {$variable|ternary:'yes':'no'}
    //  */
    // public static function mod_ternary(
    //     bool|int|float|string|array|null $param,
    //     bool|int|float|string|array|null $true,
    //     bool|int|float|string|array|null $false
    // ): bool|int|float|string|array|null {
    //     return $param ? $true : $false;
    // }

    /**
     * The "html_head" block allows to add content just before
     * </head> element in the output after the head has been parsed.
     *
     * @param ?array $params (unused)
     */
    public function block_html_head(
        ?array $params,
        ?string $content
    ): void {
        $content = isset($content) ? trim($content) : '';

        if (! empty($content)) { // second call
            $this->html_head_elements[] = $content;
        }
    }

    /**
     * The "html_style" block allows to add CSS juste before
     * </head> element in the output after the head has been parsed.
     *
     * @param ?array $params (unused)
     */
    public function block_html_style(
        ?array $params,
        ?string $content
    ): void {
        $content = isset($content) ? trim($content) : '';

        if (! empty($content)) { // second call
            $this->html_style .= "\n" . $content;
        }
    }

    /**
     * The "define_derivative" function allows to define derivative from tpl file.
     * It assigns a DerivativeParams object to _name_ template variable.
     *
     * @param array{
     *     name: mixed,
     *     type?: mixed,
     *     width: mixed,
     *     height: mixed,
     *     crop?: mixed,
     *     min_height?: mixed,
     *     min_height?: mixed,
     *     min_width: mixed,
     * } $params
     */
    public function func_define_derivative(
        array $params,
        Smarty\Template $smarty
    ): void {
        if (empty($params['name'])) {
            functions_html::fatal_error('define_derivative missing name');
        }

        if (isset($params['type'])) {
            $derivative = ImageStdParams::get_by_type($params['type']);
            $smarty->assign($params['name'], $derivative);
            return;
        }

        if (empty($params['width'])) {
            functions_html::fatal_error('define_derivative missing width');
        }

        if (empty($params['height'])) {
            functions_html::fatal_error('define_derivative missing height');
        }

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

                if ($minw <= $w) {
                    functions_html::fatal_error('define_derivative invalid min_width');
                }

                $minh = empty($params['min_height']) ? $h : intval($params['min_height']);

                if ($minh <= $h) {
                    functions_html::fatal_error('define_derivative invalid min_height');
                }
            }
        }

        $smarty->assign($params['name'], ImageStdParams::get_custom($w, $h, $crop, $minw, $minh));
    }

    /**
     * The "combine_script" functions allows inclusion of a javascript file in the current page.
     * The engine will combine several js files into a single one.
     *
     * @param array{
     *     id: mixed,
     *     path: mixed,
     *     load?: mixed,
     *     require?: mixed,
     *     version?: mixed,
     *     template: mixed,
     * } $params
     */
    public function func_combine_script(
        array $params
    ): void {
        if (! isset($params['id'])) {
            trigger_error("combine_script: missing 'id' parameter", E_USER_ERROR);
        }

        $load = 0;

        if (isset($params['load'])) {
            switch ($params['load']) {
                case 'header':
                    break;

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
            empty($params['require']) ? [] : explode(',', $params['require']),
            ($params['path'] ?? null),
            $params['version'] ?? 0,
            ($params['template'] ?? null)
        );
    }

    /**
     * The "get_combined_scripts" function returns HTML tag of combined scripts.
     * It can returns a placeholder for delayed JS files combination and minification.
     *
     * @param array{
     *     load: mixed,
     * } $params
     * @throws Smarty\Exception
     */
    public function func_get_combined_scripts(
        array $params
    ): string {
        if (! isset($params['load'])) {
            trigger_error("get_combined_scripts: missing 'load' parameter", E_USER_ERROR);
        }

        $load = $params['load'] == 'header' ? 0 : 1;
        $content = [];

        if ($load == 0) {
            return self::COMBINED_SCRIPTS_TAG;
        }

        $scripts = $this->scriptLoader->get_footer_scripts();

        foreach ($scripts[0] as $script) {
            $content[] =
              '<script src="'
              . $this->make_script_src($script)
              . '"></script>';
        }

        if ($this->scriptLoader->inline_scripts !== []) {
            $content = array_merge($content, $this->scriptLoader->inline_scripts);
        }

        if (count($scripts[1])) {
            $content[] = '<script>';
            $content[] =
              "(function() {\n"
              . "var s, after = document.getElementsByTagName('script')[document.getElementsByTagName('script').length - 1];\n";

            foreach ($scripts[1] as $id => $script) {
                $content[] =
                  "s = document.createElement('script'); s.async = true; s.src = '"
                  . $this->make_script_src($script)
                  . "';\n";
                $content[] = "after = after.parentNode.insertBefore(s, after);\n";
            }

            $content[] = '})();';
            $content[] = '</script>';
        }

        return implode("\n", $content);
    }

    /**
     * The "footer_script" block allows to add runtime script in the HTML page.
     *
     * @param ?array{
     *     require?: mixed,
     * } $params
     */
    public function block_footer_script(
        ?array $params,
        ?string $content
    ): void {
        $content = isset($content) ? trim($content) : '';

        if (! empty($content)) { // second call
            $this->scriptLoader->add_inline(
                $content,
                empty($params['require']) ? [] : explode(',', $params['require'])
            );
        }
    }

    /**
     * The "combine_css" function allows inclusion of a css file in the current page.
     * The engine will combine several css files into a single one.
     *
     * @param array{
     *     id?: string,
     *     path: string,
     *     version?: int,
     *     order?: int,
     *     template?: bool,
     * } $params
     */
    public function func_combine_css(
        array $params
    ): void {
        if (empty($params['path'])) {
            functions_html::fatal_error('combine_css missing path');
        }

        if (! isset($params['id'])) {
            $params['id'] = md5($params['path']);
        }

        $this->cssLoader->add($params['id'], $params['path'], $params['version'] ?? 0, (int) ($params['order'] ?? null), (bool) ($params['template'] ?? null));
    }

    /**
     * The "get_combined_scripts" function returns a placeholder for delayed
     * CSS files combination and minification.
     *
     * @param array $params (unused)
     */
    public function func_get_combined_css(
        array $params
    ): string {
        return self::COMBINED_CSS_TAG;
    }

    /**
     * Declares a Smarty prefilter from a plugin, allowing it to modify template
     * source before compilation and without changing core files.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.prefilters.php
     */
    public function set_prefilter(
        string $handle,
        callable $callback,
        int $weight = 50
    ): void {
        $this->external_filters[$handle][$weight][] = ['pre', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Declares a Smarty postfilter.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.postfilters.php
     */
    public function set_postfilter(
        string $handle,
        callable $callback,
        int $weight = 50
    ): void {
        $this->external_filters[$handle][$weight][] = ['post', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Declares a Smarty outputfilter.
     * They will be processed by weight ascending.
     * @see http://www.smarty.net/manual/en/advanced.features.outputfilters.php
     */
    public function set_outputfilter(
        string $handle,
        callable $callback,
        int $weight = 50
    ): void {
        $this->external_filters[$handle][$weight][] = ['output', $callback];
        ksort($this->external_filters[$handle]);
    }

    /**
     * Register the filters for the tpl file.
     * @throws Smarty\Exception
     */
    public function load_external_filters(
        string $handle
    ): void {
        if (isset($this->external_filters[$handle])) {
            $compile_id = '';

            foreach ($this->external_filters[$handle] as $filters) {
                foreach ($filters as $filter) {
                    [$type, $callback] = $filter;

                    if (is_array($callback)) {
                        $callbackString = implode('', $callback);
                    } elseif (is_string($callback)) {
                        $callbackString = $callback;
                    } elseif ($callback instanceof Closure) {
                        $callbackString = 'closure';
                    }

                    $compile_id .= $type . $callbackString;
                    $this->smarty->registerFilter($type, $callback);
                }
            }

            $this->smarty->compile_id .= '.' . base_convert(hash('crc32b', $compile_id), 16, 36);
        }
    }

    /**
     * Unregister the filters for the tpl file.
     */
    public function unload_external_filters(
        string $handle
    ): void {
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
     * @todo : description of Template::prefilter_white_space
     */
    public static function prefilter_white_space(
        string $source,
        Smarty\Template $smarty
    ): string|null {
        $ld = $smarty->getLeftDelimiter();
        $rd = $smarty->getRightDelimiter();
        $ldq = preg_quote($ld, '#');
        $rdq = preg_quote($rd, '#');

        $regex = [];
        $tags = ['if', 'foreach', 'section', 'footer_script'];

        foreach ($tags as $tag) {
            $regex[] = "#^[ \t]+({$ldq}{$tag}" . "[^{$ld}{$rd}]*{$rdq})\s*$#m";
            $regex[] = "#^[ \t]+({$ldq}/{$tag}{$rdq})\s*$#m";
        }

        $tags = ['include', 'else', 'combine_script', 'html_head'];

        foreach ($tags as $tag) {
            $regex[] = "#^[ \t]+({$ldq}{$tag}" . "[^{$ld}{$rd}]*{$rdq})\s*$#m";
        }

        return preg_replace($regex, '$1', $source);
    }

    /**
     * Postfilter used when $conf->compiled_template_cache_language is true.
     */
    public static function postfilter_language(
        string $source,
        Smarty\Template $smarty
    ): string|null {
        // replaces echo PHP_STRING_LITERAL; with the string literal value
        return preg_replace_callback(
            '/\\<\\?php echo ((?:\'(?:(?:\\\\.)|[^\'])*\')|(?:"(?:(?:\\\\.)|[^"])*"));\\?\\>\\n/',
            function (array $matches): string {
                eval('$tmp=' . $matches[1] . ';');
                return $tmp;
            },
            $source
        );
    }

    /**
     * Prefilter used to add theme local CSS files.
     */
    public static function prefilter_local_css(
        string $source,
        Smarty\Template $smarty
    ): string {
        $css = [];

        foreach ($smarty->getTemplateVars('themes') as $theme) {
            $f = 'local/css/' . $theme['id'] . '-rules.css';

            if (file_exists('./' . $f)) {
                $css[] = "{combine_css path='{$f}' order=10}";
            }
        }

        $f = 'local/css/rules.css';

        if (file_exists('./' . $f)) {
            $css[] = "{combine_css path='{$f}' order=10}";
        }

        if ($css !== []) {
            $source = str_replace('{get_combined_css}', implode("\n", $css) . "\n{get_combined_css}", $source);
        }

        return $source;
    }

    /**
     * Loads the configuration file from a theme directory and returns it.
     */
    public function load_themeconf(
        string $dir
    ): array {
        global $themeconfs, $conf;

        $dir = realpath($dir);

        if (! isset($themeconfs[$dir])) {
            $themeconf = [];
            require $dir . '/themeconf.php';
            // Put themeconf in cache
            $themeconfs[$dir] = $themeconf;
        }

        return $themeconfs[$dir];
    }

    /**
     * Registers a button to be displayed on picture page.
     */
    public function add_picture_button(
        string $content,
        int $rank = BUTTONS_RANK_NEUTRAL
    ): void {
        $this->picture_buttons[$rank][] = $content;
    }

    /**
     * Registers a button to be displayed on index pages.
     */
    public function add_index_button(
        string $content,
        int $rank = BUTTONS_RANK_NEUTRAL
    ): void {
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
        }
    }

    /**
     * Returns clean relative URL to script file.
     */
    private function make_script_src(
        Combinable $script
    ): string {
        $ret = '';

        if ($script->is_remote()) {
            $ret = $script->path;
        } else {
            $ret = functions_url::get_root_url() . $script->path;

            if ($script->version !== false) {
                $ret .= '?v' . ($script->version ?: PHPWG_VERSION);
            }
        }

        // trigger the event for eventual use of a cdn
        $ret = functions_plugins::trigger_change('combined_script', $ret, $script);
        return functions_url::embellish_url($ret);
    }
}
