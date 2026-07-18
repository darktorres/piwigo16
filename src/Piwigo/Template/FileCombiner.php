<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Piwigo\Config\Config;

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
        $dir = opendir(PHPWG_ROOT_PATH . Config::combinedDir());
        if ($dir === false) {
            return;
        }
        while ((bool) ($file = readdir($dir))) {
            if (\Piwigo\Core\StringHelper::getExtension($file) == 'js' || \Piwigo\Core\StringHelper::getExtension($file) == 'css') {
                unlink(PHPWG_ROOT_PATH . Config::combinedDir() . $file);
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
        if (\Piwigo\Auth\AccessControl::isAdmin() && ($this->is_css || ! \Piwigo\Config\Config::templateCompileCheck())) {
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
            if (! \Piwigo\Config\Config::templateCombineFiles()) {
                $this->flush_pending($result, $pending, $key, $force);
                $key = $ini_key;
            }

            $key[] = $combinable->path;
            $key[] = (string) $combinable->version;
            if (\Piwigo\Config\Config::templateCompileCheck()) {
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
            $file = Config::combinedDir() . base_convert(hash('crc32b', $key), 16, 36) . '.' . $this->type;
            if ($force || ! file_exists(PHPWG_ROOT_PATH . $file)) {
                $output = '';
                $header = '';
                foreach ($pending as $combinable) {
                    $output .= "/*BEGIN {$combinable->path} */\n";
                    $output .= $this->process_combinable($combinable, true, $force, $header);
                    $output .= "\n";
                }
                $output = "/*BEGIN header */\n" . $header . "\n" . $output;
                \Piwigo\Core\FilesystemHelper::mkgetdir(dirname(PHPWG_ROOT_PATH . $file));
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
                if (\Piwigo\Config\Config::templateCompileCheck()) {
                    $key[] = filemtime(PHPWG_ROOT_PATH . $combinable->path);
                }
                $file = Config::combinedDir() . 't' . base_convert(hash('crc32b', implode(',', $key)), 16, 36) . '.' . $this->type;
                if (! $force && file_exists(PHPWG_ROOT_PATH . $file)) {
                    $combinable->path = $file;
                    $combinable->version = false;

                    return null;
                }
            }

            $template = \Piwigo\Template\CurrentTemplate::get();
            $handle = $this->type . '.' . $combinable->id;
            $real_path = realpath(PHPWG_ROOT_PATH . $combinable->path);
            if ($real_path === false) {
                throw new \Exception("process_combinable(): file not found for {$combinable->path}");
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
                throw new \Exception('do_combine(): unable to read ' . $combinable->path);
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
            throw new \Exception("process_css(): a 'combined_css_postfilter' event listener returned a non-string value");
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
                        throw new \Exception('process_css_rec(): unable to read ' . $dir . "/{$match[1]}");
                    }
                    $replace[] = self::process_css_rec($sub_css, dirname($dir . "/{$match[1]}"), $header);
                }
            }
            $css = str_replace($search, $replace, $css);
        }
        return $css;
    }
}
