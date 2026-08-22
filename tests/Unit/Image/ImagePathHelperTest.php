<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Tests\Support\UrlServiceTestFactory;

/**
 * Piwigo\Image\ImagePathHelper -- pure path-string helpers. Had zero
 * dedicated coverage despite being reachable from several real callers.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot('/var/www/piwigo'));
});

afterEach(function (): void {
    // Without this, Kernel stays booted (with this file's own fake root)
    // for every later test in this shared process -- a real cross-file
    // leak found via composer test's own full-suite run.
    Kernel::reset();
});

test('originalToRepresentative inserts a pwg_representative/ segment and swaps the extension', function (): void {
    expect(ImagePathHelper::originalToRepresentative('galleries/2024/photo.jpg', 'png'))
        ->toBe('galleries/2024/pwg_representative/photo.png');
});

test('originalToFormat inserts a pwg_format/ segment and swaps the extension', function (): void {
    expect(ImagePathHelper::originalToFormat('galleries/2024/photo.jpg', 'tif'))
        ->toBe('galleries/2024/pwg_format/photo.tif');
});

test('getElementPath prefixes a local path with the app root', function (): void {
    $urlService = UrlServiceTestFactory::build();

    expect(ImagePathHelper::getElementPath('galleries/2024/photo.jpg', $urlService, Paths::fromRoot('/var/www/piwigo')))
        ->toBe('/var/www/piwigo/galleries/2024/photo.jpg');
});

test('getElementPath leaves a remote (http/https) path untouched', function (): void {
    $urlService = UrlServiceTestFactory::build();

    expect(ImagePathHelper::getElementPath('https://cdn.example.test/photo.jpg', $urlService, Paths::fromRoot('/var/www/piwigo')))
        ->toBe('https://cdn.example.test/photo.jpg');
    expect(ImagePathHelper::getElementPath('http://cdn.example.test/photo.jpg', $urlService, Paths::fromRoot('/var/www/piwigo')))
        ->toBe('http://cdn.example.test/photo.jpg');
});

test('getElementPath leaves an already-absolute local path untouched, not doubled up', function (): void {
    // images.path is absolute for locally site-synced photos (galleries_url
    // is seeded absolute by InstallWizard) -- prepending the root a second
    // time here produced an unreadable, doubled-up path for every such
    // photo (real bug, found live).
    $urlService = UrlServiceTestFactory::build();

    expect(ImagePathHelper::getElementPath('/var/www/piwigo/galleries/external/photo.jpg', $urlService, Paths::fromRoot('/var/www/piwigo')))
        ->toBe('/var/www/piwigo/galleries/external/photo.jpg');
});
