<?php

declare(strict_types=1);

use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Url\UrlService;

/**
 * Piwigo\Image\ImagePathHelper -- pure path-string helpers. Had zero
 * dedicated coverage (see /home/torres/.claude/plans/piped-enchanting-
 * spark.md, Wave 1) despite being reachable from several real callers.
 */
beforeEach(function (): void {
    CurrentPaths::set(Paths::fromRoot('/var/www/piwigo'));
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
    $urlService = new UrlService(new HtmlService());

    expect(ImagePathHelper::getElementPath(['path' => 'galleries/2024/photo.jpg'], $urlService))
        ->toBe('/var/www/piwigo/galleries/2024/photo.jpg');
});

test('getElementPath leaves a remote (http/https) path untouched', function (): void {
    $urlService = new UrlService(new HtmlService());

    expect(ImagePathHelper::getElementPath(['path' => 'https://cdn.example.test/photo.jpg'], $urlService))
        ->toBe('https://cdn.example.test/photo.jpg');
    expect(ImagePathHelper::getElementPath(['path' => 'http://cdn.example.test/photo.jpg'], $urlService))
        ->toBe('http://cdn.example.test/photo.jpg');
});
