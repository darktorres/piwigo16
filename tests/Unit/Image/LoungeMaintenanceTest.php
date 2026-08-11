<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Image\LoungeMaintenance;

/**
 * Piwigo\Image\LoungeMaintenance -- checks whether the lounge needs
 * automatic emptying. No dedicated Integration/Browser spec of its own.
 *
 * Covers `needsEmptying()`'s 2 pure/config-driven guards: `loungeActive`
 * disabled (config-only, no DB) and a `?method=pwg.images.upload` /
 * `pwg.images.uploadAsync` in-progress request (real `$_REQUEST['method']`
 * read via `EmptyLoungeRequest::fromGlobals()`, still no DB). The real
 * "is the oldest lounge photo older than the max duration" branch needs
 * a real `ImageRepository::findOldestLoungeAgeInfo()` DB read against
 * the shared `lounge` table and is not attempted here.
 */
afterEach(function (): void {
    unset($_REQUEST['method']);
});

test('needsEmptying returns false when loungeActive is disabled', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->loungeActive = false;

    $result = LoungeMaintenance::needsEmptying($currentConfig);

    expect($result)
        ->toBeFalse();
});

test('needsEmptying returns false during an in-progress pwg.images.upload request', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->loungeActive = true;
    $_REQUEST['method'] = 'pwg.images.upload';

    $result = LoungeMaintenance::needsEmptying($currentConfig);

    expect($result)
        ->toBeFalse();
});

test('needsEmptying returns false during an in-progress pwg.images.uploadAsync request', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->loungeActive = true;
    $_REQUEST['method'] = 'pwg.images.uploadAsync';

    $result = LoungeMaintenance::needsEmptying($currentConfig);

    expect($result)
        ->toBeFalse();
});
