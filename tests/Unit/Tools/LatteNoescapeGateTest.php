<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/tools/latte-noescape-gate.php';

/**
 * The regression fixture for P59's zero-tolerance `|noescape` gate
 * (`tools/latte-lint.php`/`tools/latte-noescape-gate.php`): proves the
 * gate actually fires on an unlisted `|noescape`, on a count drift
 * against an allowlisted one, and on a stale (no-longer-present)
 * allowlist entry -- not just that the corpus happens to be clean
 * today. Builds real temp `.latte` fixture files under a scratch
 * directory rather than asserting against the live `themes/` tree, so
 * these stay meaningful regardless of the corpus's own real state.
 */
function makeNoescapeGateScratchDir(): string
{
    $dir = sys_get_temp_dir() . '/latte-noescape-gate-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o777, true);

    return $dir;
}

function removeNoescapeGateScratchDir(string $dir): void
{
    $files = glob($dir . '/*.latte');
    foreach ($files === false ? [] : $files as $file) {
        unlink($file);
    }
    rmdir($dir);
}

test('an unlisted noescape is reported as a violation', function (): void {
    $dir = makeNoescapeGateScratchDir();

    try {
        file_put_contents($dir . '/example.latte', '<p>{$msg|noescape}</p>');

        $violations = scanNoescapeCorpus($dir, []);

        expect($violations)
            ->toHaveCount(1);
        expect($violations[0])
            ->toContain('example.latte')
            ->toContain('found 1')
            ->toContain('allowlist expects 0');
    } finally {
        removeNoescapeGateScratchDir($dir);
    }
});

test('a noescape count matching the allowlist is clean', function (): void {
    $dir = makeNoescapeGateScratchDir();

    try {
        file_put_contents($dir . '/example.latte', '<p>{$a|noescape}</p><p>{$b|noescape}</p>');

        $violations = scanNoescapeCorpus($dir, [
            'example.latte' => 2,
        ]);

        expect($violations)
            ->toBe([]);
    } finally {
        removeNoescapeGateScratchDir($dir);
    }
});

test('a noescape count exceeding the allowlist is reported as a violation', function (): void {
    $dir = makeNoescapeGateScratchDir();

    try {
        file_put_contents($dir . '/example.latte', '<p>{$a|noescape}</p><p>{$b|noescape}</p>');

        $violations = scanNoescapeCorpus($dir, [
            'example.latte' => 1,
        ]);

        expect($violations)
            ->toHaveCount(1);
        expect($violations[0])
            ->toContain('found 2')
            ->toContain('allowlist expects 1')
            ->toContain('not a silent addition');
    } finally {
        removeNoescapeGateScratchDir($dir);
    }
});

test('a stale allowlist entry for a since-fixed file is reported as a violation', function (): void {
    $dir = makeNoescapeGateScratchDir();

    try {
        file_put_contents($dir . '/example.latte', '<p>{$msg}</p>');

        $violations = scanNoescapeCorpus($dir, [
            'example.latte' => 1,
        ]);

        expect($violations)
            ->toHaveCount(1);
        expect($violations[0])
            ->toContain('found 0')
            ->toContain('allowlist expects 1')
            ->toContain('stale');
    } finally {
        removeNoescapeGateScratchDir($dir);
    }
});

test('an unrelated template comment mentioning noescape in prose does not trip the gate', function (): void {
    $dir = makeNoescapeGateScratchDir();

    try {
        file_put_contents(
            $dir . '/example.latte',
            "{* noescape kept on purpose: explained elsewhere *}\n<p>{\$msg}</p>"
        );

        $violations = scanNoescapeCorpus($dir, []);

        expect($violations)
            ->toBe([]);
    } finally {
        removeNoescapeGateScratchDir($dir);
    }
});

test('the real allowlist matches the real themes/ corpus exactly', function (): void {
    $root = dirname(__DIR__, 3);
    /** @var array<string, int> $allowlist */
    $allowlist = require $root . '/tools/latte-noescape-allowlist.php';

    $violations = scanNoescapeCorpus($root . '/themes', $allowlist);

    expect($violations)
        ->toBe([]);
});
