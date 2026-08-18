<?php

declare(strict_types=1);

use Piwigo\Asset\ViteManifest;
use Piwigo\Asset\ViteManifestEntry;
use Piwigo\Core\Paths;

function viteManifestTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-vite-manifest-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root . 'dist/.vite', 0o777, true);

    return $root;
}

function viteManifestTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? viteManifestTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('resolve() returns null when no manifest.json exists', function (): void {
    $root = viteManifestTestRoot();
    $manifest = new ViteManifest(Paths::fromRoot($root));

    expect($manifest->resolve('build/vitals.ts'))
        ->toBeNull();

    viteManifestTestRrmdir($root);
});

test('resolve() returns null for a source not present in the manifest', function (): void {
    $root = viteManifestTestRoot();
    file_put_contents($root . 'dist/.vite/manifest.json', json_encode([
        'build/vitals.ts' => [
            'file' => 'vitals.js',
            'name' => 'vitals',
            'src' => 'build/vitals.ts',
            'isEntry' => true,
        ],
    ], JSON_THROW_ON_ERROR));
    $manifest = new ViteManifest(Paths::fromRoot($root));

    expect($manifest->resolve('build/noop.ts'))
        ->toBeNull();

    viteManifestTestRrmdir($root);
});

test('resolve() reads a real entry, including its css chunks', function (): void {
    $root = viteManifestTestRoot();
    file_put_contents($root . 'dist/.vite/manifest.json', json_encode([
        'build/vitals.ts' => [
            'file' => 'vitals.js',
            'name' => 'vitals',
            'src' => 'build/vitals.ts',
            'isEntry' => true,
        ],
        'build/gallery.ts' => [
            'file' => 'assets/gallery-abc123.js',
            'name' => 'gallery',
            'src' => 'build/gallery.ts',
            'isEntry' => true,
            'css' => ['assets/gallery-def456.css'],
        ],
    ], JSON_THROW_ON_ERROR));
    $manifest = new ViteManifest(Paths::fromRoot($root));

    $vitals = $manifest->resolve('build/vitals.ts');
    expect($vitals)
        ->not->toBeNull();
    if (! $vitals instanceof ViteManifestEntry) {
        throw new RuntimeException('unreachable');
    }
    expect($vitals->file)
        ->toBe('vitals.js')
        ->and($vitals->css)
        ->toBe([])
        ->and($vitals->isEntry)
        ->toBeTrue();

    $gallery = $manifest->resolve('build/gallery.ts');
    expect($gallery)
        ->not->toBeNull();
    if (! $gallery instanceof ViteManifestEntry) {
        throw new RuntimeException('unreachable');
    }
    expect($gallery->file)
        ->toBe('assets/gallery-abc123.js')
        ->and($gallery->css)
        ->toBe(['assets/gallery-def456.css']);

    viteManifestTestRrmdir($root);
});

test('resolve() treats a malformed manifest.json as empty rather than throwing', function (): void {
    $root = viteManifestTestRoot();
    file_put_contents($root . 'dist/.vite/manifest.json', '{not valid json');
    $manifest = new ViteManifest(Paths::fromRoot($root));

    expect($manifest->resolve('build/vitals.ts'))
        ->toBeNull();

    viteManifestTestRrmdir($root);
});

test('parseEntries() skips entries with a missing/non-string file field', function (): void {
    $entries = ViteManifest::parseEntries([
        'build/broken.ts' => [
            'name' => 'broken',
        ],
        'build/vitals.ts' => [
            'file' => 'vitals.js',
        ],
    ]);

    expect($entries)
        ->toHaveCount(1)
        ->and($entries['build/vitals.ts']->file)->toBe('vitals.js');
});
