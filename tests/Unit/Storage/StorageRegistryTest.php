<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Storage\StorageRegistry;

/**
 * Container-shared instance (singleton/service-locator elimination
 * campaign, Phase 2) -- most tests construct their own fresh instance
 * directly via `new StorageRegistry([...])`/`fromConfig()`; no reset()
 * needed for the instance API. disk()'s own Kernel::isBooted()-adjacent
 * behavior (it has no safe default, so it throws via Kernel::container()
 * itself when not booted) is covered separately below.
 */
beforeEach(function (): void {
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 3)));
});

afterEach(function (): void {
    CurrentPaths::reset();
    if (Kernel::isBooted()) {
        Kernel::reset();
    }
});

test('get round-trips a write and read on a real local disk', function (): void {
    $dir = sys_get_temp_dir() . '/piwigo-storage-registry-test-' . bin2hex(random_bytes(4));
    mkdir($dir);

    $registry = new StorageRegistry([
        'scratch' => static fn (): \League\Flysystem\Filesystem => new Filesystem(new LocalFilesystemAdapter($dir)),
    ]);

    $registry->get('scratch')->write('hello.txt', 'world');

    expect($registry->get('scratch')->read('hello.txt'))->toBe('world');

    $registry->get('scratch')->delete('hello.txt');
    rmdir($dir);
});

test('get lazily initializes a disk only once, reusing the same instance', function (): void {
    $dir = sys_get_temp_dir() . '/piwigo-storage-registry-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $callCount = 0;

    $registry = new StorageRegistry([
        'scratch' => static function () use ($dir, &$callCount): \League\Flysystem\Filesystem {
            $callCount++;

            return new Filesystem(new LocalFilesystemAdapter($dir));
        },
    ]);

    $first = $registry->get('scratch');
    $second = $registry->get('scratch');

    expect($first)->toBe($second)
        ->and($callCount)->toBe(1);

    rmdir($dir);
});

test('get throws for an unknown disk name', function (): void {
    $registry = new StorageRegistry([]);

    $registry->get('does-not-exist');
})->throws(InvalidArgumentException::class, "Unknown storage disk 'does-not-exist'");

test('get lists the disk names (array_keys), not the factory closures, in the unknown-disk exception message', function (): void {
    $registry = new StorageRegistry([
        'alpha' => static fn (): Filesystem => new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir())),
        'beta' => static fn (): Filesystem => new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir())),
    ]);

    $registry->get('does-not-exist');
})->throws(InvalidArgumentException::class, "Unknown storage disk 'does-not-exist'. Available: alpha, beta.");

test('disk throws when Kernel has not booted', function (): void {
    expect(Kernel::isBooted())->toBeFalse();

    expect(fn () => StorageRegistry::disk('temp'))->toThrow(
        \LogicException::class,
        'Kernel not booted — call Kernel::boot() first.',
    );
});

test('disk delegates to the container-shared instance once Kernel has booted', function (): void {
    Kernel::boot();
    $storageRegistry = Kernel::container()->get(StorageRegistry::class);
    if (! $storageRegistry instanceof StorageRegistry) {
        throw new \LogicException('Container returned an unexpected type for ' . StorageRegistry::class);
    }

    expect(StorageRegistry::disk('temp'))->toBe($storageRegistry->get('temp'));
});

test('container-resolved StorageRegistry requires config/storage.php from beneath CurrentPaths::get()->root, not a bare relative path', function (): void {
    // Distinctive, non-project root: config/storage.php below it declares a
    // disk name ('mutation-canary') that the real project config/storage.php
    // does not have. If config/container.php's own `CurrentPaths::get()->root .`
    // concatenation were dropped, StorageRegistry::fromConfig() would
    // receive the bare literal 'config/storage.php' -- resolved relative to
    // cwd/include_path, not this root -- and would never see this disk, so
    // get() would throw instead of resolving it.
    $dir = sys_get_temp_dir() . '/piwigo-storage-registry-fromconfig-' . bin2hex(random_bytes(4));
    mkdir($dir . '/config', 0o777, true);
    file_put_contents($dir . '/config/storage.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        return [
            'mutation-canary' => static fn (): \League\Flysystem\Filesystem => new \League\Flysystem\Filesystem(
                new \League\Flysystem\Local\LocalFilesystemAdapter(sys_get_temp_dir()),
            ),
        ];
        PHP);

    CurrentPaths::set(Paths::fromRoot($dir));
    Kernel::boot();

    expect(StorageRegistry::disk('mutation-canary'))->toBeInstanceOf(Filesystem::class);

    unlink($dir . '/config/storage.php');
    rmdir($dir . '/config');
    rmdir($dir);
});

test('stripRoot converts an absolute path under the root into a relative one', function (): void {
    expect(StorageRegistry::stripRoot('/var/www/piwigo', '/var/www/piwigo/upload/2026/photo.jpg'))
        ->toBe('upload/2026/photo.jpg');
});

test('stripRoot normalizes backslashes', function (): void {
    expect(StorageRegistry::stripRoot('/var/www/piwigo', '/var/www/piwigo\\upload\\photo.jpg'))
        ->toBe('upload/photo.jpg');
});

test('stripRoot collapses a single /./ redundancy from root+path concatenation', function (): void {
    // The realistic case this exists for: Paths::$root concatenated
    // with a Config value that already starts with './' (e.g. CurrentConfig::
    // uploadDir()'s './upload') produces exactly one '/./' redundancy in the
    // middle of the string -- normalize() does a single str_replace() pass,
    // not a repeated/recursive collapse, but that's sufficient for the one
    // redundancy this concatenation pattern actually produces.
    expect(StorageRegistry::stripRoot('././upload', '././upload/2026/photo.jpg'))
        ->toBe('2026/photo.jpg');
});

test('stripRoot falls back to a left-trimmed path when the root does not actually prefix it', function (): void {
    expect(StorageRegistry::stripRoot('/var/www/other', '/var/www/piwigo/upload/photo.jpg'))
        ->toBe('var/www/piwigo/upload/photo.jpg');
});

test('stripRoot rtrims a trailing slash off the normalized absolute path before comparing', function (): void {
    // $root has no trailing slash to strip, so only the rtrim() applied to
    // $absolutePath (inside the shared normalize closure) can be responsible
    // for producing 'upload' instead of 'upload/' here.
    expect(StorageRegistry::stripRoot('/var/www/piwigo', '/var/www/piwigo/upload/'))
        ->toBe('upload');
});

test('stripRoot collapses a /./ segment that only appears in the path, not the root', function (): void {
    // Unlike the root+path concatenation-pattern test above, $root here has
    // no '/./' of its own -- only $absolutePath does. This asymmetry is
    // deliberate: a root+path pair that *both* contain the same '/./'
    // redundancy can't distinguish "properly collapsed to '/'" from
    // "left untouched" or "collapsed to nothing", because the normalize()
    // closure runs on both sides and any wrong-but-consistent transform
    // still cancels out in the str_starts_with()+substr() comparison. With
    // the redundancy only on the path side, a wrong transform there breaks
    // the match against the (unaffected) normalized root and shows up
    // directly in the returned relative path.
    expect(StorageRegistry::stripRoot('/var/www/piwigo', '/var/www/piwigo/./upload/photo.jpg'))
        ->toBe('upload/photo.jpg');
});
