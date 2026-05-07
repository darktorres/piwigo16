<?php

declare(strict_types=1);

namespace Piwigo\Template;

use JShrink\Minifier;
use MatthiasMullie\Minify\CSS;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\InstallSentinel;
use Piwigo\Users\PermissionService;

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
    public function __construct(private readonly string $type, private array $combinables = [])
    {
        $this->is_css = $this->type == 'css';
    }

    /**
     * Deletes all combined files from cache directory.
     */
    public static function clear_combined_files(): void
    {
        $dir = opendir(PHPWG_ROOT_PATH.PWG_COMBINED_DIR);
        if ($dir === false) {
            return;
        }
        while ($file = readdir($dir)) {
            if (get_extension($file) == 'js' || get_extension($file) == 'css') {
                unlink(PHPWG_ROOT_PATH.PWG_COMBINED_DIR.$file);
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
        $force = false;
        if (InstallSentinel::isInstalled() && PermissionService::get()->isAdmin() && ($this->is_css || !Config::templateCompileCheck())) {
            $force = (isset($_SERVER['HTTP_CACHE_CONTROL']) && is_string($_SERVER['HTTP_CACHE_CONTROL']) && str_contains($_SERVER['HTTP_CACHE_CONTROL'], 'max-age=0'))
              || (isset($_SERVER['HTTP_PRAGMA']) && is_string($_SERVER['HTTP_PRAGMA']) && str_contains($_SERVER['HTTP_PRAGMA'], 'no-cache'));
        }

        $result = [];
        $pending = [];
        $ini_key = $this->is_css ? [get_absolute_root_url(false)] : []; //because for css we modify bg url;
        $key = $ini_key;

        foreach ($this->combinables as $combinable) {
            $is_dist = !$this->is_css && str_starts_with($combinable->path, 'dist/');
            if ($combinable->is_remote() || $is_dist) {
                $this->flush_pending($result, $pending, $key, $force);
                $key = $ini_key;
                $result[] = $combinable;
                continue;
            } elseif (!Config::templateCombineFiles()) {
                $this->flush_pending($result, $pending, $key, $force);
                $key = $ini_key;
            }

            $key[] = $combinable->path;
            $key[] = (string) $combinable->version;
            if (Config::templateCompileCheck()) {
                $mtime = filemtime(PHPWG_ROOT_PATH . $combinable->path);
                $key[] = $mtime !== false ? (string) $mtime : '0';
            }
            $pending[] = $combinable;
        }
        $this->flush_pending($result, $pending, $key, $force);
        return $result;
    }

    /**
     * Process a set of pending files.
     *
     * @param string[] $key
     */
    /**
     * @param Combinable[] $result
     * @param Combinable[] $pending
     * @param string[] $key
     */
    private function flush_pending(array &$result, array &$pending, array $key, bool $force): void
    {
        if (count($pending) > 1) {
            $key = join('>', $key);
            $file = PWG_COMBINED_DIR . base_convert(hash('crc32b', $key), 16, 36) . '.' . $this->type;
            if ($force || !file_exists(PHPWG_ROOT_PATH.$file)) {
                $output = '';
                $header = '';
                foreach ($pending as $combinable) {
                    $output .= "/*BEGIN $combinable->path */\n";
                    $output .= $this->process_combinable($combinable, true, $force, $header);
                    $output .= "\n";
                }
                $output = "/*BEGIN header */\n" . $header . "\n" . $output;
                mkgetdir(dirname(PHPWG_ROOT_PATH.$file));
                file_put_contents(PHPWG_ROOT_PATH.$file, $output);
                Filesystem::tryChmod(PHPWG_ROOT_PATH.$file, 0644);
            }
            $result[] = new Combinable('combi', $file, 0);
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
     * @return null|string
     */
    private function process_combinable($combinable, bool $return_content, bool $force, string &$header)
    {
        if ($combinable->is_template) {
            if (!$return_content) {
                $key = [$combinable->path, $combinable->version];
                if (Config::templateCompileCheck()) {
                    $key[] = filemtime(PHPWG_ROOT_PATH . $combinable->path);
                }
                $file = PWG_COMBINED_DIR . 't' . base_convert(hash('crc32b', implode(',', $key)), 16, 36) . '.' . $this->type;
                if (!$force && file_exists(PHPWG_ROOT_PATH.$file)) {
                    $combinable->path = $file;
                    $combinable->version = 0;
                    return null;
                }
            }

            $template = TemplateRegistry::current();
            $handle = $this->type. '.' .$combinable->id;
            $resolved = realpath(PHPWG_ROOT_PATH.$combinable->path);
            $template->set_filename($handle, $resolved !== false ? $resolved : PHPWG_ROOT_PATH.$combinable->path);
            trigger_notify('combinable_preparse', $template, $combinable, $this); //allow themes and plugins to set their own vars to template ...
            $content = (string) $template->parse($handle, true);

            if ($this->is_css) {
                $content = self::process_css($content, $combinable->path, $header);
            } else {
                $content = self::process_js($content, $combinable->path);
            }

            if ($return_content) {
                return $content;
            }
            file_put_contents(PHPWG_ROOT_PATH.$file, $content);
            $combinable->path = $file;
        } elseif ($return_content) {
            // handled below

            $content = file_get_contents(PHPWG_ROOT_PATH . $combinable->path);
            if ($content === false) {
                return null;
            }
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
     * @param string $file
     */
    private static function process_js(string $js, $file): string
    {
        if (!str_contains($file, '.min') and !str_contains($file, '.packed')) {
            try {
                $minified = Minifier::minify($js);
                if (is_string($minified)) {
                    $js = $minified;
                }
            } catch (\Exception) {
            }
        }
        return trim($js, " \t\r\n;").";\n";
    }

    /**
     * Process a CSS file.
     *
     * @param string $css file content
     * @param string $file
     * @param string $header CSS directives that must appear first in
     *                       the minified file.
     * @return string
     */
    private static function process_css(string $css, $file, string &$header)
    {
        $css = self::process_css_rec($css, dirname($file), $header);
        if (!str_contains($file, '.min')) {
            $minifier = new CSS($css);
            $css = $minifier->minify();
        }
        $css = trigger_change('combined_css_postfilter', $css);
        return $css;
    }

    /**
     * Resolves relative links in CSS file.
     *
     * @param string $css file content
     * @param string $header CSS directives that must appear first in
     *                       the minified file.
     */
    private static function process_css_rec(string $css, string $dir, string &$header): string
    {
        static $PATTERN_URL = "#url\(\s*['|\"]{0,1}(.*?)['|\"]{0,1}\s*\)#";
        static $PATTERN_IMPORT = "#@import\s*['|\"]{0,1}(.*?)['|\"]{0,1};#";

        if (preg_match_all($PATTERN_URL, $css, $matches, PREG_SET_ORDER)) {
            $search = $replace = [];
            foreach ($matches as $match) {
                if (!url_is_remote($match[1]) && $match[1][0] != '/' && !str_contains($match[1], 'data:image/')) {
                    $relative = $dir . "/$match[1]";
                    $search[] = $match[0];
                    $url = embellish_url(get_absolute_root_url(false).$relative);
                    $replace[] = 'url('.(is_string($url) ? $url : get_absolute_root_url(false).$relative).')';
                }
            }
            $css = str_replace($search, $replace, $css);
        }

        if (preg_match_all($PATTERN_IMPORT, $css, $matches, PREG_SET_ORDER)) {
            $search = $replace = [];

            foreach ($matches as $match) {
                $search[] = $match[0];

                if (
                    str_contains($match[1], '..') // Possible attempt to get out of Piwigo's dir
                    or str_contains($match[1], '://') // Remote URL
                    or !is_readable(PHPWG_ROOT_PATH . $dir . '/' . $match[1])
                ) {
                    // If anything is suspicious, don't try to process the
                    // @import. Since @import need to be first and we are
                    // concatenating several CSS files, remove it from here and return
                    // it through $header.
                    $header .= $match[0];
                    $replace[] = '';
                } else {
                    $sub_css = file_get_contents(PHPWG_ROOT_PATH . $dir . "/$match[1]");
                    if ($sub_css !== false) {
                        $replace[] = self::process_css_rec($sub_css, dirname($dir . "/$match[1]"), $header);
                    } else {
                        $replace[] = '';
                    }
                }
            }
            $css = str_replace($search, $replace, $css);
        }
        return $css;
    }
}
