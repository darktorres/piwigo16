<?php

/**
 * Scans the Piwigo source for static Translator::get()->plural('singular', 'plural', ...) calls
 * and returns a map of singular_key → plural_key.
 *
 * Usage (as a library, included by php-to-po.php):
 *   $pairs = extract_plural_pairs($rootDir);
 */

declare(strict_types=1);

function extract_plural_pairs(string $root): array
{
    $pairs = [];

    $dirs = [
        $root . '/include',
        $root . '/admin',
        $root . '/src',
    ];

    $pattern = '/l10n_dec\s*\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'[\s\n]*,[\s\n]*\'((?:[^\'\\\\]|\\\\.)*)\'/s';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        // Only scan core source, not vendor/plugins/language/tools
        if (
            str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) ||
            str_contains($path, DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR) ||
            str_contains($path, DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR) ||
            str_contains($path, DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR)
        ) {
            continue;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $singular = stripslashes($match[1]);
                $plural   = stripslashes($match[2]);
                if ($singular !== '' && $plural !== '' && $singular !== $plural) {
                    $pairs[$singular] = $plural;
                }
            }
        }
    }

    return $pairs;
}
