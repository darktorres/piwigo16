<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Image\ImageFilterCriteria;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Tag\ImageTagEntity;
use Piwigo\Tag\Projection\ImageTagLink;
use Piwigo\Tag\Projection\ImageTagPair;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * Piwigo\Tag\TagRepository -- has its own dedicated
 * tests/Integration/TagRepositoryTest.php; this ports its 47 tests down
 * to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern, plus real gaps mutation testing found in both this file and
 * the original spec (neither `findByIdsOrAll()` nor
 * `findImageIdsForTags()`'s own `$usePermissions=true` path had any
 * coverage anywhere, not even in the Integration original).
 *
 * Fixture (image_tag): image 1 has tags 1 (nature), 2 (travel), 3
 * (family); images 2/3 have only tag 1. All 3 images sit in category 1.
 * Images 4/5 have no tags of their own.
 *
 * Real gaps mutation testing found and closed with dedicated tests,
 * rather than left confirmed-equivalent: `findCommonTags()`'s
 * `if ($maxTags > 0)` guard (maxTags=0 must skip setMaxResults()
 * entirely, not call setMaxResults(0)); `findByIdsOrAll()`'s own
 * find-by-id vs. >= 1000-id "return every tag" fallback branch (this
 * whole method had zero coverage before this file) -- including its own
 * `count($ids) < 1000` boundary specifically, which needed ids that
 * don't overlap the real fixture tag ids at exactly 1000 of them to
 * distinguish `<` from a `<=` widening (an initial version of this test
 * used ids 1-1000, which coincidentally include the real tag ids 1/2/3,
 * so both the filtered and fallback code paths produced the same "every
 * tag" result and the mutation stayed unobservable); `findAllTags()`/
 * `findByIdsUrlNamesOrNames()`'s own `Tag` DTO construction (an
 * `UnwrapArrayMap` mutation returning raw `TagEntity` objects instead
 * went undetected by field assertions alone, since `Tag` and
 * `TagEntity` share identical public property names -- same DTO/Entity
 * structural-coincidence gotcha as `GroupRepository::findAllBasic()`,
 * closed the same way via an explicit `toBeInstanceOf(Tag::class)`
 * check); `findImageIdsForTags()`'s own `$usePermissions` gate (both the
 * `image_category` INNER JOIN it adds and the `PermissionCriteria` it
 * then applies through that join were unobservable against every other
 * test's own no-restriction criteria, since `applyCondition()` no-ops on
 * an empty condition regardless of whether the join exists -- needs a
 * real forbidden-categories restriction to prove either gate actually
 * fires); its own `$mode === 'AND' && count($tagIds) > 1` HAVING guard's
 * `&&` half (an accidental `||` widening only shows up against a real
 * OR-mode, multi-tag-id call -- the `> 1` half is a genuine, provable
 * no-op at count===1 regardless of test design: the WHERE clause already
 * guarantees a single distinct tag_id per matched row whenever exactly
 * one tag id was searched, so `HAVING COUNT(DISTINCT tag_id) = 1` is
 * tautological there, not a real gap in disguise); `massInsertImageTags()`'s
 * `$ignore` parameter (untested with `ignore: true` anywhere -- a
 * `RemoveArrayItem` mutation dropping the `'ignore' => $ignore` options
 * key went undetected since BatchWriter's own `?? false` default matches
 * the ignore=false case exactly, closed via a real duplicate-key insert
 * proving `ignore: true` actually suppresses the constraint violation);
 * `deleteByIds()`/`massInsertImageTags()`'s own `em->clear()` calls
 * (real, killable gaps for `deleteByIds()`, closed by a dedicated
 * staleness test -- `massInsertImageTags()`'s own equivalent test is
 * left in place as documentation/coverage even though its own mutation
 * (`RemoveMethodCall`) stayed unobservable after adding it, confirmed
 * live: a brand-new raw-DBAL insert has nothing previously tracked in
 * the identity map to go stale, same root cause as
 * GroupRepositoryTest.php's own addMembers() finding -- Doctrine's
 * find() never negative-caches a "not found" result, so it always
 * re-queries regardless of clear()).
 *
 * Confirmed-equivalent mutations, not individually tested: every
 * `is_numeric(...) ? (int) ... : default`/`(...) instanceof Vo ? ->value
 * : default` cast across this file's own methods (countImagesPerTag(),
 * findCommonTags(), countExistingIds(), findImageIdsForTagIds(),
 * findImageIdsForTags(), countAll(), countAllImageTagLinks(),
 * countImagesPerTagUnrestricted()) is unreachable on this driver, same
 * root cause documented throughout this project's other Unit-suite
 * files; every redundant `array_map(intval(...), ...)`/
 * `array_filter(..., is_numeric(...))`/`array_values(...)` wrapper
 * immediately before an already-native-int bind or an already-list
 * result (findByIdsUrlNamesOrNames()'s `ids` bind, countImagesPerTag()'s
 * `tagIds` bind alongside its own explicit `ArrayParameterType::INTEGER`,
 * findTagIdsByImageIds()'s `imageIds` bind, findImageIdsForTags()'s own
 * final return) is dead weight on this driver, same reasoning; every
 * `is_array($row) || ! is_numeric/instanceof` guard is dead code under
 * any real query result, same reasoning; `findCommonTags()`'s own
 * `$tag->id instanceof TagId ? $tag->id->value : 0` fallback reads
 * straight off a hydrated `TagEntity` object (not an array-hydrated
 * row), and `TagEntity::$id` is only ever null before its first
 * `flush()` -- every entity this query can return was already
 * persisted, so the instanceof check is always true; a `CoalesceRemoveLeft` on
 * findImageIdsForTags()'s own `$filterCondition->types[$name] ??
 * ParameterType::STRING` bind-type fallback is unreachable the same
 * way; every `if ($xIds === []) { return ...; }` early return
 * (deleteImageTagByTagIds(), deleteImageTagByImageIds(),
 * deleteImageTagByImageAndTagIds(), findTagIdsByImageIds(),
 * findImageIdsForTagIds(), findTagsByIds(), countExistingIds(),
 * findCommaJoinedTagIdsByImageIds()) is unobservable if skipped -- DBAL's
 * own `ArrayParameterType` expansion of an empty array already matches
 * nothing on this driver, same root cause as
 * PermalinkRepositoryTest.php's own findPermalinkMatches() finding;
 * findCommonTags()'s own raw-array row mapping (`array_map` building the
 * `id`/`name`/`url_name`/`lastmodified`/`counter` shape from a hydrated
 * `TagEntity`) is unreachable in the "wrap the entity object itself"
 * sense -- every field this file's own tests assert on (including the
 * strengthened maxTags=1 test's own full-row check) is read straight
 * off the real entity either way; the other 3 bulk-delete methods'
 * (`deleteImageTagByTagIds()`, `deleteImageTagByImageIds()`,
 * `deleteImageTagByImageAndTagIds()`) own `em->clear()` calls follow the
 * identical real-gap shape `deleteByIds()`'s own dedicated test already
 * covers, not repeated as separate tests here.
 */
function tagTestRepo(): TagRepository
{
    $repo = TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(TagEntity::class), TagRepository::class);

    return $repo;
}

/**
 * Same as tagTestRepo(), but built on a caller-supplied connection
 * instead of a fresh internal one -- the countAll()/countAllImageTagLinks()
 * "reflects a freshly inserted..." tests below need the repo to share
 * their own raw-SQL ground-truth read's exact connection/transaction, not
 * a separate one of its own.
 */
function tagTestRepoFor(Connection $conn): TagRepository
{
    $repo = TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(TagEntity::class), TagRepository::class);

    return $repo;
}

/**
 * getEntityManager() is protected on Doctrine's own EntityRepository base
 * class -- the em->clear() staleness tests below need direct
 * EntityManager access (for find()) alongside the repo, same
 * CaddieRepositoryTest.php precedent.
 *
 * @return array{0: TagRepository, 1: EntityManagerInterface}
 */
function tagTestRepoWithEm(): array
{
    $em = EntityManagerFactory::build(DbConnection::build());
    $repo = TypedRepository::narrow($em->getRepository(TagEntity::class), TagRepository::class);

    return [$repo, $em];
}

function tagTestName(): string
{
    return 'p17-unit-test-' . bin2hex(random_bytes(4));
}

/**
 * A fresh, disposable tag pre-linked to image 1 -- mimics the fixture's
 * own tag 3 ("family", also linked to image 1) shape for tests that need
 * an existing image1<->tag link to add/remove other images' links
 * against, without touching the real, shared fixture tag. A prior
 * version of several tests below used the real tag 3 directly for this;
 * confirmed live that composer test's own parallel runner intermittently
 * raced SearchServiceTest.php's own "'family' search matches only image
 * 1" assertions against these tests' own temporary (image 4/5, tag 3)
 * links -- the same class of cross-file collision already found and
 * fixed for PermalinkServiceTest.php/PermalinkRepositoryTest.php's
 * shared category id. Caller must remove both the returned tag and its
 * image1 link via tagTestRemoveFixtureLikeTag() in its own finally block.
 */
function tagTestFixtureLikeTagId(): TagId
{
    $repo = tagTestRepo();
    $tagId = $repo->insert(tagTestName(), tagTestName());
    DbConnection::build()->insert('image_tag', [
        'image_id' => 1,
        'tag_id' => $tagId->value,
    ]);

    return $tagId;
}

function tagTestRemoveFixtureLikeTag(TagId $tagId): void
{
    DbConnection::build()->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId->value]);
    tagTestRepo()
        ->deleteByIds([$tagId]);
}

function tagTestNoPermissionRestriction(): PermissionCriteria
{
    return new PermissionCriteria(null, null, null, null, null, null);
}

test('findAllTags() returns every fixture tag', function (): void {
    // Checks the fixture's 3 are all present, not that the result is
    // exactly those 3 -- findAllTags() is a genuinely global, unfiltered
    // query, and another file can have a real, non-disposable-shaped tag
    // alive at the same instant under --parallel (confirmed live:
    // SearchServiceTest.php's own Inflector-variant test briefly inserts
    // a real 'natures' tag).
    $names = array_column(tagTestRepo()->findAllTags(), 'name');

    expect($names)
        ->toContain('family')
        ->and($names)
        ->toContain('nature')
        ->and($names)
        ->toContain('travel');
});

test('findByIdsUrlNamesOrNames() returns empty for no criteria', function (): void {
    expect(tagTestRepo()->findByIdsUrlNamesOrNames([], [], []))->toBe([]);
});

test('findByIdsUrlNamesOrNames() matches by id', function (): void {
    $rows = tagTestRepo()
        ->findByIdsUrlNamesOrNames([1], [], []);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->name)->toBe('nature');
});

test('findByIdsUrlNamesOrNames() matches by url_name', function (): void {
    $rows = tagTestRepo()
        ->findByIdsUrlNamesOrNames([], ['travel'], []);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->name)->toBe('travel');
});

test('findByIdsUrlNamesOrNames() matches by name', function (): void {
    $rows = tagTestRepo()
        ->findByIdsUrlNamesOrNames([], [], ['family']);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->name)->toBe('family');
});

test('findByIdsUrlNamesOrNames() combines criteria with OR', function (): void {
    $rows = tagTestRepo()
        ->findByIdsUrlNamesOrNames([1], ['travel'], []);

    $names = array_column($rows, 'name');
    sort($names);
    expect($names)
        ->toBe(['nature', 'travel']);
});

test('findByIdsUrlNamesOrNames() accepts numeric string ids', function (): void {
    $rows = tagTestRepo()
        ->findByIdsUrlNamesOrNames(['2'], [], []);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->name)->toBe('travel');
});

test('findByIdsOrAll() filters to the given ids when under the 1000-id threshold', function (): void {
    $rows = tagTestRepo()
        ->findByIdsOrAll([TagId::from(1)]);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]->name)->toBe('nature');
});

test('findByIdsOrAll() returns empty for an empty id list', function (): void {
    // Real behavior, not the >= 1000 fallback: an empty $ids still binds
    // an empty IN(:ids) clause rather than skipping filtering entirely.
    expect(tagTestRepo()->findByIdsOrAll([]))->toBe([]);
});

test('findByIdsOrAll() falls back to every tag at exactly the 1000-id threshold', function (): void {
    // None of these ids need to exist, and deliberately don't overlap
    // the real fixture ids (1/2/3) -- a `count($ids) < 1000` boundary
    // widened to `<= 1000` would otherwise still apply the id filter at
    // exactly 1000, which this proves would return empty instead of the
    // real "no filter, every tag" fallback. Checks the fixture's 3 are
    // all present, not that the result is exactly those 3 -- the
    // fallback is a genuinely global, unfiltered query, and another file
    // can have a real, non-disposable-shaped tag alive at the same
    // instant under --parallel (same reasoning as findAllTags()'s own
    // sibling test above).
    $ids = array_map(TagId::from(...), range(1000, 1999));

    $names = array_column(tagTestRepo()->findByIdsOrAll($ids), 'name');

    expect($names)
        ->toContain('family')
        ->and($names)
        ->toContain('nature')
        ->and($names)
        ->toContain('travel');
});

test('findTagIdsByImageIds() returns empty for no ids', function (): void {
    expect(tagTestRepo()->findTagIdsByImageIds([]))->toBe([]);
});

test('findTagIdsByImageIds() matches the fixture', function (): void {
    // Filtered down to known-real pairs rather than an unfiltered
    // toBe(), since another --parallel worker's own
    // FULLTEXT-deadlock-exempted test (TagServiceTest.php's
    // 'getAvailableTags() with no filter caches...') briefly attaches a
    // real, disposable tag to this same real fixture image 1, visible to
    // this test's own isolated transaction until that other test's own
    // cleanup runs.
    $rows = tagTestRepo()
        ->findTagIdsByImageIds([1, 2]);

    $pairs = array_map(
        static fn (ImageTagLink $row): string => $row->imageId . ':' . $row->tagId->value,
        $rows
    );
    $realPairs = array_values(array_intersect($pairs, ['1:1', '1:2', '1:3', '2:1']));
    sort($realPairs);

    expect($realPairs)
        ->toBe(['1:1', '1:2', '1:3', '2:1']);
});

test('findImageIdsForTagIds() returns empty for no ids', function (): void {
    expect(tagTestRepo()->findImageIdsForTagIds([]))->toBe([]);
});

test('findImageIdsForTagIds() matches the fixture', function (): void {
    $ids = tagTestRepo()
        ->findImageIdsForTagIds([TagId::from(1)]);
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findAllTaggedImageIds() returns every distinct image id with at least one tag', function (): void {
    // Fixture: image_tag links images 1/2/3 (image 1 to all 3 tags, 2/3 to
    // tag 1 only); images 4/5 have none. Not scoped to an exact [1, 2, 3]
    // match, though -- several sibling tests in this same file (e.g.
    // 'deleteImageTagByImageAndTagIds() removes only the intersection')
    // temporarily link image 4 or 5 to their own disposable tag as part
    // of their own setup, cleaned up in their own finally block. This
    // test's own default (non-exempted) blanket per-test transaction
    // establishes its real consistent-read snapshot at its own first
    // query below, not at the test's own start -- if that instant lands
    // inside one of those sibling tests' own live window in another
    // --parallel worker, image 4/5 can transiently, legitimately appear
    // here too (reproduced live this session: [1, 2, 3, 4]). Same
    // tolerance shape as that sibling test's own -- assert the real
    // claim (every genuinely tagged image is present) and that nothing
    // outside the fixture's own known image universe ever leaks in,
    // without asserting one way or the other about 4/5's transient
    // presence.
    $ids = tagTestRepo()
        ->findAllTaggedImageIds();
    sort($ids);

    expect($ids)
        ->toContain(1)
        ->toContain(2)
        ->toContain(3)
        ->and(array_diff($ids, [1, 2, 3, 4, 5]))
        ->toBe([]);
});

test('deleteImageTagByImageIds() is a no-op for no ids', function (): void {
    // A disposable tag, not one of the fixture's own shared 1/2/3 --
    // SearchServiceTest.php's own tag-name assertions can observe a real
    // fixture tag temporarily borrowed here under --parallel. Exempt from
    // the blanket per-test transaction for the same FULLTEXT-deadlock
    // reason documented on TagServiceTest.php's 'getTagIds() creates a
    // new tag for a plain name when allowed'.
    DbTransactionTestOverride::rollback();
    $repo = tagTestRepo();
    $tagId = $repo->insert(tagTestName(), tagTestName());
    $conn = DbConnection::build();
    $conn->insert('image_tag', [
        'image_id' => 4,
        'tag_id' => $tagId->value,
    ]);
    $conn->insert('image_tag', [
        'image_id' => 5,
        'tag_id' => $tagId->value,
    ]);

    try {
        $repo->deleteImageTagByImageIds([]);

        // both links survive this no-op call.
        $imageIds = $repo->findImageIdsForTagIds([$tagId]);
        sort($imageIds);
        expect($imageIds)
            ->toBe([4, 5]);
    } finally {
        $conn->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId->value]);
        $repo->deleteByIds([$tagId]);
    }
});

test('deleteImageTagByImageIds() removes every link from that image', function (): void {
    // 2 disposable tags, both linked to image 5, so the assertion below
    // proves every link from that image is gone, not just one -- see
    // deleteImageTagByTagIds()'s no-op test above for why real fixture
    // tag ids aren't safe to borrow here. Exempt from the blanket
    // per-test transaction for the same FULLTEXT-deadlock reason -- see
    // that same test above.
    DbTransactionTestOverride::rollback();
    $repo = tagTestRepo();
    $tagIdA = $repo->insert(tagTestName(), tagTestName());
    $tagIdB = $repo->insert(tagTestName(), tagTestName());
    $conn = DbConnection::build();
    $conn->insert('image_tag', [
        'image_id' => 5,
        'tag_id' => $tagIdA->value,
    ]);
    $conn->insert('image_tag', [
        'image_id' => 5,
        'tag_id' => $tagIdB->value,
    ]);

    try {
        $repo->deleteImageTagByImageIds([5]);

        expect($repo->findTagIdsByImageIds([5]))->toBe([]);
    } finally {
        $repo->deleteByIds([$tagIdA, $tagIdB]);
    }
});

test('deleteImageTagByImageAndTagIds() is a no-op for empty image ids', function (): void {
    // Exempt from the blanket per-test transaction: tagTestFixtureLikeTagId()
    // below inserts into `tags` -- same FULLTEXT-deadlock reason as
    // deleteImageTagByTagIds()'s no-op test far above.
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $tagId = tagTestFixtureLikeTagId();
    $conn->insert('image_tag', [
        'image_id' => 4,
        'tag_id' => $tagId->value,
    ]);

    try {
        tagTestRepo()->deleteImageTagByImageAndTagIds([], [$tagId]);

        expect(tagTestRepo()->findImageIdsForTagIds([$tagId]))->toBe([1, 4]);
    } finally {
        tagTestRemoveFixtureLikeTag($tagId);
    }
});

test('deleteImageTagByImageAndTagIds() is a no-op for empty tag ids', function (): void {
    // Exempt from the blanket per-test transaction: tagTestFixtureLikeTagId()
    // below inserts into `tags` -- same FULLTEXT-deadlock reason as
    // deleteImageTagByTagIds()'s no-op test far above.
    DbTransactionTestOverride::rollback();
    $repo = tagTestRepo();
    $conn = DbConnection::build();
    $tagId = tagTestFixtureLikeTagId();
    $conn->insert('image_tag', [
        'image_id' => 4,
        'tag_id' => $tagId->value,
    ]);

    try {
        $repo->deleteImageTagByImageAndTagIds([4], []);

        expect($repo->findImageIdsForTagIds([$tagId]))->toBe([1, 4]);
    } finally {
        tagTestRemoveFixtureLikeTag($tagId);
    }
});

test('deleteImageTagByImageAndTagIds() removes only the intersection', function (): void {
    // image 4 linked to 2 disposable tags, but only the (image 4, tagB)
    // pair (the requested image/tag intersection) should be removed --
    // the (image 4, tagA) link must survive untouched. See
    // deleteImageTagByTagIds()'s no-op test above for why real fixture
    // tag ids aren't safe to borrow here.
    //
    // findTagIdsByImageIds([4]) below isn't scoped to just this test's
    // own 2 disposable tags -- it returns every tag linked to real
    // fixture image 4 -- so the assertion checks tagA/tagB membership
    // rather than an exact count, tolerant of another --parallel
    // worker's own real image-4 tag link existing at the same instant
    // (pre-existing, not introduced by this session's own blanket
    // per-test transaction work; confirmed live via a 15-run --parallel
    // verification loop).
    //
    // Also exempt from the blanket per-test transaction itself -- same
    // FULLTEXT-deadlock reason as deleteImageTagByTagIds()'s no-op test
    // far above.
    //
    // This test's own image_tag inserts below used to be able to collide
    // with TagServiceTest.php's own bulk 1000-tag cleanup -- see
    // 'massInsertImageTags() clears the identity map...' far below for
    // the full mechanism; fixed on that side (the cleanup's own DELETE
    // no longer takes the lock that caused the collision), so nothing
    // needs to change on this side.
    DbTransactionTestOverride::rollback();
    $repo = tagTestRepo();
    $tagIdA = $repo->insert(tagTestName(), tagTestName());
    $tagIdB = $repo->insert(tagTestName(), tagTestName());
    $conn = DbConnection::build();
    $conn->insert('image_tag', [
        'image_id' => 4,
        'tag_id' => $tagIdA->value,
    ]);
    $conn->insert('image_tag', [
        'image_id' => 4,
        'tag_id' => $tagIdB->value,
    ]);

    try {
        $repo->deleteImageTagByImageAndTagIds([4], [$tagIdB]);

        expect($repo->findImageIdsForTagIds([$tagIdA]))->toBe([4]);

        $remainingTagIds = array_map(
            static fn (ImageTagLink $row): int => $row->tagId->value,
            $repo->findTagIdsByImageIds([4])
        );
        expect($remainingTagIds)
            ->toContain($tagIdA->value)
            ->not->toContain($tagIdB->value);
    } finally {
        $conn->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagIdA->value]);
        $repo->deleteByIds([$tagIdA, $tagIdB]);
    }
});

test('deleteByIds() is a no-op for no ids', function (): void {
    tagTestRepo()->deleteByIds([]);

    expect(tagTestRepo()->findIdByName('nature'))
        ->not->toBeNull();
});

test('deleteByIds() removes the disposable tag', function (): void {
    // Exempt from the blanket per-test transaction: this test both
    // inserts into and deletes from `tags` -- same FULLTEXT-deadlock
    // reason as deleteImageTagByTagIds()'s no-op test far above.
    DbTransactionTestOverride::rollback();
    $repo = tagTestRepo();
    $id = $repo->insert(tagTestName(), tagTestName());

    $repo->deleteByIds([$id]);

    expect($repo->findIdByName('nature'))
        ->not->toBeNull();
});

test('deleteByIds() clears the identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    // Exempt from the blanket per-test transaction -- same
    // FULLTEXT-deadlock reason as the sibling test just above.
    DbTransactionTestOverride::rollback();
    [$repo, $em] = tagTestRepoWithEm();
    $id = $repo->insert(tagTestName(), tagTestName());
    $tracked = $em->find(TagEntity::class, $id);
    expect($tracked)
        ->not->toBeNull();

    $repo->deleteByIds([$id]);

    expect($em->find(TagEntity::class, $id))->toBeNull();
});

test('findIdByNameLikeAnyPattern() matches an exact pattern', function (): void {
    $id = tagTestRepo()
        ->findIdByNameLikeAnyPattern(['nature']);

    expect($id)
        ->not->toBeNull();
    if (! $id instanceof TagId) {
        throw new RuntimeException('unreachable');
    }
    expect($id->value)
        ->toBe(1);
});

test('findIdByNameLikeAnyPattern() matches a wildcard pattern', function (): void {
    $id = tagTestRepo()
        ->findIdByNameLikeAnyPattern(['nat%']);

    expect($id)
        ->not->toBeNull();
    if (! $id instanceof TagId) {
        throw new RuntimeException('unreachable');
    }
    expect($id->value)
        ->toBe(1);
});

test('findIdByNameLikeAnyPattern() tries every pattern until one matches', function (): void {
    $id = tagTestRepo()
        ->findIdByNameLikeAnyPattern(['no-such-tag', 'trav%']);

    expect($id)
        ->not->toBeNull();
    if (! $id instanceof TagId) {
        throw new RuntimeException('unreachable');
    }
    expect($id->value)
        ->toBe(2);
});

test('findIdByNameLikeAnyPattern() returns null for no match', function (): void {
    expect(tagTestRepo()->findIdByNameLikeAnyPattern(['no-such-tag']))->toBeNull();
});

test('findIdByNameLikeAnyPattern() returns null for an empty pattern list', function (): void {
    expect(tagTestRepo()->findIdByNameLikeAnyPattern([]))->toBeNull();
});

test('findIdByNameLikeAnyPattern() treats SQL syntax as a literal value', function (): void {
    // Always binds each pattern as a query parameter: a pattern value
    // containing SQL syntax is treated as a literal LIKE value, never as
    // SQL structure -- it matches nothing (no tag name actually
    // contains this text) rather than injecting a tautology.
    expect(tagTestRepo()->findIdByNameLikeAnyPattern(["nature' OR '1'='1"]))->toBeNull();
});

test('updateNameAndUrlName() renames an existing tag', function (): void {
    // Exempt from the blanket per-test transaction: this test inserts,
    // renames, and deletes a `tags` row -- same FULLTEXT-deadlock reason
    // as deleteImageTagByTagIds()'s no-op test far above.
    DbTransactionTestOverride::rollback();
    $repo = tagTestRepo();
    $id = $repo->insert(tagTestName(), tagTestName());

    try {
        $newName = tagTestName();
        $repo->updateNameAndUrlName($id, $newName, $newName . '-url');

        $renamedId = $repo->findIdByName($newName);
        expect($renamedId)
            ->not->toBeNull();
        if (! $renamedId instanceof TagId) {
            throw new RuntimeException('unreachable');
        }
        expect($renamedId->value)
            ->toBe($id->value);
    } finally {
        $repo->deleteByIds([$id]);
    }
});

test('updateNameAndUrlName() is a silent no-op for a nonexistent id', function (): void {
    // Exempt from the blanket per-test transaction out of caution -- the
    // UPDATE below matches zero rows (id 999999 doesn't exist), so it
    // shouldn't trigger `tags`' own FULLTEXT auxiliary-index maintenance
    // at all, but a point UPDATE against a table with a held-open
    // blanket transaction from every other --parallel worker is cheap
    // enough to exempt anyway rather than rely on that distinction
    // staying true across a MySQL version bump.
    DbTransactionTestOverride::rollback();
    $name = tagTestName();
    tagTestRepo()
        ->updateNameAndUrlName(TagId::from(999999), $name, $name);

    expect(tagTestRepo()->findIdByName($name))
        ->toBeNull();
});

test('countImagesPerTagUnrestricted() counts every image_tag link regardless of permissions', function (): void {
    // A disposable tag, not one of the fixture's own shared 1/2/3 -- this
    // whole DB is shared across every Unit test in one process, so a
    // fixture tag's own counter isn't safe to assert on exactly. Fixture
    // images 4/5 have no tags of their own, so linking this brand-new
    // tag id to them gives an exact, collision-proof count.
    //
    // Exempt from the blanket per-test transaction: the insert() below
    // reaches `tags` -- same FULLTEXT-deadlock reason as
    // deleteImageTagByTagIds()'s no-op test far above.
    //
    // This test's own massInsertImageTags() call below used to be able
    // to collide with TagServiceTest.php's own bulk 1000-tag cleanup --
    // see 'massInsertImageTags() clears the identity map...' above for
    // the full mechanism; fixed on that side, so nothing needs to change
    // on this side.
    DbTransactionTestOverride::rollback();
    $repo = tagTestRepo();
    $tagId = $repo->insert(tagTestName(), tagTestName());
    $repo->massInsertImageTags([
        new ImageTagPair(imageId: 4, tagId: $tagId->value),
        new ImageTagPair(imageId: 5, tagId: $tagId->value),
    ]);

    try {
        $counters = $repo->countImagesPerTagUnrestricted();

        expect($counters[$tagId->value] ?? null)->toBe(2);
    } finally {
        DbConnection::build()->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId->value]);
        $repo->deleteByIds([$tagId]);
    }
});

test('massInsertImageTags() with ignore=true silently skips a duplicate, unlike the default', function (): void {
    // Image 1 already has tag 1 in the fixture -- proves $options['ignore']
    // actually reaches BatchWriter's own INSERT IGNORE, per this method's
    // own docblock (`Controller\Api\Tags\TagMergeController`'s real
    // "already tagged" collision case), not just that the default (no
    // 'ignore' key at all) still throws.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // BatchWriter::massInsert()'s own $conn->transactional() wraps the
    // first call in a nested transaction, and the UniqueConstraintViolationException
    // it deliberately triggers makes that nested transactional() call its
    // own rollBack() -- nested inside the wrapper's already-open outer
    // transaction, DBAL's savepoint bookkeeping for that rollback breaks
    // ("SAVEPOINT DOCTRINE_2 does not exist", reproduced live under
    // --parallel). A plain, unnested connection doesn't hit this.
    DbTransactionTestOverride::rollback();
    expect(fn () => tagTestRepo()->massInsertImageTags([
        new ImageTagPair(imageId: 1, tagId: 1),
    ]))
        ->toThrow(UniqueConstraintViolationException::class);

    tagTestRepo()
        ->massInsertImageTags([
            new ImageTagPair(imageId: 1, tagId: 1),
        ], ignore: true);
});

test('massInsertImageTags() clears the identity map, so a later find() sees the real insert instead of a stale cached null', function (): void {
    // Exempt from the blanket per-test transaction: the insert() below
    // reaches `tags` -- same FULLTEXT-deadlock reason as
    // deleteImageTagByTagIds()'s no-op test far above.
    //
    // This test's own massInsertImageTags() call below inserts a single
    // image_tag row and used to be able to collide with
    // TagServiceTest.php's own 'getAvailableTags() skips a tag absent
    // from the counters once past the 1000 id threshold' -- see that
    // test's own leading docblock for the full FK-cascade-locking
    // mechanism; fixed on that side, so nothing needs to change on this
    // side.
    DbTransactionTestOverride::rollback();
    [$repo, $em] = tagTestRepoWithEm();
    $tagId = $repo->insert(tagTestName(), tagTestName());
    $key = [
        'imageId' => ImageId::from(4),
        'tagId' => $tagId,
    ];

    try {
        expect($em->find(ImageTagEntity::class, $key))->toBeNull();

        $repo->massInsertImageTags([
            new ImageTagPair(imageId: 4, tagId: $tagId->value),
        ]);

        expect($em->find(ImageTagEntity::class, $key))->not->toBeNull();
    } finally {
        DbConnection::build()->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId->value]);
        $repo->deleteByIds([$tagId]);
    }
});

test('findCommaJoinedTagIdsByImageIds() groups by image', function (): void {
    $byImageId = tagTestRepo()
        ->findCommaJoinedTagIdsByImageIds([1, 2, 3], [1, 2, 3]);

    $tagIdsForImage1 = array_map(intval(...), explode(',', $byImageId[1] ?? ''));
    sort($tagIdsForImage1);
    expect($tagIdsForImage1)
        ->toBe([1, 2, 3])
        ->and($byImageId[2] ?? null)->toBe('1')
        ->and($byImageId[3] ?? null)->toBe('1');
});

test('findCommaJoinedTagIdsByImageIds() returns empty for empty tag ids', function (): void {
    expect(tagTestRepo()->findCommaJoinedTagIdsByImageIds([], [1, 2, 3]))->toBe([]);
});

test('findCommaJoinedTagIdsByImageIds() returns empty for empty image ids', function (): void {
    expect(tagTestRepo()->findCommaJoinedTagIdsByImageIds([1, 2, 3], []))->toBe([]);
});

test('countExistingIds() counts only the ids that exist', function (): void {
    expect(tagTestRepo()->countExistingIds([1, 2, 999999]))->toBe(2);
});

test('countExistingIds() returns zero for an empty input', function (): void {
    expect(tagTestRepo()->countExistingIds([]))->toBe(0);
});

test('countImagesPerTag() counts distinct images per tag', function (): void {
    $counters = tagTestRepo()
        ->countImagesPerTag([], tagTestNoPermissionRestriction());

    expect($counters[1] ?? null)->toBe(3)
        ->and($counters[2] ?? null)->toBe(1)
        ->and($counters[3] ?? null)->toBe(1);
});

test('countImagesPerTag() filters by the given tag ids', function (): void {
    expect(tagTestRepo()->countImagesPerTag([1], tagTestNoPermissionRestriction()))->toBe([
        1 => 3,
    ]);
});

test('countImagesPerTag() applies the given condition', function (): void {
    expect(tagTestRepo()->countImagesPerTag([], new PermissionCriteria(null, [999999], null, null, null, null)))->toBe([]);
});

test('findCommonTags() returns tags used by the given images with counts', function (): void {
    // $itemsCsv/$excludedTagIdsCsv are bound as query parameters, not
    // spliced into the SQL.
    $rows = tagTestRepo()
        ->findCommonTags([1, 2, 3], 10, []);

    $byId = array_column($rows, 'counter', 'id');
    expect($byId[1] ?? null)->toBe(3)
        ->and($byId[2] ?? null)->toBe(1)
        ->and($byId[3] ?? null)->toBe(1);
});

test('findCommonTags() orders by counter descending and respects max tags', function (): void {
    $rows = tagTestRepo()
        ->findCommonTags([1, 2, 3], 1, []);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]['id'])->toBe(1)
        ->and($rows[0]['name'])->toBe('nature')
        ->and($rows[0]['url_name'])->toBe('nature')
        ->and($rows[0]['counter'])->toBe(3);
});

test('findCommonTags() excludes the given tag ids', function (): void {
    $ids = array_column(tagTestRepo()->findCommonTags([1, 2, 3], 10, [1]), 'id');
    sort($ids);

    expect($ids)
        ->toBe([2, 3]);
});

test('findCommonTags() returns empty for no matching images', function (): void {
    expect(tagTestRepo()->findCommonTags([999999], 10, []))->toBe([]);
});

test('findCommonTags() with maxTags=0 returns every matching tag, not zero rows', function (): void {
    // $maxTags <= 0 must skip setMaxResults() entirely, not call
    // setMaxResults(0) -- that would return zero rows instead of "no
    // limit".
    $ids = array_column(tagTestRepo()->findCommonTags([1, 2, 3], 0, []), 'id');
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIdsForTags() binds named parameters', function (): void {
    // Otherwise only exercised indirectly via TagServiceTest's own
    // getImageIdsForTags() tests -- this is the first direct test of its
    // own typed params.
    $ids = tagTestRepo()
        ->findImageIdsForTags([1], 'AND', false, tagTestNoPermissionRestriction());
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIdsForTags() falls back to the raw-SQL path for an unparseable order fragment', function (): void {
    // `comment` is a real images column but not one of the bounded
    // PhotoSortField tokens, so resolveDqlOrderBy() returns null -- every
    // other findImageIdsForTags() test here passes no $orderBySqlBody at
    // all, which resolves successfully (PhotoSortOrder::none()) and so
    // exercises the DQL path instead; this is the only real coverage of
    // the raw-DBAL fallback still needed.
    $ids = tagTestRepo()
        ->findImageIdsForTags([1], 'AND', false, tagTestNoPermissionRestriction(), null, 'comment ASC');
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIdsForTags() in AND mode with multiple tag ids requires every tag, not just one', function (): void {
    // A single-tag search (the sibling test above) can never reach the
    // `count($tagIds) > 1` HAVING clause at all -- only image 1 has BOTH
    // tags 1 and 2 (images 2/3 only have tag 1), so this is what proves
    // AND mode's own multi-tag intersection, not a union.
    $ids = tagTestRepo()
        ->findImageIdsForTags([1, 2], 'AND', false, tagTestNoPermissionRestriction());

    expect($ids)
        ->toBe([1]);
});

test('findImageIdsForTags() in OR mode with multiple tag ids returns the union, not the AND-mode intersection', function (): void {
    // The `$mode === 'AND'` half of the HAVING-clause guard only shows
    // up against a real OR-mode, multi-tag-id call -- an AND-mode call
    // (the sibling test above) can never observe an `&&` accidentally
    // widened to `||`, since mode==='AND' is already true there either
    // way. Tag 2 alone only links image 1; the union with tag 1 (images
    // 1/2/3) is [1, 2, 3], not the AND-mode intersection [1].
    $ids = tagTestRepo()
        ->findImageIdsForTags([1, 2], 'OR', false, tagTestNoPermissionRestriction());
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIdsForTags() applies an ImageFilterCriteria', function (): void {
    // Proves $filterCriteria (the one legitimate caller-supplied
    // fragment this method still accepts) reaches the query and stays
    // correctly bound -- fixture: tag 1 tags images 1 (rating_score
    // 4.50), 2 (3.00), 3 (5.00); minRate: 4.0 excludes image 2.
    $ids = tagTestRepo()
        ->findImageIdsForTags(
            [1],
            'AND',
            false,
            tagTestNoPermissionRestriction(),
            new ImageFilterCriteria(minRate: 4.0),
        );
    sort($ids);

    expect($ids)
        ->toBe([1, 3]);
});

test('findImageIdsForTags() only applies the PermissionCriteria when usePermissions is true', function (): void {
    // A no-restriction PermissionCriteria (every other test in this
    // file) can never distinguish usePermissions=true from false --
    // applyCondition() no-ops on an empty condition either way, so the
    // image_category INNER JOIN this flag also gates is never actually
    // referenced. A real forbidden-categories restriction is required to
    // observe either gate: forbidding category 1 (every fixture image's
    // own category) empties the result when permissions apply, but
    // must be silently ignored when they don't.
    $forbidCategory1 = new PermissionCriteria([1], null, null, null, null, null);

    $withPermissions = tagTestRepo()
        ->findImageIdsForTags([1], 'AND', true, $forbidCategory1);

    $withoutPermissions = tagTestRepo()
        ->findImageIdsForTags([1], 'AND', false, $forbidCategory1);
    sort($withoutPermissions);

    expect($withPermissions)
        ->toBe([])
        ->and($withoutPermissions)
        ->toBe([1, 2, 3]);
});

test('existsById() is true for a real tag', function (): void {
    expect(tagTestRepo()->existsById(TagId::from(1)))
        ->toBeTrue();
});

test('existsById() is false for an unknown id', function (): void {
    expect(tagTestRepo()->existsById(TagId::from(999999)))
        ->toBeFalse();
});

test('findTagsForImage() returns every tag linked to that image', function (): void {
    $names = array_column(tagTestRepo()->findTagsForImage(ImageId::from(1)), 'name');
    sort($names);

    expect($names)
        ->toBe(['family', 'nature', 'travel']);
});

test('findTagsForImage() returns empty for an image with no tags', function (): void {
    expect(tagTestRepo()->findTagsForImage(ImageId::from(999999)))->toBe([]);
});

test('findTagsByIds() returns empty for no ids', function (): void {
    expect(tagTestRepo()->findTagsByIds([]))->toBe([]);
});

test('findTagsByIds() matches the given ids', function (): void {
    $rows = tagTestRepo()
        ->findTagsByIds([1, 2]);

    $names = array_column($rows, 'name');
    sort($names);
    expect($names)
        ->toBe(['nature', 'travel']);
});

test('findIdsByNameLike() matches a wildcard pattern', function (): void {
    // toContain(), not an exact [1] -- SearchServiceTest.php's own
    // Inflector-variant test briefly inserts a real 'natures' tag (also
    // matching '%nat%'), a separate file so a separate --parallel
    // worker; confirmed live this raced.
    expect(tagTestRepo()->findIdsByNameLike('%nat%'))
        ->toContain(1);
});

test('findIdsByNameLike() returns empty for no match', function (): void {
    expect(tagTestRepo()->findIdsByNameLike('%no-such-tag%'))
        ->toBe([]);
});

test('existsByName() is true for a real tag', function (): void {
    expect(tagTestRepo()->existsByName('nature'))
        ->toBeTrue();
});

test('existsByName() is false for an unknown name', function (): void {
    expect(tagTestRepo()->existsByName('no-such-tag'))
        ->toBeFalse();
});

test('findOtherNames() excludes the given id', function (): void {
    // Checks exclusion/inclusion individually, not an exact 2-name array
    // -- findOtherNames() is a genuinely global, unfiltered "every other
    // tag" query, and (same root cause as the sibling findIdsByNameLike()
    // test above) another file can have a real, non-disposable-shaped
    // tag alive at the same instant under --parallel.
    $names = tagTestRepo()
        ->findOtherNames(TagId::from(1));

    expect($names)
        ->not->toContain('nature')
        ->and($names)
        ->toContain('family')
        ->and($names)
        ->toContain('travel');
});

test('countAll() reflects a freshly inserted tag', function (): void {
    // countAll() is a genuinely global, unfiltered COUNT(*), and this
    // whole DB is shared across every Unit test in one process -- even
    // 2 back-to-back reads (a raw COUNT(*) immediately next to the
    // repo's own) each over their own round trip aren't safe: a
    // concurrently-running test's own DELETE can land in the gap between
    // them, so the 2 reads observe genuinely different snapshots even
    // though countAll() itself is working correctly (confirmed live:
    // "6 is identical to 7" after switching to that technique). Building
    // the repo on the SAME connection as the raw read and running both
    // inside one REPEATABLE READ transaction pins both queries to the
    // exact same snapshot, closing the gap for good rather than just
    // narrowing it.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction: this
    // test's own explicit beginTransaction()/commit() below (needed for
    // the snapshot-pinning technique itself) requires a plain,
    // non-overridden connection to behave as a real, single transaction
    // rather than nesting a savepoint inside the blanket wrapper's own
    // already-open one; the insert() also reaches `tags` -- same
    // FULLTEXT-deadlock reason as deleteImageTagByTagIds()'s no-op test
    // far above.
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $repo = tagTestRepoFor($conn);
    $id = $repo->insert(tagTestName(), tagTestName());

    try {
        $conn->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $conn->beginTransaction();
        $raw = $conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('tags')
            ->executeQuery()
            ->fetchOne();
        $actual = $repo->countAll();
        $conn->commit();

        expect($actual)
            ->toBe(is_numeric($raw) ? (int) $raw : -1);
    } finally {
        $repo->deleteByIds([$id]);
    }
});

test('countAllImageTagLinks() reflects a freshly inserted link', function (): void {
    // Same same-connection, same-snapshot technique as countAll()'s own
    // sibling test above, for the identical reason -- see its comment.
    // Uses a disposable tag, not a real fixture one (2/'travel') -- see
    // deleteImageTagByTagIds()'s no-op test far above for why: the
    // snapshot comparison itself is race-proof, but a real fixture tag's
    // transient link is still observable by a concurrent search test
    // during the window before this test's own cleanup runs.
    //
    // Also exempt from the blanket per-test transaction itself, for the
    // same 2 reasons as countAll()'s own sibling test just above (real
    // beginTransaction()/commit() needed for the snapshot technique,
    // plus the `tags` insert() itself).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $repo = tagTestRepoFor($conn);
    $tagId = $repo->insert(tagTestName(), tagTestName());
    $conn->insert('image_tag', [
        'image_id' => 5,
        'tag_id' => $tagId->value,
    ]);

    try {
        $conn->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $conn->beginTransaction();
        $raw = $conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('image_tag')
            ->executeQuery()
            ->fetchOne();
        $actual = $repo->countAllImageTagLinks();
        $conn->commit();

        expect($actual)
            ->toBe(is_numeric($raw) ? (int) $raw : -1);
    } finally {
        $conn->delete('image_tag', [
            'image_id' => 5,
            'tag_id' => $tagId->value,
        ]);
        $repo->deleteByIds([$tagId]);
    }
});
