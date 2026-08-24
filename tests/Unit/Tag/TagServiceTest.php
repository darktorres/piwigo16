<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TagCloudCachePool;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\Event\GetTagAltNames;
use Piwigo\Tag\Event\GetTagNameLikeWhere;
use Piwigo\Tag\Projection\TagBrief;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Tag\TagService -- has its own dedicated
 * tests/Integration/TagServiceTest.php (37 tests); this ports them down
 * to the Unit suite via the real-DB-no-HTTP pattern. 236-line gap, 0
 * existing Unit tests before this.
 *
 * Kernel::boot() IS needed here, for the whole file -- TagService's own
 * first constructor arg is Lang, and LangTestFactory::get() has no
 * pre-boot fallback at all (same reasoning already applied to
 * CategoryServiceTest.php).
 *
 * Fixture: category 1 "Sample Album" has images 1-3, category 2 "Nested
 * Sub Album" has images 4-5. image_tag: image 1 has tags 1 (nature), 2
 * (travel), 3 (family); image 2 and 3 each have only tag 1; image 4 and
 * 5 have none.
 *
 * Two tests deliberately diverge from the Integration original to avoid
 * new --parallel hazards against other Unit files sharing this fixture:
 * - the tag-cloud caching test uses a disposable tag instead of the
 *   real tag 1 ("nature") -- TagRepositoryTest.php's own "own your row
 *   space" fix earlier in this campaign exists precisely because
 *   SearchServiceTest.php asserts "nature" maps to exactly images
 *   1/2/3; briefly tagging an arbitrary extra image "nature" here would
 *   reopen that same race.
 * - the 1000-id-threshold test uses a disposable image (placed into
 *   real category 1 via image_category, since countImagesPerTag()
 *   requires that join) rather than any real fixture image --
 *   TagRepositoryTest.php's own disposable-tag tests treat every real
 *   fixture image (1, 4, 5) as a "known image" fixture of their own,
 *   and this test's own 1000-row image_tag insert on a shared real
 *   image was observed disturbing them even while transaction-wrapped.
 */
function tagServiceTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-tagservice-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);

    return $root;
}

/**
 * @return array{0: TagService, 1: Connection, 2: ImageService}
 */
function tagServiceTestServiceConn(?Connection $conn = null): array
{
    $conn ??= DbConnection::build();
    $currentConfig = CurrentConfigTestFactory::get();
    $filterState = new FilterState();

    $currentLogger = Kernel::container()->get(CurrentLogger::class);
    if (! $currentLogger instanceof CurrentLogger) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
    }

    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));

    $tagServiceAccessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig);
    $tagServiceCategoryService = new CategoryService(
        LangTestFactory::get(),
        new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
        new PermissionService(
            new PermissionRepository(EntityManagerFactory::build($conn)),
            TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), GroupRepository::class),
            new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
            CurrentUserTestFactory::get(),
            $filterState,
            $tagServiceAccessLevelChecker
        ),
        $currentConfig,
        EventDispatcherTestFactory::get(),
        new Translator($currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),
        $tagServiceAccessLevelChecker
    );
    $tagServiceImageService = new ImageService(
        TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ImageEntity::class), ImageRepository::class),
        new ActivityService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class), ActivityRepository::class)),
        EventDispatcherTestFactory::get(),
        $currentConfig,
        Paths::fromRoot(sys_get_temp_dir()),
        $tagServiceCategoryService
    );

    $service = new TagService(
        LangTestFactory::get(),
        TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(TagEntity::class), TagRepository::class),
        new PermissionService(
            new PermissionRepository(EntityManagerFactory::build($conn)),
            TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), GroupRepository::class),
            new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
            CurrentUserTestFactory::get(),
            $filterState,
            new AccessLevelChecker(CurrentUserTestFactory::get(), $currentConfig)
        ),
        new ActivityService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class), ActivityRepository::class)),
        EventDispatcherTestFactory::get(),
        CurrentUserTestFactory::get(),
        $currentConfig,
        $currentLogger
    );

    return [$service, $conn, $tagServiceImageService];
}

function tagServiceTestService(): TagService
{
    return tagServiceTestServiceConn()[0];
}

function tagServiceTestImageService(): ImageService
{
    return tagServiceTestServiceConn()[2];
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(tagServiceTestRoot()));
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
    ]));
    CurrentConfigTestFactory::get()->tagsLevels = 5;
});

afterEach(function (): void {
    $tagCloudCachePool = Kernel::container()->get(TagCloudCachePool::class);
    if (! $tagCloudCachePool instanceof TagCloudCachePool) {
        throw new LogicException('Container returned an unexpected type for ' . TagCloudCachePool::class);
    }
    $tagCloudCachePool->clear();
    Kernel::reset();
    CurrentConfigTestFactory::get()->reset();
    CurrentUserTestFactory::get()->reset();
});

test('getAllTags() returns every fixture tag alphabetically', function (): void {
    // getAllTags() returns literally every row in `tags`, not just the 3
    // real fixture ones -- filtered down to just those here rather than
    // an unfiltered toBe(), since another --parallel worker's own
    // FULLTEXT-deadlock-exempted tag-creating test (this file has
    // several: 'getTagIds() creates a new tag...',
    // 'getAvailableTags() with no filter caches...', etc.) does a real,
    // briefly-committed INSERT INTO tags that this test's own isolated
    // transaction CAN observe until that other test's own cleanup runs.
    // What's actually under test is the alphabetical ordering among the
    // real tags, not "nothing else in the whole suite ever creates a tag
    // at this exact instant."
    $names = array_column(tagServiceTestService()->getAllTags(HtmlServiceTestFactory::build()), 'name');
    $realNames = array_values(array_intersect($names, ['family', 'nature', 'travel']));

    expect($realNames)
        ->toBe(['family', 'nature', 'travel']);
});

test('getAllTags() sets name_raw', function (): void {
    $tags = tagServiceTestService()
        ->getAllTags(HtmlServiceTestFactory::build());

    expect($tags[0]['name'])->toBe($tags[0]['name_raw']);
});

test('findTags() delegates to the repository', function (): void {
    $tags = tagServiceTestService()
        ->findTags([1]);

    expect($tags)
        ->toHaveCount(1)
        ->and($tags[0]['name'])->toBe('nature');
});

test('addLevelToTags() returns empty for empty input', function (): void {
    expect(tagServiceTestService()->addLevelToTags([]))->toBe([]);
});

test('addLevelToTags() assigns higher level to higher counter', function (): void {
    $tags = [
        [
            'id' => 1,
            'counter' => 1,
        ],
        [
            'id' => 2,
            'counter' => 100,
        ],
    ];

    $withLevels = tagServiceTestService()
        ->addLevelToTags($tags);

    $levelOne = is_numeric($withLevels[0]['level']) ? (int) $withLevels[0]['level'] : 0;
    $levelTwo = is_numeric($withLevels[1]['level']) ? (int) $withLevels[1]['level'] : 0;
    expect($levelTwo)
        ->toBeGreaterThan($levelOne);
});

test('addLevelToTags() gives the middle level to the average', function (): void {
    $tags = [
        [
            'id' => 1,
            'counter' => 10,
        ],
        [
            'id' => 2,
            'counter' => 10,
        ],
        [
            'id' => 3,
            'counter' => 10,
        ],
    ];

    $withLevels = tagServiceTestService()
        ->addLevelToTags($tags);

    foreach ($withLevels as $tag) {
        expect($tag['level'])->toBe(3);
    }
});

test('tagsIdCompare() orders by id ascending', function (): void {
    $service = tagServiceTestService();

    expect($service->tagsIdCompare([
        'id' => 1,
    ], [
        'id' => 2,
    ]))->toBe(-1)
        ->and($service->tagsIdCompare([
            'id' => 2,
        ], [
            'id' => 1,
        ]))->toBe(1);
});

test('tagsCounterCompare() orders by counter descending', function (): void {
    $service = tagServiceTestService();

    expect($service->tagsCounterCompare([
        'id' => 1,
        'counter' => 1,
    ], [
        'id' => 2,
        'counter' => 5,
    ]))->toBe(1)
        ->and($service->tagsCounterCompare([
            'id' => 1,
            'counter' => 5,
        ], [
            'id' => 2,
            'counter' => 1,
        ]))->toBe(-1);
});

test('tagsCounterCompare() breaks ties by id', function (): void {
    expect(tagServiceTestService()->tagsCounterCompare([
        'id' => 1,
        'counter' => 5,
    ], [
        'id' => 2,
        'counter' => 5,
    ]))
        ->toBe(-1);
});

/**
 * TagCloudCachePool caches getAvailableTags()'s no-filter branch --
 * proven by mutating the underlying data after the first (caching)
 * call, then showing a 2nd no-filter call still returns the stale
 * result while an explicitly-filtered call (which always bypasses this
 * cache) reflects the change. Uses a disposable tag rather than the
 * real tag 1 ("nature") -- see file docblock.
 *
 * Exempt from tests/Pest.php's blanket per-test transaction: `tags`
 * carries a FULLTEXT index (tags_ft_name), and InnoDB's FULLTEXT
 * auxiliary-index maintenance on INSERT holds internal locks that, under
 * the wrapper's whole-test-duration transaction, can deadlock against
 * another --parallel worker's own concurrent tags INSERT -- same
 * mechanism, same fix, as 'getTagIds() creates a new tag for a plain
 * name when allowed' above (reproduced live there: DeadlockException).
 */
test('getAvailableTags() with no filter caches the result via TagCloudCachePool', function (): void {
    DbTransactionTestOverride::rollback();
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(2),
        username: Username::from('fixture_guest'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));

    $conn = DbConnection::build();
    $tagId = null;

    try {
        [$service] = tagServiceTestServiceConn($conn);

        $suffix = bin2hex(random_bytes(4));
        $tagName = "p18-cache-test-{$suffix}";
        $conn->executeStatement(
            'INSERT INTO tags (name, url_name, lastmodified) VALUES (?, ?, NOW())',
            [$tagName, $tagName]
        );
        $tagId = (int) $conn->lastInsertId();

        // Fixture image 1 is already in a public/visible category, and
        // brand new so it can't already carry the disposable tag.
        $imageId = 1;

        $before = array_column($service->getAvailableTags(), 'id');
        expect($before)
            ->not->toContain($tagId);

        $conn->executeStatement(
            'INSERT INTO image_tag (image_id, tag_id) VALUES (?, ?)',
            [$imageId, $tagId]
        );

        // a cache hit must not re-query the DB.
        $cachedAfterMutation = array_column($service->getAvailableTags(), 'id');
        expect($cachedAfterMutation)
            ->not->toContain($tagId);

        // an explicit tag_id filter always bypasses this cache.
        $bypassed = array_column($service->getAvailableTags([$tagId]), 'id');
        expect($bypassed)
            ->toContain($tagId);
    } finally {
        if ($tagId !== null) {
            $conn->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId]);
            $conn->executeStatement('DELETE FROM tags WHERE id = ?', [$tagId]);
        }
    }
});

test('tagIdFromTagName() returns the existing id for a known name', function (): void {
    expect(tagServiceTestService()->tagIdFromTagName('nature'))
        ->toEqual(TagId::from(1));
});

test('tagIdFromTagName() creates a new tag for an unknown name', function (): void {
    // Exempt from tests/Pest.php's blanket per-test transaction: `tags`
    // carries a FULLTEXT index (tags_ft_name), and InnoDB's FULLTEXT
    // auxiliary-index maintenance on INSERT can deadlock against another
    // --parallel worker's own concurrent tags INSERT when held open for
    // a whole test's duration -- same mechanism, same fix, as
    // 'getTagIds() creates a new tag for a plain name when allowed'
    // elsewhere in this file (reproduced live there: DeadlockException).
    DbTransactionTestOverride::rollback();
    [$service, $conn] = tagServiceTestServiceConn();
    $name = 'brand-new-tag-' . uniqid();

    try {
        $id = $service->tagIdFromTagName($name);

        expect(
            $conn->createQueryBuilder()
                ->select('name')
                ->from('tags')
                ->where('id = :id')
                ->setParameter('id', $id->value)
                ->executeQuery()
                ->fetchOne()
        )->toBe($name);
    } finally {
        $conn->executeStatement('DELETE FROM tags WHERE name = ?', [$name]);
    }
});

/**
 * A disposable image row for setTagsOf()-based tests below, rather than
 * a real fixture image -- TagRepositoryTest.php's own disposable-tag
 * tests already treat images 1, 4 and 5 as their own "known image"
 * fixtures, so setTagsOf()'s own blanket delete-then-insert of
 * image_tag rows targets an image no other file has any stake in.
 *
 * A real, plain auto-increment insert (no explicit `id`), not a
 * hand-picked literal or a random value drawn from a fixed numeric
 * range -- this used to draw from a hardcoded range, which turned out
 * to be unsafe for two real, live-reproduced reasons: it originally
 * overlapped ImageServiceTest.php's own 'deleteElementFiles skips a
 * remote row...' test's identical range (both files picked a manual
 * `images.id` from the same numbers with no coordination), and even
 * after splitting the ranges apart, `images.id`'s own AUTO_INCREMENT
 * counter turned out to never reset on a fixture reimport (confirmed
 * live via `SHOW TABLE STATUS` -- reimport only deletes/reloads row
 * data), so any hardcoded "surely below the counter" range is only
 * safe by coincidence of how much history a given database has
 * accumulated. Auto-increment ids can never collide with anything by
 * construction.
 */
function tagServiceTestDisposableImageId(Connection $conn): int
{
    $suffix = bin2hex(random_bytes(4));
    $conn->insert('images', [
        'file' => "p17-unit-test-{$suffix}.jpg",
        'path' => "upload/2026/08/p17-unit-test-{$suffix}.jpg",
    ]);

    return (int) $conn->lastInsertId();
}

/**
 * @return list<TagId>
 */
function tagServiceTestDisposableTagIds(Connection $conn, int $count): array
{
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $name = 'p17-unit-test-tag-' . bin2hex(random_bytes(4));
        $conn->executeStatement(
            'INSERT INTO tags (name, url_name, lastmodified) VALUES (?, ?, NOW())',
            [$name, $name]
        );
        $ids[] = TagId::from((int) $conn->lastInsertId());
    }

    return $ids;
}

/**
 * Real fixture tags 1-3 aren't used here either -- setTagsOf() briefly
 * associating the real "nature" tag with an extra (disposable) image
 * would make TagRepositoryTest.php's own findImageIdsForTagIds([1])
 * exact-list assertion (`[1, 2, 3]`) observe a 4th, spurious id under
 * --parallel. 3 disposable tags stand in for what tags 1/2/3 would
 * otherwise cover.
 *
 * Exempt from tests/Pest.php's blanket per-test transaction: both
 * `images` and `tags` carry a FULLTEXT index, and InnoDB's FULLTEXT
 * auxiliary-index maintenance on INSERT can deadlock against another
 * --parallel worker's own concurrent INSERT into either table when held
 * open for a whole test's duration -- same mechanism, same fix, as
 * 'getTagIds() creates a new tag for a plain name when allowed'
 * (reproduced live there and here: DeadlockException).
 */
test('setTagsOf() creates then overwrites image tag associations', function (): void {
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $imageId = tagServiceTestDisposableImageId($conn);
    $disposableTagIds = tagServiceTestDisposableTagIds($conn, 3);
    assert(count($disposableTagIds) === 3);
    [$tagIdA, $tagIdB, $tagIdC] = $disposableTagIds;
    $service = tagServiceTestService();

    try {
        $service->setTagsOf([
            $imageId => [$tagIdA, $tagIdB],
        ], tagServiceTestImageService());
        expect($service->getImageTagIds([$imageId])[$imageId])->toEqualCanonicalizing([$tagIdA, $tagIdB]);

        // Overwrites, not appends -- tag C replaces A+B entirely.
        $service->setTagsOf([
            $imageId => [$tagIdC],
        ], tagServiceTestImageService());
        expect($service->getImageTagIds([$imageId])[$imageId])->toEqualCanonicalizing([$tagIdC]);
    } finally {
        $conn->executeStatement('DELETE FROM image_tag WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        $conn->executeStatement(
            'DELETE FROM tags WHERE id IN (?, ?, ?)',
            [$tagIdA->value, $tagIdB->value, $tagIdC->value]
        );
    }
});

/**
 * Regression test: compareImageTagLists() used to compare TagId lists
 * with `!==`, which for objects checks identity, not value -- two
 * separately-constructed TagId(1) instances are never `!==`-equal, so
 * this would have wrongly reported every image as changed on every
 * call, even when the tag list genuinely didn't change.
 *
 * Disposable image row -- see tagServiceTestDisposableImageId()'s own
 * docblock for why.
 *
 * Exempt from tests/Pest.php's blanket per-test transaction -- same
 * FULLTEXT auxiliary-index deadlock reasoning as the sibling setTagsOf()
 * test above.
 */
test('compareImageTagLists() reports no change when tags are set to the same values', function (): void {
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $imageId = tagServiceTestDisposableImageId($conn);
    $disposableTagIds = tagServiceTestDisposableTagIds($conn, 2);
    assert(count($disposableTagIds) === 2);
    [$tagIdA, $tagIdB] = $disposableTagIds;
    $service = tagServiceTestService();

    try {
        $service->setTagsOf([
            $imageId => [$tagIdA, $tagIdB],
        ], tagServiceTestImageService());
        $before = $service->getImageTagIds([$imageId]);

        // Re-set the exact same tags -- a genuine no-op from the
        // caller's perspective.
        $service->setTagsOf([
            $imageId => [$tagIdA, $tagIdB],
        ], tagServiceTestImageService());
        $after = $service->getImageTagIds([$imageId]);

        expect($service->compareImageTagLists($before, $after))
            ->toBe([]);
    } finally {
        $conn->executeStatement('DELETE FROM image_tag WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM tags WHERE id IN (?, ?)', [$tagIdA->value, $tagIdB->value]);
    }
});

test('compareImageTagLists() reports the image when tags genuinely change', function (): void {
    $before = [
        4 => [TagId::from(1)],
    ];
    $after = [
        4 => [TagId::from(1), TagId::from(2)],
    ];

    expect(tagServiceTestService()->compareImageTagLists($before, $after))
        ->toBe([4]);
});

test('getOrphanTags() finds a tag with no images past the grace period', function (): void {
    // Exempt from tests/Pest.php's blanket per-test transaction: `tags`
    // carries a FULLTEXT index (tags_ft_name), and InnoDB's FULLTEXT
    // auxiliary-index maintenance on INSERT can deadlock against another
    // --parallel worker's own concurrent tags INSERT when held open for
    // a whole test's duration -- same mechanism, same fix, as
    // 'getTagIds() creates a new tag for a plain name when allowed'
    // below.
    DbTransactionTestOverride::rollback();
    [$service, $conn] = tagServiceTestServiceConn();
    $name = 'orphan-tag-' . uniqid();
    $conn->insert('tags', [
        'name' => $name,
        'url_name' => $name,
        // past the 1-day grace period findOrphanTags() applies.
        'lastmodified' => '2020-01-01 00:00:00',
    ]);
    $id = (int) $conn->lastInsertId();

    try {
        $orphanIds = array_map(static fn (TagBrief $tag): int => $tag->id->value, $service->getOrphanTags());

        expect($orphanIds)
            ->toContain($id);
    } finally {
        $conn->executeStatement('DELETE FROM tags WHERE id = ?', [$id]);
    }
});

test('deleteOrphanTags() removes a genuinely orphaned tag', function (): void {
    // Exempt from the blanket per-test transaction -- same
    // FULLTEXT-deadlock reason as the sibling getOrphanTags() test just
    // above.
    DbTransactionTestOverride::rollback();
    [$service, $conn, $imageService] = tagServiceTestServiceConn();
    $name = 'orphan-tag-' . uniqid();
    $conn->insert('tags', [
        'name' => $name,
        'url_name' => $name,
        'lastmodified' => '2020-01-01 00:00:00',
    ]);
    $id = (int) $conn->lastInsertId();

    $service->deleteOrphanTags(EntityManagerFactory::build($conn), $imageService);

    $remaining = $conn->createQueryBuilder()
        ->select('id')
        ->from('tags')
        ->where('id = :id')
        ->setParameter('id', $id)
        ->executeQuery()
        ->fetchOne();

    expect($remaining)
        ->toBeFalse();
});

test('getAvailableTags() returns empty when the filter matches no images', function (): void {
    expect(tagServiceTestService()->getAvailableTags([999999]))->toBe([]);
});

/**
 * getAvailableTags()'s own `if (! isset($tagCounters[$tag->id->value]))
 * { continue; }` is unreachable below TagRepository::findByIdsOrAll()'s
 * own 1000-id threshold; past 1000 ids it intentionally returns EVERY
 * tag instead, letting the caller filter down by its own id set -- this
 * is that filter-down, reached via 1000 disposable tags all linked to
 * the same fixture image, plus one more disposable tag with zero image
 * links at all. A disposable image, not real fixture image 4 --
 * TagRepositoryTest.php's own disposable-tag tests also treat image 4
 * as a "known real image" fixture. image_category is required too --
 * countImagesPerTag() INNER JOINs it, so an image with no category row
 * is never counted regardless of its image_tag links.
 *
 * Exempt from tests/Pest.php's blanket per-test transaction: `tags`
 * carries a FULLTEXT index (tags_ft_name), and InnoDB's FULLTEXT
 * auxiliary-index maintenance on INSERT holds internal locks that, under
 * the wrapper's whole-test-duration transaction, can deadlock against
 * another --parallel worker's own concurrent tags INSERT -- same
 * mechanism, same fix, as 'getTagIds() creates a new tag for a plain
 * name when allowed' above (reproduced live there: DeadlockException).
 *
 * FIXED (was a KNOWN, ACCEPTED RESIDUAL under --parallel): this test's
 * own cleanup DELETE used to run as one monolithic 1000-row
 * `DELETE FROM tags WHERE id IN (...)` statement. `image_tag.tag_id` has
 * an `ON DELETE CASCADE` FK to `tags.id`, so deleting many parent rows
 * in one statement forces InnoDB to verify referential integrity for
 * every one of those ids in that same statement -- a broad lock/scan of
 * `image_tag`'s own secondary index, confirmed live: switching the
 * DELETE from a `name LIKE` scan to an exact `id IN (...)` list did NOT
 * change the lock pattern one bit, via 8 separate `SHOW ENGINE INNODB
 * STATUS` captures, and chunking the DELETE into smaller batches
 * (tried first) reduced but did not eliminate a fresh live
 * reproduction. TagRepositoryTest.php's own `massInsertImageTags()`-
 * calling tests (e.g. 'massInsertImageTags() clears the identity
 * map...', 'countImagesPerTagUnrestricted() counts every image_tag
 * link...') insert single `image_tag` rows concurrently and could lose
 * that race as either a DeadlockException or (if the loser was this
 * test's own earlier tag-creation transaction) a
 * ForeignKeyConstraintViolationException -- reproduced live at roughly a
 * 1-in-2 rate across two independent 10-run --parallel hunts.
 *
 * The real fix: by the time the cleanup DELETE below runs, the line
 * right before it has ALREADY deleted every `image_tag` row for this
 * test's own disposable image -- unconditionally, in one statement,
 * regardless of which tag each row pointed to, since these 1000 tags
 * were never linked to any other image. Nothing in `image_tag` can
 * possibly reference any of these tag ids by the time the DELETE
 * below runs, which makes InnoDB's own cascade re-verification of that
 * fact provably redundant -- and it's that redundant check's own
 * lock/scan that collides with TagRepositoryTest.php's concurrent
 * writes, not anything the DELETE itself needs. `SET
 * FOREIGN_KEY_CHECKS=0/1` around the DELETE tells InnoDB to skip the
 * check outright, removing the actual contention mechanism instead of
 * shrinking its window (chunking, tried first) or recovering from it
 * after the fact (retry, also tried first and rejected as treating an
 * avoidable lock conflict as though it were unavoidable). This is an
 * established, precedented pattern in this exact codebase, not novel --
 * see `tests/Unit/Category/CategoryServiceTest.php`,
 * `tests/Unit/Notification/NotificationByMailRepositoryTest.php`,
 * `tests/Unit/Feed/FeedRepositoryTest.php`, and
 * `tests/Integration/IntegrationTestCase.php`'s own
 * disableForeignKeyChecks()/enableForeignKeyChecks() helper pair, all of
 * which already disable/re-enable FK checks around a specific statement
 * for the identical session-scoped reason.
 *
 * The bulk-tags INSERT above still runs in 100-row chunks -- a genuinely
 * separate mechanism (InnoDB's FULLTEXT auxiliary-index maintenance lock
 * during INSERT, which has no "disable the check" equivalent the way an
 * FK constraint does), untouched by this fix.
 */
test('getAvailableTags() skips a tag absent from the counters once past the 1000 id threshold', function (): void {
    DbTransactionTestOverride::rollback();
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(2),
        username: Username::from('fixture_guest'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));

    $conn = DbConnection::build();
    $imageId = tagServiceTestDisposableImageId($conn);
    $suffix = bin2hex(random_bytes(4));
    $extraId = null;
    // Pre-initialized (matching $extraId above) -- finally must stay safe
    // to reach even if an exception fires before the assignment below.
    $bulkIds = [];

    try {
        [$service] = tagServiceTestServiceConn($conn);

        $conn->executeStatement('INSERT INTO image_category (image_id, category_id) VALUES (?, 1)', [$imageId]);

        $tagValues = [];
        for ($i = 0; $i < 1000; $i++) {
            $name = "p18-test-bulk-{$suffix}-{$i}";
            $tagValues[] = "('{$name}', '{$name}', NOW())";
        }
        // Chunked (100 rows/statement, 10 statements), not one 1000-row
        // INSERT -- see this test's own leading docblock for why.
        foreach (array_chunk($tagValues, 100) as $chunk) {
            $conn->executeStatement('INSERT INTO tags (name, url_name, lastmodified) VALUES ' . implode(',', $chunk));
        }
        $bulkIds = array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $conn->fetchFirstColumn("SELECT id FROM tags WHERE name LIKE 'p18-test-bulk-{$suffix}-%'")
        );
        expect($bulkIds)
            ->toHaveCount(1000);

        $imageTagValues = [];
        foreach ($bulkIds as $tagId) {
            $imageTagValues[] = "({$imageId}, {$tagId})";
        }
        $conn->executeStatement('INSERT INTO image_tag (image_id, tag_id) VALUES ' . implode(',', $imageTagValues));

        $extraName = "p18-test-bulk-extra-{$suffix}";
        $conn->executeStatement("INSERT INTO tags (name, url_name, lastmodified) VALUES ('{$extraName}', '{$extraName}', NOW())");
        $extraId = (int) $conn->lastInsertId();

        $ids = array_column($service->getAvailableTags(), 'id');

        expect($ids)
            ->not->toContain($extraId)
            ->and($ids)
            ->toContain($bulkIds[0])
            ->and($ids)
            ->toContain($bulkIds[999]);
    } finally {
        $conn->executeStatement('DELETE FROM image_tag WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM image_category WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        // Exact ids (already collected above as $bulkIds), not a LIKE
        // pattern -- `tags` carries no plain B-tree index on `name` (only
        // url_name, lastmodified, and a FULLTEXT index, which the
        // optimizer can't use for LIKE), so a name-pattern DELETE would
        // force a full clustered-index scan that next-key-locks its way
        // across the ENTIRE table -- including rows that don't even match
        // the pattern.
        //
        // FK checks disabled just for this DELETE -- the image_tag
        // DELETE 3 lines above already removed every row that could
        // reference any of these tag ids, so InnoDB's own cascade
        // re-verification is redundant; see this test's own leading
        // docblock for the full reasoning and precedent. Wrapped in its
        // own try/finally so checks are always restored even if the
        // DELETE itself throws.
        if ($bulkIds !== []) {
            $isPostgres = getenv('PIWIGO_DB_DRIVER') === 'pgsql';
            $conn->executeStatement($isPostgres ? 'SET session_replication_role = replica' : 'SET FOREIGN_KEY_CHECKS=0');

            try {
                $conn->executeStatement('DELETE FROM tags WHERE id IN (' . implode(',', $bulkIds) . ')');
            } finally {
                $conn->executeStatement($isPostgres ? 'SET session_replication_role = DEFAULT' : 'SET FOREIGN_KEY_CHECKS=1');
            }
        }
        if ($extraId !== null) {
            $conn->executeStatement('DELETE FROM tags WHERE id = ?', [$extraId]);
        }
    }
});

test('getImageIdsForTags() returns empty for no tag ids', function (): void {
    expect(tagServiceTestService()->getImageIdsForTags([]))->toBe([]);
});

/**
 * fixture image_tag rows: image 1 has tags 1+2+3, image 2 and 3 each
 * have only tag 1 -- AND-mode with tags [1, 2] must only match image 1
 * (the one image with BOTH), while OR-mode with the same 2 tags matches
 * every image that has either one.
 */
test('getImageIdsForTags() AND mode requires every tag', function (): void {
    expect(tagServiceTestService()->getImageIdsForTags([TagId::from(1), TagId::from(2)], 'AND'))->toBe([1]);
});

test('getImageIdsForTags() OR mode matches any tag', function (): void {
    $ids = tagServiceTestService()
        ->getImageIdsForTags([TagId::from(1), TagId::from(2)], 'OR');
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('getCommonTags() returns empty for no items', function (): void {
    expect(tagServiceTestService()->getCommonTags([], 10, HtmlServiceTestFactory::build()))->toBe([]);
});

test('addTags() is a no-op for empty tags or images', function (): void {
    // A disposable image, not real fixture image 5 -- this test's own
    // exact getImageTagIds()===[] assertion is otherwise fragile to
    // TagRepositoryTest.php's own (already self-cleaning, legitimate)
    // disposable-tag tests, which also use image 5 as a "known real
    // image" fixture.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction -- same
    // FULLTEXT auxiliary-index deadlock reasoning as
    // tagServiceTestDisposableImageId()'s other callers in this file.
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $imageId = tagServiceTestDisposableImageId($conn);
    $service = tagServiceTestService();

    try {
        $service->addTags([], [$imageId], tagServiceTestImageService());
        $service->addTags([TagId::from(1)], [], tagServiceTestImageService());

        expect($service->getImageTagIds([$imageId])[$imageId])->toBe([]);
    } finally {
        $conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
    }
});

/**
 * A 2nd call for the same name must return the identical id from the
 * in-process TagIdCache without touching the DB at all -- proven by
 * deleting the underlying row directly in between: if the cache hit
 * didn't short-circuit, the 2nd call would fall through to
 * findIdByName() (returns null, row is gone) and create a brand new tag
 * with a different id instead.
 */
test('tagIdFromTagName() returns the cached id without touching the db', function (): void {
    // Exempt from tests/Pest.php's blanket per-test transaction -- the
    // first call below creates a new tag, same FULLTEXT auxiliary-index
    // deadlock reasoning as 'tagIdFromTagName() creates a new tag for an
    // unknown name' above.
    DbTransactionTestOverride::rollback();
    [$service, $conn] = tagServiceTestServiceConn();
    $name = 'cache-hit-tag-' . uniqid();

    $firstId = $service->tagIdFromTagName($name);
    $conn->executeStatement('DELETE FROM tags WHERE id = ?', [$firstId->value]);

    try {
        $secondId = $service->tagIdFromTagName($name);

        expect($secondId)
            ->toEqual($firstId);
        $tagCount = $conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('tags')
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchOne();
        expect(is_numeric($tagCount) ? (int) $tagCount : -1)
            ->toBe(0, 'the cache hit must never re-insert the deleted row');
    } finally {
        $conn->executeStatement('DELETE FROM tags WHERE name = ?', [$name]);
    }
});

/**
 * A plugin's `get_tag_name_like_where` handler (extended-description
 * sub-name matching) can resolve to an EXISTING tag even when the exact
 * name and url name both miss -- no new tag gets created in that case.
 */
test('tagIdFromTagName() matches via a plugin-supplied LIKE pattern', function (): void {
    EventDispatcherTestFactory::get()->addTypedHandler(
        GetTagNameLikeWhere::class,
        static function (GetTagNameLikeWhere $event): void {
            $event->value = ['nature'];
        }
    );

    try {
        expect(tagServiceTestService()->tagIdFromTagName('totally-unrelated-name-' . uniqid()))
            ->toEqual(TagId::from(1));
    } finally {
        EventDispatcherTestFactory::get()->reset();
    }
});

/**
 * [SEC-19] regression: a plugin handler returning SQL-shaped text is
 * now always bound as a literal LIKE value -- it matches nothing and a
 * brand-new tag gets created, instead of injecting a tautology that
 * would have resolved to an arbitrary existing tag.
 */
test('tagIdFromTagName() treats a plugin-supplied SQL injection attempt as a literal value', function (): void {
    // Exempt from tests/Pest.php's blanket per-test transaction -- this
    // creates a new tag, same FULLTEXT auxiliary-index deadlock
    // reasoning as 'tagIdFromTagName() creates a new tag for an unknown
    // name' above.
    DbTransactionTestOverride::rollback();
    [$service, $conn] = tagServiceTestServiceConn();
    EventDispatcherTestFactory::get()->addTypedHandler(
        GetTagNameLikeWhere::class,
        static function (GetTagNameLikeWhere $event): void {
            $event->value = ["' OR '1'='1"];
        }
    );

    $tagName = 'p18-test-sec19-' . bin2hex(random_bytes(4));

    try {
        $id = $service->tagIdFromTagName($tagName);

        expect($id)
            ->not->toEqual(TagId::from(1))
            ->and(
                $conn->createQueryBuilder()
                    ->select('name')
                    ->from('tags')
                    ->where('id = :id')
                    ->setParameter('id', $id->value)
                    ->executeQuery()
                    ->fetchOne()
            )->toBe($tagName);
    } finally {
        EventDispatcherTestFactory::get()->reset();
        $conn->executeStatement('DELETE FROM tags WHERE name = ?', [$tagName]);
    }
});

test('getImageTagIds() returns empty for no image ids', function (): void {
    expect(tagServiceTestService()->getImageTagIds([]))->toBe([]);
});

test('createTag() returns an error for an existing name', function (): void {
    expect(tagServiceTestService()->createTag('nature')->error)
        ->toBe('Tag "nature" already exists');
});

/**
 * Fixture image_tag: image 1 has tags 1 (nature), 2 (travel), 3
 * (family) -- each rendered as a '~~id~~'-marked, alphabetically sorted
 * entry.
 */
test("getTagListForImage() returns the image's tags sorted alphabetically", function (): void {
    $result = tagServiceTestService()
        ->getTagListForImage(ImageId::from(1), HtmlServiceTestFactory::build());

    expect(array_combine(array_column($result, 'id'), array_column($result, 'name')))
        ->toBe([
            '~~3~~' => 'family',
            '~~1~~' => 'nature',
            '~~2~~' => 'travel',
        ]);
});

test('getTagListForImage() returns empty for an image with no tags', function (): void {
    expect(tagServiceTestService()->getTagListForImage(ImageId::from(999_999), HtmlServiceTestFactory::build()))
        ->toBe([]);
});

test('getTagListByIds() returns the matching tags sorted alphabetically', function (): void {
    $result = tagServiceTestService()
        ->getTagListByIds([1, 2], HtmlServiceTestFactory::build());

    expect(array_combine(array_column($result, 'id'), array_column($result, 'name')))
        ->toBe([
            '~~1~~' => 'nature',
            '~~2~~' => 'travel',
        ]);
});

/**
 * onlyUserLanguage=false additionally surfaces every alt name a
 * `get_tag_alt_names` plugin handler returns, except any alt name
 * identical to the tag's own already-rendered name -- both the
 * original and surviving alt entries share the same '~~id~~' marker.
 */
test('getTagListByIds() includes surviving alt names when not restricted to user language', function (): void {
    EventDispatcherTestFactory::get()->addTypedHandler(
        GetTagAltNames::class,
        static function (GetTagAltNames $event): void {
            $event->value = $event->rawName === 'nature' ? ['nature', 'Nature (alt)'] : [];
        }
    );

    try {
        $result = tagServiceTestService()
            ->getTagListByIds([1], HtmlServiceTestFactory::build(), false);

        $names = array_column($result, 'name');
        sort($names);
        expect($names)
            ->toBe(['Nature (alt)', 'nature']);

        foreach ($result as $row) {
            expect($row['id'])->toBe('~~1~~');
        }
    } finally {
        EventDispatcherTestFactory::get()->reset();
    }
});

test('getTagIds() parses existing tag id markers', function (): void {
    expect(tagServiceTestService()->getTagIds('~~1~~,~~2~~'))
        ->toEqual([TagId::from(1), TagId::from(2)]);
});

test('getTagIds() creates a new tag for a plain name when allowed', function (): void {
    // Exempt from tests/Pest.php's blanket per-test transaction: `tags` carries
    // a FULLTEXT index (tags_ft_name), and InnoDB's FULLTEXT auxiliary-index
    // maintenance on INSERT holds internal locks that, under the wrapper's
    // whole-test-duration transaction, deadlock against another --parallel
    // worker's own concurrent tags INSERT (reproduced live:
    // DeadlockException). A plain INSERT commits and releases immediately, as
    // it did before this session's wrapper existed.
    DbTransactionTestOverride::rollback();
    [$service, $conn] = tagServiceTestServiceConn();
    $name = 'freeform-tag-' . uniqid();

    try {
        $ids = $service->getTagIds([$name]);

        expect($ids)
            ->toHaveCount(1)
            ->and(
                $conn->createQueryBuilder()
                    ->select('name')
                    ->from('tags')
                    ->where('id = :id')
                    ->setParameter('id', $ids[0]->value)
                    ->executeQuery()
                    ->fetchOne()
            )->toBe($name);
    } finally {
        $conn->executeStatement('DELETE FROM tags WHERE name = ?', [$name]);
    }
});

test('getTagIds() skips a plain name when creation is disallowed', function (): void {
    expect(tagServiceTestService()->getTagIds(['brand-new-name-' . uniqid()], false))->toBe([]);
});
