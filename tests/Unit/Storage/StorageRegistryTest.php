<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Piwigo\Storage\StorageRegistry;

if (! defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', dirname(__DIR__, 3) . '/');
}

beforeEach(function (): void {
    StorageRegistry::reset();
});

afterEach(function (): void {
    StorageRegistry::reset();
});

test('get round-trips a write and read on a real local disk', function (): void {
    $dir = sys_get_temp_dir() . '/piwigo-storage-registry-test-' . bin2hex(random_bytes(4));
    mkdir($dir);

    $registry = new StorageRegistry([
        'scratch' => static fn () => new Filesystem(new LocalFilesystemAdapter($dir)),
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
        'scratch' => static function () use ($dir, &$callCount) {
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

test('disk() delegates to the current() singleton', function (): void {
    $dir = sys_get_temp_dir() . '/piwigo-storage-registry-test-' . bin2hex(random_bytes(4));
    mkdir($dir);

    $registry = new StorageRegistry([
        'scratch' => static fn () => new Filesystem(new LocalFilesystemAdapter($dir)),
    ]);
    StorageRegistry::set($registry);

    expect(StorageRegistry::disk('scratch'))->toBe($registry->get('scratch'));

    rmdir($dir);
});

test('current() lazily builds from config/storage.php only once', function (): void {
    $first = StorageRegistry::current();
    $second = StorageRegistry::current();

    expect($first)->toBe($second);
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
    // The realistic case this exists for: PHPWG_ROOT_PATH ('./') concatenated
    // with a Config value that already starts with './' (e.g. Config::
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
