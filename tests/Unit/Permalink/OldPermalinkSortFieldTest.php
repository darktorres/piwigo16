<?php

declare(strict_types=1);

use Piwigo\Permalink\OldPermalinkSortField;

/**
 * Piwigo\Permalink\OldPermalinkSortField -- the typed replacement for
 * Controller\Admin\PermalinksSubController::parseSortVariables()'s own
 * bare column-name string. Every one of the 5 real sortable_by fields
 * and one invalid token, same test shape as Image\PhotoSortFieldTest.
 */
test('fromToken recognizes every real sortable field', function (string $token, OldPermalinkSortField $expected): void {
    expect(OldPermalinkSortField::fromToken($token))->toBe($expected);
})->with([
    ['cat_id', OldPermalinkSortField::CatId],
    ['permalink', OldPermalinkSortField::Permalink],
    ['date_deleted', OldPermalinkSortField::DateDeleted],
    ['last_hit', OldPermalinkSortField::LastHit],
    ['hit', OldPermalinkSortField::Hit],
]);

test('fromToken returns null for an unrecognized token', function (): void {
    expect(OldPermalinkSortField::fromToken('not_a_real_column'))->toBeNull();
});

test('dqlProperty returns the real DQL property path for every field', function (): void {
    expect(OldPermalinkSortField::CatId->dqlProperty())->toBe('op.catId')
        ->and(OldPermalinkSortField::Permalink->dqlProperty())->toBe('op.permalink')
        ->and(OldPermalinkSortField::DateDeleted->dqlProperty())->toBe('op.dateDeleted')
        ->and(OldPermalinkSortField::LastHit->dqlProperty())->toBe('op.lastHit')
        ->and(OldPermalinkSortField::Hit->dqlProperty())->toBe('op.hit');
});
