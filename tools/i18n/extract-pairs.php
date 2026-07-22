<?php

declare(strict_types=1);

// Scans the Piwigo source for static l10n_dec('singular', 'plural', ...)
// calls and returns a map of singular_key => plural_key. php-to-po-fn.php
// uses this to pair up the two flat $lang[] entries a .lang.php file
// carries per plural concept (Piwigo's legacy l10n_dec() only ever
// supported a 2-form singular/plural distinction in PHP source, regardless
// of how many real plural forms the target locale has -- see plural-
// forms.php's docblock for what that means for 3+-form languages).

/**
 * @return array<string, string>
 */
function extract_plural_pairs(string $root): array
{
    $pairs = [];

    $pattern = '/l10n_dec\s*\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'[\s\n]*,[\s\n]*\'((?:[^\'\\\\]|\\\\.)*)\'/s';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        /** @var \SplFileInfo $file RecursiveDirectoryIterator always yields SplFileInfo */
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        // Only scan core source, not vendor/plugins/language/tools.
        if (
            str_contains((string) $path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            || str_contains((string) $path, DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR)
            || str_contains((string) $path, DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR)
            || str_contains((string) $path, DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR)
        ) {
            continue;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            continue;
        }

        foreach ($matches as $match) {
            $singular = stripslashes($match[1]);
            $plural = stripslashes($match[2]);
            if ($singular !== '' && $plural !== '' && $singular !== $plural) {
                $pairs[$singular] = $plural;
            }
        }
    }

    return $pairs;
}
