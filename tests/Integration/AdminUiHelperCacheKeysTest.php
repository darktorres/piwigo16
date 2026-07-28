<?php

declare(strict_types=1);

use Piwigo\Admin\AdminUiHelper;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

/**
 * AdminUiHelper::getAdminClientCacheKeys() -- the pure, file-based half of
 * this class (getExtents/pwgUrl/getActiveMenu/numberFormatHumanReadable) is
 * covered by tests/Unit/Admin/AdminUiHelperTest.php; this method needs a
 * real DB connection (a live COUNT/MAX(lastmodified) per table), so it
 * lives here instead. Each per-item value is the live-computed
 * "{unix_timestamp}_{count}" string described by the method's own
 * docblock -- asserted by shape (real fixture row counts shift as other
 * Integration/Contract/Browser tests mutate the same shared DB), not by a
 * hardcoded literal.
 */
function adminUiHelperUrlService(): UrlService
{
    return new UrlService(new HtmlService());
}

test('getAdminClientCacheKeys returns all 5 known table keys plus _hash when nothing specific is requested', function (): void {
    $keys = AdminUiHelper::getAdminClientCacheKeys(adminUiHelperUrlService());

    expect(array_keys($keys))->toBe(['_hash', 'categories', 'groups', 'images', 'tags', 'users']);
    expect($keys['_hash'])->toBe(md5(adminUiHelperUrlService()->getAbsoluteRootUrl()));
    foreach (['categories', 'groups', 'images', 'tags', 'users'] as $item) {
        expect($keys[$item])->toMatch('/^\d+_\d+$/');
    }
});

test('getAdminClientCacheKeys with a single scalar string requested returns just that key plus _hash', function (): void {
    $keys = AdminUiHelper::getAdminClientCacheKeys(adminUiHelperUrlService(), 'tags');

    expect(array_keys($keys))->toBe(['_hash', 'tags']);
    expect($keys['tags'])->toMatch('/^\d+_\d+$/');
});

test('getAdminClientCacheKeys filters an array request down to only the known table names', function (): void {
    $keys = AdminUiHelper::getAdminClientCacheKeys(adminUiHelperUrlService(), ['users', 'not-a-real-table', 'groups']);

    expect(array_keys($keys))->toBe(['_hash', 'users', 'groups']);
});

test('getAdminClientCacheKeys returns just _hash when every requested key is unknown', function (): void {
    $keys = AdminUiHelper::getAdminClientCacheKeys(adminUiHelperUrlService(), ['not-a-real-table']);

    expect(array_keys($keys))->toBe(['_hash']);
});