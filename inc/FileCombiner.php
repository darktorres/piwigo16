<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Exception;
use SmartyException;
use tubalmartin\CssMin\Minifier;

/**
 * Allows merging of javascript and css files into a single one.
 */
final class FileCombiner
{
    /**
     * 'js' or 'css'
     */
    private readonly string $type;

    private readonly bool $is_css;

    /**
     * @var array<Combinable>
     */
    private array $combinables;

    /**
     * @param string $type 'js' or 'css'
     * @param array<Combinable> $combinables
     */
    public function __construct(
        string $type,
        array $combinables = []
    ) {
        $this->type = $type;
        $this->is_css = $type == 'css';
        $this->combinables = $combinables;
    }

    /**
     * Deletes all combined files from cache directory.
     */
    public static function clear_combined_files(): void
    {
        $dir = opendir('./' . PWG_COMBINED_DIR);

        while ($file = readdir($dir)) {
            if (functions::get_extension($file) == 'js' ||
                functions::get_extension($file) == 'css'
            ) {
                unlink('./' . PWG_COMBINED_DIR . $file);
            }
        }

        closedir($dir);
    }

    /**
     * @param Combinable|Combinable[] $combinable
     */
    public function add(
        array|Combinable $combinable
    ): void {
        if (is_array($combinable)) {
            $this->combinables = array_merge($this->combinables, $combinable);
        } else {
            $this->combinables[] = $combinable;
        }
    }

    /**
     * @return Combinable[]
     * @throws SmartyException
     */
    public function combine(): array
    {
        global $conf;
        $force = false;

        if (functions_user::is_admin() &&
           ($this->is_css || ! $conf['template_compile_check'])
        ) {
            $force = (isset($_SERVER['HTTP_CACHE_CONTROL']) && strpos($_SERVER['HTTP_CACHE_CONTROL'], 'max-age=0') !== false) ||
                           (isset($_SERVER['HTTP_PRAGMA']) && strpos($_SERVER['HTTP_PRAGMA'], 'no-cache'));
        }

        $result = [];
        $pending = [];
        $ini_key = $this->is_css ? [functions_url::get_absolute_root_url(false)] : []; //because for css we modify bg url;
        $key = $ini_key;

        foreach ($this->combinables as $combinable) {
            if ($combinable->is_remote()) {
                $this->flush_pending($result, $pending, $key, $force);
                $key = $ini_key;
                $result[] = $combinable;
                continue;
            } elseif (! $conf['template_combine_files']) {
                $this->flush_pending($result, $pending, $key, $force);
                $key = $ini_key;
            }

            $key[] = $combinable->path;
            $key[] = $combinable->version;

            if ($conf['template_compile_check']) {
                $key[] = filemtime('./' . $combinable->path);
            }

            $pending[] = $combinable;
        }

        $this->flush_pending($result, $pending, $key, $force);
        return $result;
    }

    /**
     * Process a set of pending files.
     *
     * @param array<int, int|string> $key
     * @throws SmartyException
     */
    private function flush_pending(
        array &$result,
        array &$pending,
        array $key,
        bool $force
    ): void {
        if (count($pending) > 1) {
            $key = join('>', $key);
            $file = PWG_COMBINED_DIR . base_convert(hash('crc32b', $key), 16, 36) . '.' . $this->type;

            if ($force ||
                ! file_exists('./' . $file)
            ) {
                $output = '';
                $header = '';

                foreach ($pending as $combinable) {
                    $output .= "/*BEGIN {$combinable->path} */\n";
                    $output .= $this->process_combinable($combinable, true, $force, $header);
                    $output .= "\n";
                }

                $output = "/*BEGIN header */\n" . $header . "\n" . $output;
                functions::mkgetdir(dirname('./' . $file));
                file_put_contents('./' . $file, $output);
                chmod('./' . $file, 0644);
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
     * @param string $header CSS directives that must appear first in
     *                       the minified file (only used when
     *                       $return_content===true)
     * @throws SmartyException
     */
    private function process_combinable(
        Combinable $combinable,
        bool $return_content,
        bool $force,
        string &$header
    ): ?string {
        global $conf;

        if ($combinable->is_template) {
            if (! $return_content) {
                $key = [$combinable->path, $combinable->version];

                if ($conf['template_compile_check']) {
                    $key[] = filemtime('./' . $combinable->path);
                }

                $file = PWG_COMBINED_DIR . 't' . base_convert(hash('crc32b', implode(',', $key)), 16, 36) . '.' . $this->type;

                if (! $force &&
                    file_exists('./' . $file)
                ) {
                    $combinable->path = $file;
                    $combinable->version = false;
                    return null;
                }
            }

            global $template;
            $handle = $this->type . '.' . $combinable->id;
            $template->set_filename($handle, realpath('./' . $combinable->path));
            functions_plugins::trigger_notify('combinable_preparse', $template, $combinable, $this); //allow themes and plugins to set their own vars to template ...
            $content = $template->parse($handle, true);

            if ($this->is_css) {
                $content = self::process_css($content, $combinable->path, $header);
            } else {
                $content = self::process_js($content, $combinable->path);
            }

            if ($return_content) {
                return $content;
            }

            if (! file_exists(dirname('./' . $file))) {
                functions::mkgetdir(dirname('./' . $file));
            }

            file_put_contents('./' . $file, $content);
            $combinable->path = $file;
        } elseif ($return_content) {
            $content = file_get_contents('./' . $combinable->path);

            if ($this->is_css) {
                $content = self::process_css($content, $combinable->path, $header);
            } else {
                $content = self::process_js($content, $combinable->path);
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
    private static function process_js(
        string $js,
        string $file
    ): string {
        if (strpos($file, '.min') === false and
            strpos($file, '.packed') === false
        ) {
            try {
                $js = \JShrink\Minifier::minify($js);
            } catch (Exception $e) {
            }
        }

        return trim($js, " \t\r\n;") . ";\n";
    }

    /**
     * Process a CSS file.
     *
     * @param string $css file content
     * @param string $header CSS directives that must appear first in
     *                       the minified file.
     */
    private static function process_css(
        string $css,
        string $file,
        string &$header
    ): string {
        $css = self::process_css_rec($css, dirname($file), $header);

        if (strpos($file, '.min') === false and
            version_compare(PHP_VERSION, '5.2.4', '>=')
        ) {
            $cssMin = new Minifier();
            $css = $cssMin->run($css);
        }

        $css = functions_plugins::trigger_change('combined_css_postfilter', $css);
        return $css;
    }

    /**
     * Resolves relative links in CSS file.
     *
     * @param string $css file content
     * @param string $header CSS directives that must appear first in
     *                       the minified file.
     */
    private static function process_css_rec(
        string $css,
        string $dir,
        string &$header
    ): string {
        static $PATTERN_URL = "#url\(\s*['|\"]{0,1}(.*?)['|\"]{0,1}\s*\)#";
        static $PATTERN_IMPORT = "#@import\s*['|\"]{0,1}(.*?)['|\"]{0,1};#";

        if (preg_match_all($PATTERN_URL, $css, $matches, PREG_SET_ORDER)) {
            $search = $replace = [];

            foreach ($matches as $match) {
                if (! functions_url::url_is_remote($match[1]) &&
                    $match[1][0] != '/' &&
                    strpos($match[1], 'data:image/') === false
                ) {
                    $relative = $dir . "/{$match[1]}";
                    $search[] = $match[0];
                    $replace[] = 'url(' . functions_url::embellish_url(functions_url::get_absolute_root_url(false) . $relative) . ')';
                }
            }

            $css = str_replace($search, $replace, $css);
        }

        if (preg_match_all($PATTERN_IMPORT, $css, $matches, PREG_SET_ORDER)) {
            $search = $replace = [];

            foreach ($matches as $match) {
                $search[] = $match[0];

                if (strpos($match[1], '..') !== false or // Possible attempt to get out of Piwigo's dir
                    strpos($match[1], '://') !== false or // Remote URL
                    ! is_readable('./' . $dir . '/' . $match[1])
                ) {
                    // If anything is suspicious, don't try to process the
                    // @import. Since @import need to be first and we are
                    // concatenating several CSS files, remove it from here and return
                    // it through $header.
                    $header .= $match[0];
                    $replace[] = '';
                } else {
                    $sub_css = file_get_contents('./' . $dir . "/{$match[1]}");
                    $replace[] = self::process_css_rec($sub_css, dirname($dir . "/{$match[1]}"), $header);
                }
            }

            $css = str_replace($search, $replace, $css);
        }

        return $css;
    }
}
