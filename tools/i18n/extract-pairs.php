<?php

declare(strict_types=1);

// Scans the Piwigo source for static l10n_dec('singular', 'plural', ...)
// calls and returns a map of singular_key => plural_key. php-to-po-fn.php
// uses this to pair up the two flat $lang[] entries a .lang.php file
// carries per plural concept (Piwigo's legacy l10n_dec() only ever
// supported a 2-form singular/plural distinction in PHP source, regardless
// of how many real plural forms the target locale has -- see plural-
// forms.php's docblock for what that means for 3+-form languages).
//
// Also scans .tpl files for the equivalent Smarty modifier,
// |@translate_dec:'singular':'plural' (the '@' is optional -- both forms
// are real, confirmed across every legacy theme/plugin that uses it).
// Real, confirmed gap this closes (P29.6, the modus theme): a
// theme/plugin whose only plural usage is template-side -- modus's own
// "%d album"/"%d albums" (mainpage_categories.tpl) -- has no l10n_dec()
// call anywhere in its PHP source at all, so the PHP-only scan silently
// found zero pairs for it, and php-to-po-fn.php would have emitted the
// singular/plural .lang.php entries as two unrelated flat msgids instead
// of one correct msgid/msgid_plural pair.

/**
 * @return array<string, string>
 */
function extract_plural_pairs(string $root): array
{
    $pairs = [];

    $phpPattern = '/l10n_dec\s*\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'[\s\n]*,[\s\n]*\'((?:[^\'\\\\]|\\\\.)*)\'/s';
    $tplPattern = '/\|@?translate_dec:\'((?:[^\'\\\\]|\\\\.)*)\':\'((?:[^\'\\\\]|\\\\.)*)\'/s';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        /** @var SplFileInfo $file RecursiveDirectoryIterator always yields SplFileInfo */
        if (! $file->isFile()) {
            continue;
        }
        $extension = $file->getExtension();
        if ($extension !== 'php' && $extension !== 'tpl') {
            continue;
        }
        $path = $file->getPathname();
        // Only scan core source, not vendor/plugins/language/tools.
        if (
            str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            || str_contains($path, DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR)
            || str_contains($path, DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR)
            || str_contains($path, DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR)
        ) {
            continue;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }

        $pattern = $extension === 'php' ? $phpPattern : $tplPattern;
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
