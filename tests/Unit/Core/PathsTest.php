<?php

declare(strict_types=1);

use Piwigo\Core\Paths;

test('fromRoot composes every subdirectory from the given root, trailing slash normalized', function (): void {
    $paths = Paths::fromRoot('/var/www/piwigo');

    expect($paths->root)->toBe('/var/www/piwigo/')
        ->and($paths->plugins)->toBe('/var/www/piwigo/plugins/')
        ->and($paths->themes)->toBe('/var/www/piwigo/themes/')
        ->and($paths->local)->toBe('/var/www/piwigo/local/')
        ->and($paths->siteLocal)->toBe('/var/www/piwigo/local/')
        ->and($paths->data)->toBe('/var/www/piwigo/_data/')
        ->and($paths->derivatives)->toBe('/var/www/piwigo/_data/i/')
        ->and($paths->logs)->toBe('/var/www/piwigo/_data/logs/')
        ->and($paths->upload)->toBe('/var/www/piwigo/upload/')
        ->and($paths->config)->toBe('/var/www/piwigo/config/')
        ->and($paths->vendor)->toBe('/var/www/piwigo/vendor/');
});

test('fromRoot strips a trailing slash from the given root before recomposing', function (): void {
    $paths = Paths::fromRoot('/var/www/piwigo/');

    expect($paths->root)->toBe('/var/www/piwigo/');
});

test('fromIndex derives the root from the directory containing the given file', function (): void {
    $paths = Paths::fromIndex('/var/www/piwigo/index.php');

    expect($paths->root)->toBe('/var/www/piwigo/');
});

test('siteLocal defaults to the same local/ directory when PIWIGO_LOCAL_DIR is unset', function (): void {
    $original = getenv('PIWIGO_LOCAL_DIR');
    $original = $original === false ? null : $original;
    putenv('PIWIGO_LOCAL_DIR');

    try {
        $paths = Paths::fromRoot('/var/www/piwigo');

        expect($paths->siteLocal)->toBe('/var/www/piwigo/local/')
            ->and($paths->siteLocal)->toBe($paths->local);
    } finally {
        putenv($original === null ? 'PIWIGO_LOCAL_DIR' : 'PIWIGO_LOCAL_DIR=' . $original);
    }
});

test('siteLocal honors a PIWIGO_LOCAL_DIR override, the multi-site-instance deployment mechanism, while local stays fixed', function (): void {
    $original = getenv('PIWIGO_LOCAL_DIR');
    $original = $original === false ? null : $original;
    putenv('PIWIGO_LOCAL_DIR=local-site2');

    try {
        $paths = Paths::fromRoot('/var/www/piwigo');

        expect($paths->siteLocal)->toBe('/var/www/piwigo/local-site2/')
            ->and($paths->local)->toBe('/var/www/piwigo/local/');
    } finally {
        putenv($original === null ? 'PIWIGO_LOCAL_DIR' : 'PIWIGO_LOCAL_DIR=' . $original);
    }
});
