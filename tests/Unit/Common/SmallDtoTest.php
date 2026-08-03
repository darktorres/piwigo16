<?php

declare(strict_types=1);

use Piwigo\Admin\Category\CreateCategoryResult;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\Dto\UserGroupPair;
use Piwigo\Permalink\OldPermalinkEntity;
use Piwigo\Permalink\Projection\OldPermalink;

/**
 * A handful of small DTO/projection classes that had zero dedicated
 * coverage (see /home/torres/.claude/plans/piped-enchanting-spark.md,
 * Wave 1) but carry real narrowing/named-constructor logic worth a
 * direct test, unlike their pure-data-holder siblings (NotificationChannelConfig,
 * the Sensitive attribute) which have no behavior beyond constructor
 * assignment. PaginatedResult is the same "no behavior" shape but is
 * included below anyway -- its constructor body itself was genuinely
 * 0% covered (no test anywhere ever instantiated it), unlike the other
 * two which are only ever built via array-literal instantiation the
 * coverage tool doesn't attribute a line to.
 */
test('UserGroupPair::fromRow narrows a full real row', function (): void {
    $pair = UserGroupPair::fromRow(['user_id' => '3', 'group_id' => '7']);

    expect($pair->userId)->toBe(3);
    expect($pair->groupId)->toBe(7);
});

test('UserGroupPair::fromRow falls back to 0 for a missing/malformed row', function (): void {
    $pair = UserGroupPair::fromRow([]);

    expect($pair->userId)->toBe(0);
    expect($pair->groupId)->toBe(0);
});

test('CreateCategoryResult::failure carries the error message with no category id', function (): void {
    $result = CreateCategoryResult::failure('This name already exists');

    expect($result->success)->toBeFalse();
    expect($result->message)->toBe('This name already exists');
    expect($result->categoryId)->toBeNull();
});

test('CreateCategoryResult::success carries the info message and the new category id', function (): void {
    $result = CreateCategoryResult::success('Album created', 42);

    expect($result->success)->toBeTrue();
    expect($result->message)->toBe('Album created');
    expect($result->categoryId)->toBe(42);
});

test('OldPermalink::fromEntity copies every field straight through', function (): void {
    $permalink = OldPermalink::fromEntity(new OldPermalinkEntity(
        permalink: 'old-album-name',
        catId: 4,
        dateDeleted: '2026-07-01 00:00:00',
        lastHit: '2026-07-15 12:00:00',
        hit: 12,
    ));

    expect($permalink->catId)->toBe(4);
    expect($permalink->permalink)->toBe('old-album-name');
    expect($permalink->dateDeleted)->toBe('2026-07-01 00:00:00');
    expect($permalink->lastHit)->toBe('2026-07-15 12:00:00');
    expect($permalink->hit)->toBe(12);
});

test('OldPermalink::fromEntity leaves null dateDeleted/lastHit as null', function (): void {
    $permalink = OldPermalink::fromEntity(new OldPermalinkEntity(
        permalink: 'my-album',
        catId: 1,
        dateDeleted: null,
        lastHit: null,
        hit: 0,
    ));

    expect($permalink->dateDeleted)->toBeNull();
    expect($permalink->lastHit)->toBeNull();
});

test('OldPermalink::toArray round-trips every field', function (): void {
    $permalink = new OldPermalink(
        catId: 1,
        permalink: 'my-album',
        dateDeleted: null,
        lastHit: null,
        hit: 0,
    );

    expect($permalink->toArray())->toBe([
        'cat_id' => 1,
        'permalink' => 'my-album',
        'date_deleted' => null,
        'last_hit' => null,
        'hit' => 0,
    ]);
});

test('PaginatedResult carries its rows and a known total', function (): void {
    $result = new PaginatedResult(['photo-a', 'photo-b', 'photo-c'], 42);

    expect($result->rows)->toBe(['photo-a', 'photo-b', 'photo-c']);
    expect($result->total)->toBe(42);
});

test('PaginatedResult accepts a null total when SQL_CALC_FOUND_ROWS was skipped', function (): void {
    $result = new PaginatedResult([], null);

    expect($result->rows)->toBe([]);
    expect($result->total)->toBeNull();
});
