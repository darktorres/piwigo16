<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/tools/i18n/extract-pairs.php';

/**
 * Real gap this locks in (P29.6, the `modus` theme port): a theme/plugin
 * whose only plural usage is template-side (Smarty's own
 * `|@translate_dec:'singular':'plural'` modifier, never a PHP-source
 * `l10n_dec()` call) used to extract zero pairs, silently breaking
 * `php-to-po-fn.php`'s ability to emit a correct `msgid`/`msgid_plural`
 * pair for it -- confirmed real against modus's own legacy source
 * (`mainpage_categories.tpl`'s `"%d album"`/`"%d albums"`, which has no
 * `l10n_dec()` call anywhere in that theme's PHP).
 */
function makeExtractPairsScratchDir(): string
{
    $dir = sys_get_temp_dir() . '/extract-pairs-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o777, true);

    return $dir;
}

function removeExtractPairsScratchDir(string $dir): void
{
    $files = glob($dir . '/*');
    foreach ($files === false ? [] : $files as $file) {
        unlink($file);
    }
    rmdir($dir);
}

test('extracts a plural pair from a real l10n_dec() PHP call', function (): void {
    $dir = makeExtractPairsScratchDir();

    try {
        file_put_contents(
            $dir . '/functions.php',
            "<?php\nl10n_dec('%d comment', '%d comments', \$n);\n",
        );

        expect(extract_plural_pairs($dir))
            ->toBe([
                '%d comment' => '%d comments',
            ]);
    } finally {
        removeExtractPairsScratchDir($dir);
    }
});

test('extracts a plural pair from a Smarty |@translate_dec template modifier with no PHP call site at all', function (): void {
    $dir = makeExtractPairsScratchDir();

    try {
        file_put_contents(
            $dir . '/mainpage_categories.tpl',
            "<span>{\$item.nb_categories|@translate_dec:'%d album':'%d albums'}</span>",
        );

        expect(extract_plural_pairs($dir))
            ->toBe([
                '%d album' => '%d albums',
            ]);
    } finally {
        removeExtractPairsScratchDir($dir);
    }
});

test('extracts a plural pair from the non-@ |translate_dec modifier form too', function (): void {
    $dir = makeExtractPairsScratchDir();

    try {
        file_put_contents(
            $dir . '/thumbnails.tpl',
            "{\$thumbnail.NB_HITS|translate_dec:'%d hit':'%d hits'}",
        );

        expect(extract_plural_pairs($dir))
            ->toBe([
                '%d hit' => '%d hits',
            ]);
    } finally {
        removeExtractPairsScratchDir($dir);
    }
});

test('combines pairs from both .php and .tpl files in the same root', function (): void {
    $dir = makeExtractPairsScratchDir();

    try {
        file_put_contents(
            $dir . '/functions.php',
            "<?php\nl10n_dec('%d comment', '%d comments', \$n);\n",
        );
        file_put_contents(
            $dir . '/mainpage_categories.tpl',
            "{\$item.nb_categories|@translate_dec:'%d album':'%d albums'}",
        );

        // RecursiveDirectoryIterator's own real iteration order isn't
        // alphabetical -- assert both entries are present rather than one
        // fixed key order.
        expect(extract_plural_pairs($dir))
            ->toHaveCount(2)
            ->toHaveKey('%d comment', '%d comments')
            ->toHaveKey('%d album', '%d albums');
    } finally {
        removeExtractPairsScratchDir($dir);
    }
});

test('ignores a file extension other than php or tpl', function (): void {
    $dir = makeExtractPairsScratchDir();

    try {
        file_put_contents(
            $dir . '/notes.txt',
            "{\$x|@translate_dec:'%d thing':'%d things'}",
        );

        expect(extract_plural_pairs($dir))
            ->toBe([]);
    } finally {
        removeExtractPairsScratchDir($dir);
    }
});
