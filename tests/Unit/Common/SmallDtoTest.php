<?php

declare(strict_types=1);

use Piwigo\Admin\Category\CreateCategoryResult;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\Dto\UserGroupPair;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Permalink\OldPermalinkEntity;
use Piwigo\Permalink\Projection\OldPermalink;

/**
 * A handful of small DTO/projection classes that had zero dedicated
 * coverage but carry real narrowing/named-constructor logic worth a
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

    expect($pair->userId)->toEqual(UserId::from(3));
    expect($pair->groupId)->toEqual(GroupId::from(7));
});

test('UserGroupPair::fromRow throws for a missing/malformed row', function (): void {
    UserGroupPair::fromRow([]);
})->throws(InvalidArgumentException::class);

test('UserGroupPair::fromRow throws with the exact invalid value when user_id is missing, not masked by group_id', function (): void {
    // Isolates the userId fallback from the groupId one by giving a
    // valid group_id: GroupId::from() never gets a chance to throw
    // first, so this can only pass if UserId::from() itself throws with
    // the real 0 fallback embedded in its message. Kills the userId
    // fallback's DecrementInteger (-1 instead of 0: UserId::from(-1)
    // also throws, but with "got -1", not "got 0") and IncrementInteger
    // (1 instead of 0: UserId::from(1) is valid and does NOT throw at
    // all, letting the whole call succeed instead) -- confirmed live,
    // same technique as GroupTest.php's own "throws when id is null"
    // test for the identical fallback shape.
    UserGroupPair::fromRow(['group_id' => 5]);
})->throws(InvalidArgumentException::class, 'UserId must be a positive integer, got 0');

test('UserGroupPair::fromRow throws with the exact invalid value when group_id is missing, not masked by user_id', function (): void {
    // Mirror of the test above, isolating the groupId fallback: kills
    // the same DecrementInteger/IncrementInteger pair on the groupId
    // side.
    UserGroupPair::fromRow(['user_id' => 5]);
})->throws(InvalidArgumentException::class, 'GroupId must be a positive integer, got 0');

test('CreateCategoryResult::failure carries the error message with no category id', function (): void {
    $result = CreateCategoryResult::failure('This name already exists');

    expect($result->success)->toBeFalse();
    expect($result->message)->toBe('This name already exists');
    expect($result->categoryId)->toBeNull();
});

test('CreateCategoryResult::success carries the info message and the new category id', function (): void {
    $result = CreateCategoryResult::success('Album created', CategoryId::from(42));

    expect($result->success)->toBeTrue();
    expect($result->message)->toBe('Album created');
    expect($result->categoryId)->toEqual(CategoryId::from(42));
});

test('OldPermalink::fromEntity copies every field straight through', function (): void {
    $permalink = OldPermalink::fromEntity(new OldPermalinkEntity(
        permalink: Permalink::from('old-album-name'),
        catId: CategoryId::from(4),
        dateDeleted: SqlDateTime::from('2026-07-01 00:00:00'),
        lastHit: '2026-07-15 12:00:00',
        hit: 12,
    ));

    expect($permalink->catId)->toEqual(CategoryId::from(4));
    expect($permalink->permalink)->toEqual(Permalink::from('old-album-name'));
    expect($permalink->dateDeleted)->toBe('2026-07-01 00:00:00');
    expect($permalink->lastHit)->toBe('2026-07-15 12:00:00');
    expect($permalink->hit)->toBe(12);
});

test('OldPermalink::fromEntity leaves null dateDeleted/lastHit as null', function (): void {
    $permalink = OldPermalink::fromEntity(new OldPermalinkEntity(
        permalink: Permalink::from('my-album'),
        catId: CategoryId::from(1),
        dateDeleted: null,
        lastHit: null,
        hit: 0,
    ));

    expect($permalink->dateDeleted)->toBeNull();
    expect($permalink->lastHit)->toBeNull();
});

test('OldPermalink::toArray round-trips every field', function (): void {
    $permalink = new OldPermalink(
        catId: CategoryId::from(1),
        permalink: Permalink::from('my-album'),
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
