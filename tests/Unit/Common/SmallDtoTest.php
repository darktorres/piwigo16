<?php

declare(strict_types=1);

use Piwigo\Admin\Category\CreateCategoryResult;
use Piwigo\Common\Dto\UserGroupPair;
use Piwigo\Permalink\Projection\OldPermalink;

/**
 * A handful of small DTO/projection classes that had zero dedicated
 * coverage (see /home/torres/.claude/plans/piped-enchanting-spark.md,
 * Wave 1) but carry real narrowing/named-constructor logic worth a
 * direct test, unlike their pure-data-holder siblings (PaginatedResult,
 * NotificationChannelConfig, the Sensitive attribute) which have no
 * behavior beyond constructor assignment.
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

test('OldPermalink::fromRow narrows a full real row', function (): void {
    $permalink = OldPermalink::fromRow([
        'cat_id' => '4',
        'permalink' => 'old-album-name',
        'date_deleted' => '2026-07-01 00:00:00',
        'last_hit' => '2026-07-15 12:00:00',
        'hit' => '12',
    ]);

    expect($permalink->catId)->toBe(4);
    expect($permalink->permalink)->toBe('old-album-name');
    expect($permalink->dateDeleted)->toBe('2026-07-01 00:00:00');
    expect($permalink->lastHit)->toBe('2026-07-15 12:00:00');
    expect($permalink->hit)->toBe(12);
});

test('OldPermalink::fromRow falls back to defaults for a missing/malformed row', function (): void {
    $permalink = OldPermalink::fromRow([]);

    expect($permalink->catId)->toBe(0);
    expect($permalink->permalink)->toBe('');
    expect($permalink->dateDeleted)->toBeNull();
    expect($permalink->lastHit)->toBeNull();
    expect($permalink->hit)->toBe(0);
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
