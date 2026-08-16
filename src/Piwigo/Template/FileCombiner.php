<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Exception;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Event\CombinablePreparse;
use Piwigo\Template\Event\CombinedCssPostfilter;

final class FileCombiner
{
    private readonly bool $is_css;

    /**
     * @param string $type 'js' or 'css'
     * @param Combinable[] $combinables
     */
    public function __construct(
        private readonly AccessLevelChecker $accessLevelChecker,
        private readonly string $type,
        private readonly UrlServiceInterface $urlService,
        private readonly Paths $paths,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CurrentConfig $currentConfig,
        private array $combinables = [],
    ) {
        $this->is_css = $this->type === 'css';
    }

    /**
     * Deletes all combined files from cache directory.
     */
    public static function clearCombinedFiles(CurrentConfig $currentConfig, Paths $paths): void
    {
        $root = $paths->root;
        $dir = opendir($root . $currentConfig->combinedDir);
        if ($dir === false) {
            return;
        }
        while ((bool) ($file = readdir($dir))) {
            if (StringHelper::getExtension($file) === 'js' || StringHelper::getExtension($file) === 'css') {
                unlink($root . $currentConfig->combinedDir . $file);
            }
        }
        closedir($dir);
    }

    /**
     * @param Combinable|Combinable[] $combinable
     */
    public function add(Combinable|array $combinable): void
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
        $force = $this->computeForce();

        $result = [];
        $pending = [];
        $ini_key = $this->initialKey();
        $key = $ini_key;

        foreach ($this->combinables as $combinable) {
            if ($combinable->path === null) {
                // Same defensive "warn and omit" contract
                // ScriptLoader::getHeadScripts() already applies to a
                // never-resolved path -- there's nothing to combine or
                // pass through for an entry with no file at all.
                trigger_error("Combinable {$combinable->id} has an undefined path", E_USER_WARNING);
                continue;
            }
            if ($combinable->isRemote($this->urlService)) {
                $this->flushPending($result, $pending, $key, $force);
                $key = $ini_key;
                $result[] = $combinable;
                continue;
            }
            if (! $this->currentConfig->templateCombineFiles) {
                $this->flushPending($result, $pending, $key, $force);
                $key = $ini_key;
            }

            $key[] = $combinable->path;
            $key[] = (string) $combinable->version;
            if ($this->currentConfig->templateCompileCheck) {
                $key[] = (string) filemtime($this->paths->root . $combinable->path);
            }
            $pending[] = $combinable;
        }
        $this->flushPending($result, $pending, $key, $force);
        return $result;
    }

    /**
     * @return string[]
     */
    private function initialKey(): array
    {
        // Because for css we modify bg url.
        return $this->is_css ? [$this->urlService->getAbsoluteRootUrl(false)] : [];
    }

    private function computeForce(): bool
    {
        if ($this->accessLevelChecker->isAdmin() && ($this->is_css || ! $this->currentConfig->templateCompileCheck)) {
            return (isset($_SERVER['HTTP_CACHE_CONTROL']) && is_string($_SERVER['HTTP_CACHE_CONTROL']) && str_contains($_SERVER['HTTP_CACHE_CONTROL'], 'max-age=0'))
              || (isset($_SERVER['HTTP_PRAGMA']) && is_string($_SERVER['HTTP_PRAGMA']) && (bool) strpos($_SERVER['HTTP_PRAGMA'], 'no-cache'));
        }

        return false;
    }

    /**
     * Process a set of pending files.
     *
     * @param Combinable[] $result
     * @param Combinable[] $pending
     * @param string[] $key
     */
    private function flushPending(array &$result, array &$pending, array $key, bool $force): void
    {
        if (count($pending) > 1) {
            $key = join('>', $key);
            $file = $this->currentConfig->combinedDir . base_convert(hash('crc32b', $key), 16, 36) . '.' . $this->type;
            if ($force || ! file_exists($this->paths->root . $file)) {
                $output = '';
                $header = '';
                foreach ($pending as $combinable) {
                    $output .= "/*BEGIN {$combinable->path} */\n";
                    $output .= $this->processCombinable($combinable, true, $force, $header);
                    $output .= "\n";
                }
                $output = "/*BEGIN header */\n" . $header . "\n" . $output;
                FilesystemHelper::mkgetdir(dirname($this->paths->root . $file), $this->currentConfig);
                file_put_contents($this->paths->root . $file, $output);
                @chmod($this->paths->root . $file, 0644);
            }
            $result[] = new Combinable('combi', $file, false);
        } elseif (count($pending) === 1) {
            $header = '';
            $this->processCombinable($pending[0], false, $force, $header);
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
     */
    private function processCombinable(Combinable $combinable, bool $return_content, bool $force, string &$header): ?string
    {
        // Both real call sites only ever pass an item already drawn from
        // $pending, which combine()'s own loop above never adds a
        // null-path combinable to (see its own "undefined path" warn-and-
        // skip branch).
        assert($combinable->path !== null);

        if ($combinable->is_template) {
            // Only ever read below when $return_content is false (where
            // it's also assigned) -- $return_content doesn't change value
            // in between, but declaring this here lets Psalm see it's
            // always defined without tracing that correlation itself.
            $file = null;
            if (! $return_content) {
                $key = [$combinable->path, $combinable->version];
                if ($this->currentConfig->templateCompileCheck) {
                    $key[] = filemtime($this->paths->root);
                }
                $file = $this->currentConfig->combinedDir . 't' . base_convert(hash('crc32b', implode(',', $key)), 16, 36) . '.' . $this->type;
                if (! $force && file_exists($this->paths->root . $file)) {
                    $combinable->path = $file;
                    $combinable->version = false;

                    return null;
                }
            }

            $template = $this->currentTemplate->get();
            $real_path = realpath($this->paths->root . $combinable->path);
            if ($real_path === false) {
                throw new Exception("processCombinable(): file not found for {$combinable->path}");
            }
            $this->eventDispatcher->dispatch(new CombinablePreparse($template, $combinable, $this)); // allow themes and plugins to set their own vars to template ...
            // parse($real_path, true) is always string (never null) since we
            // always pass true here (see Template::parse()'s conditional
            // return type).
            $content = $template->parse($real_path, true);

            if ($this->is_css) {
                $content = self::processCss($content, $combinable->path, $header, $this->urlService, $this->paths, $this->eventDispatcher);
            } else {
                $content = self::processJs($content);
            }

            if ($return_content) {
                return $content;
            }
            // Unlike flushPending()'s multi-item ('combi') branch, this
            // single-item ('t'-prefixed) cache file has no other call site
            // that ever creates CurrentConfig::combinedDir() -- on a fresh
            // install (or right after clearCombinedFiles(), which only
            // unlinks *.js/*.css and never rmdir()s the directory itself,
            // so this is only reachable pre-first-write) the very first
            // single-combinable template request would silently fail to
            // write here (file_put_contents() against a non-existent
            // directory) while still pointing $combinable->path at the
            // never-written file.
            FilesystemHelper::mkgetdir(dirname($this->paths->root . $file), $this->currentConfig);
            file_put_contents($this->paths->root . $file, $content);
            $combinable->path = $file;

            return null;
        }
        if ($return_content) {
            $content = file_get_contents($this->paths->root . $combinable->path);
            if ($content === false) {
                throw new Exception('do_combine(): unable to read ' . $combinable->path);
            }
            if ($this->is_css) {
                $content = self::processCss($content, $combinable->path, $header, $this->urlService, $this->paths, $this->eventDispatcher);
            } else {
                $content = self::processJs($content);
            }
            return $content;
        }

        return null;
    }

    /**
     * Process a JS file.
     *
     * @param string|null $js file content
     */
    private static function processJs(?string $js): string
    {
        return trim((string) $js, " \t\r\n;") . ";\n";
    }

    /**
     * Process a CSS file.
     *
     * @param string $header CSS directives that must appear first in
     *                       the minified file.
     */
    private static function processCss(string $css, string $file, string &$header, UrlServiceInterface $urlService, Paths $paths, EventDispatcher $eventDispatcher): string
    {
        $css = self::processCssRec($css, dirname($file), $header, $urlService, $paths);
        $postfilterEvent = $eventDispatcher->dispatch(new CombinedCssPostfilter($css));

        return $postfilterEvent->css;
    }

    /**
     * Resolves relative links in CSS file.
     *
     * @param string $header CSS directives that must appear first in
     *                       the minified file.
     */
    private static function processCssRec(string $css, string $dir, string &$header, UrlServiceInterface $urlService, Paths $paths): string
    {
        /** @var non-empty-string */
        static $PATTERN_URL = "#url\(\s*['|\"]{0,1}(.*?)['|\"]{0,1}\s*\)#";
        /** @var non-empty-string */
        static $PATTERN_IMPORT = "#@import\s*['|\"]{0,1}(.*?)['|\"]{0,1};#";

        if ((bool) preg_match_all($PATTERN_URL, $css, $matches, PREG_SET_ORDER)) {
            $search = $replace = [];
            foreach ($matches as $match) {
                if (! $urlService->urlIsRemote($match[1]) && $match[1][0] !== '/' && ! str_contains($match[1], 'data:image/')) {
                    $relative = $dir . "/{$match[1]}";
                    $search[] = $match[0];
                    $replace[] = 'url(' . $urlService->embellishUrl($urlService->getAbsoluteRootUrl(false) . $relative) . ')';
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
                    or ! is_readable($paths->root . $dir . '/' . $match[1])
                ) {
                    // If anything is suspicious, don't try to process the
                    // @import. Since @import need to be first and we are
                    // concatenating several CSS files, remove it from here and return
                    // it through $header.
                    $header .= $match[0];
                    $replace[] = '';
                } else {
                    $sub_css = file_get_contents($paths->root . $dir . "/{$match[1]}");
                    if ($sub_css === false) {
                        throw new Exception('processCssRec(): unable to read ' . $dir . "/{$match[1]}");
                    }
                    $replace[] = self::processCssRec($sub_css, dirname($dir . "/{$match[1]}"), $header, $urlService, $paths);
                }
            }
            $css = str_replace($search, $replace, $css);
        }
        return $css;
    }
}
