<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Image\ImageFilterCriteria;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Tag\Projection\ImageTagLink;
use Piwigo\Tag\Projection\ImageTagPair;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;
use Piwigo\Tests\Support\DbTransactionTestOverride;

final class TagRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private TagRepository $repo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(TagEntity::class), TagRepository::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testFindAllReturnsEveryFixtureTag(): void
    {
        $names = array_column($this->repo->findAllTags(), 'name');
        sort($names);

        self::assertSame(['family', 'nature', 'travel'], $names);
    }

    public function testFindByIdsUrlNamesOrNamesReturnsEmptyForNoCriteria(): void
    {
        self::assertSame([], $this->repo->findByIdsUrlNamesOrNames([], [], []));
    }

    public function testFindByIdsMatchesById(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames([1], [], []);

        self::assertCount(1, $rows);
        self::assertSame('nature', $rows[0]->name);
    }

    public function testFindByIdsMatchesByUrlName(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames([], ['travel'], []);

        self::assertCount(1, $rows);
        self::assertSame('travel', $rows[0]->name);
    }

    public function testFindByIdsMatchesByName(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames([], [], ['family']);

        self::assertCount(1, $rows);
        self::assertSame('family', $rows[0]->name);
    }

    public function testFindByIdsCombinesCriteriaWithOr(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames([1], ['travel'], []);

        $names = array_column($rows, 'name');
        sort($names);
        self::assertSame(['nature', 'travel'], $names);
    }

    public function testFindByIdsAcceptsNumericStringIds(): void
    {
        $rows = $this->repo->findByIdsUrlNamesOrNames(['2'], [], []);

        self::assertCount(1, $rows);
        self::assertSame('travel', $rows[0]->name);
    }

    public function testFindTagIdsByImageIdsReturnsEmptyForNoIds(): void
    {
        self::assertSame([], $this->repo->findTagIdsByImageIds([]));
    }

    /**
     * fixture image_tag rows (tests/Fixtures/piwigo-17.0.sql): image 1 has
     * tags 1 (nature), 2 (travel), 3 (family); image 2 has only tag 1.
     */
    public function testFindTagIdsByImageIdsMatchesTheFixture(): void
    {
        $rows = $this->repo->findTagIdsByImageIds([1, 2]);

        $pairs = array_map(
            static fn (ImageTagLink $row): string => $row->imageId . ':' . $row->tagId->value,
            $rows
        );
        sort($pairs);

        self::assertSame(['1:1', '1:2', '1:3', '2:1'], $pairs);
    }

    public function testFindImageIdsForTagIdsReturnsEmptyForNoIds(): void
    {
        self::assertSame([], $this->repo->findImageIdsForTagIds([]));
    }

    public function testFindImageIdsForTagIdsMatchesTheFixture(): void
    {
        $ids = $this->repo->findImageIdsForTagIds([TagId::from(1)]);
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function testDeleteImageTagByImageIdsIsANoOpForNoIds(): void
    {
        $this->conn->insert('image_tag', [
            'image_id' => 5,
            'tag_id' => 2,
        ]);

        try {
            $this->repo->deleteImageTagByImageIds([]);

            // tag 2 is also linked to image 1 in the fixture -- both links
            // survive this no-op call.
            self::assertSame([1, 5], $this->repo->findImageIdsForTagIds([TagId::from(2)]));
        } finally {
            $this->conn->delete('image_tag', [
                'image_id' => 5,
                'tag_id' => 2,
            ]);
        }
    }

    public function testDeleteImageTagByImageIdsRemovesEveryLinkFromThatImage(): void
    {
        $this->conn->insert('image_tag', [
            'image_id' => 5,
            'tag_id' => 2,
        ]);
        $this->conn->insert('image_tag', [
            'image_id' => 5,
            'tag_id' => 3,
        ]);

        $this->repo->deleteImageTagByImageIds([5]);

        self::assertSame([], $this->repo->findTagIdsByImageIds([5]));
    }

    public function testDeleteImageTagByImageAndTagIdsIsANoOpForEmptyImageIds(): void
    {
        $this->conn->insert('image_tag', [
            'image_id' => 4,
            'tag_id' => 3,
        ]);

        try {
            $this->repo->deleteImageTagByImageAndTagIds([], [TagId::from(3)]);

            // tag 3 (family) is also linked to image 1 in the fixture --
            // both links survive this no-op call.
            self::assertSame([1, 4], $this->repo->findImageIdsForTagIds([TagId::from(3)]));
        } finally {
            $this->conn->delete('image_tag', [
                'image_id' => 4,
                'tag_id' => 3,
            ]);
        }
    }

    public function testDeleteImageTagByImageAndTagIdsIsANoOpForEmptyTagIds(): void
    {
        $this->conn->insert('image_tag', [
            'image_id' => 4,
            'tag_id' => 3,
        ]);

        try {
            $this->repo->deleteImageTagByImageAndTagIds([4], []);

            // tag 3 (family) is also linked to image 1 in the fixture --
            // both links survive this no-op call.
            self::assertSame([1, 4], $this->repo->findImageIdsForTagIds([TagId::from(3)]));
        } finally {
            $this->conn->delete('image_tag', [
                'image_id' => 4,
                'tag_id' => 3,
            ]);
        }
    }

    public function testDeleteImageTagByImageAndTagIdsRemovesOnlyTheIntersection(): void
    {
        // image 4 linked to both tag 2 and tag 3, but only (image 4, tag 3)
        // (the requested image/tag intersection) should be removed -- the
        // (image 4, tag 2) link must survive untouched.
        $this->conn->insert('image_tag', [
            'image_id' => 4,
            'tag_id' => 2,
        ]);
        $this->conn->insert('image_tag', [
            'image_id' => 4,
            'tag_id' => 3,
        ]);

        try {
            $this->repo->deleteImageTagByImageAndTagIds([4], [TagId::from(3)]);

            // tag 2 is also linked to image 1 in the fixture -- that link
            // survives alongside the (image 4, tag 2) one this test added.
            self::assertSame([1, 4], $this->repo->findImageIdsForTagIds([TagId::from(2)]));

            $remaining = $this->repo->findTagIdsByImageIds([4]);
            self::assertCount(1, $remaining);
            self::assertSame(2, $remaining[0]->tagId->value);
        } finally {
            $this->conn->delete('image_tag', [
                'image_id' => 4,
                'tag_id' => 2,
            ]);
        }
    }

    public function testDeleteByIdsIsANoOpForNoIds(): void
    {
        $this->repo->deleteByIds([]);

        self::assertNotNull($this->repo->findIdByName('nature'));
    }

    public function testDeleteByIdsRemovesTheDisposableTag(): void
    {
        $id = $this->repo->insert('disposable-tag', 'disposable-tag');

        $this->repo->deleteByIds([$id]);

        self::assertNull($this->repo->findIdByName('disposable-tag'));
    }

    public function testFindIdByNameLikeAnyPatternMatchesAnExactPattern(): void
    {
        $id = $this->repo->findIdByNameLikeAnyPattern(['nature']);

        self::assertNotNull($id);
        self::assertSame(1, $id->value);
    }

    public function testFindIdByNameLikeAnyPatternMatchesAWildcardPattern(): void
    {
        $id = $this->repo->findIdByNameLikeAnyPattern(['nat%']);

        self::assertNotNull($id);
        self::assertSame(1, $id->value);
    }

    public function testFindIdByNameLikeAnyPatternTriesEveryPatternUntilOneMatches(): void
    {
        $id = $this->repo->findIdByNameLikeAnyPattern(['no-such-tag', 'trav%']);

        self::assertNotNull($id);
        self::assertSame(2, $id->value);
    }

    public function testFindIdByNameLikeAnyPatternReturnsNullForNoMatch(): void
    {
        self::assertNull($this->repo->findIdByNameLikeAnyPattern(['no-such-tag']));
    }

    public function testFindIdByNameLikeAnyPatternReturnsNullForAnEmptyPatternList(): void
    {
        self::assertNull($this->repo->findIdByNameLikeAnyPattern([]));
    }

    /**
     * findIdByNameLikeAnyPattern() always binds each pattern as a query
     * parameter: a pattern value containing SQL syntax is treated as a
     * literal LIKE value, never as SQL structure -- it matches nothing
     * (no tag name actually contains this text) rather than injecting a
     * tautology.
     */
    public function testFindIdByNameLikeAnyPatternTreatsSqlSyntaxAsALiteralValue(): void
    {
        self::assertNull($this->repo->findIdByNameLikeAnyPattern(["nature' OR '1'='1"]));
    }

    public function testUpdateNameAndUrlNameRenamesAnExistingTag(): void
    {
        $id = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), 'p18-test-' . bin2hex(random_bytes(4)));

        $this->repo->updateNameAndUrlName($id, 'p18-test-renamed', 'p18-test-renamed-url');

        $renamedId = $this->repo->findIdByName('p18-test-renamed');
        self::assertNotNull($renamedId);
        self::assertSame($id->value, $renamedId->value);

        $this->repo->deleteByIds([$id]);
    }

    public function testUpdateNameAndUrlNameIsASilentNoopForANonexistentId(): void
    {
        $this->repo->updateNameAndUrlName(TagId::from(999_999), 'p18-test-should-not-exist', 'p18-test-should-not-exist');

        self::assertNull($this->repo->findIdByName('p18-test-should-not-exist'));
    }

    public function testCountImagesPerTagUnrestrictedCountsEveryImageTagLinkRegardlessOfPermissions(): void
    {
        // A disposable tag, not one of the fixture's own shared 1/2/3 --
        // this whole DB is shared across every Integration suite in one
        // process, so a fixture tag's own counter isn't safe to assert on
        // exactly (another suite's own temporary image_tag row could be
        // alive at the same moment). Fixture images 4/5 have no tags of
        // their own (only image 1/2/3 do -- this file's own class
        // docblock-adjacent fixture description), so linking this
        // brand-new tag id to them gives an exact, collision-proof count.
        $tagId = $this->repo->insert('p18-test-' . bin2hex(random_bytes(4)), 'p18-test-' . bin2hex(random_bytes(4)));
        $this->repo->massInsertImageTags([
            new ImageTagPair(imageId: 4, tagId: $tagId->value),
            new ImageTagPair(imageId: 5, tagId: $tagId->value),
        ]);

        try {
            $counters = $this->repo->countImagesPerTagUnrestricted();

            self::assertSame(2, $counters[$tagId->value] ?? null);
        } finally {
            $this->conn->executeStatement('DELETE FROM image_tag WHERE tag_id = ?', [$tagId->value]);
            $this->repo->deleteByIds([$tagId]);
        }
    }

    // countImagesPerTagUnrestricted()'s own `! is_numeric($tagId)` `continue`
    // guard is not chased here: `image_tag.tag_id` is part of a composite
    // NOT NULL primary key (tests/Fixtures/piwigo-17.0.sql), always a
    // native int under this project's DBAL driver, so it's unreachable
    // through any real fetched row -- same shape as this project's other
    // documented "id is a native-int NOT NULL [primary/foreign] key"
    // residuals (see SearchRepositoryTest's own).

    public function testFindCommaJoinedTagIdsByImageIdsGroupsByImage(): void
    {
        $byImageId = $this->repo->findCommaJoinedTagIdsByImageIds([1, 2, 3], [1, 2, 3]);

        $tagIdsForImage1 = array_map(intval(...), explode(',', $byImageId[1] ?? ''));
        sort($tagIdsForImage1);
        self::assertSame([1, 2, 3], $tagIdsForImage1);
        self::assertSame('1', $byImageId[2] ?? null);
        self::assertSame('1', $byImageId[3] ?? null);
    }

    public function testFindCommaJoinedTagIdsByImageIdsReturnsEmptyForEmptyTagIds(): void
    {
        self::assertSame([], $this->repo->findCommaJoinedTagIdsByImageIds([], [1, 2, 3]));
    }

    public function testFindCommaJoinedTagIdsByImageIdsReturnsEmptyForEmptyImageIds(): void
    {
        self::assertSame([], $this->repo->findCommaJoinedTagIdsByImageIds([1, 2, 3], []));
    }

    // findCommaJoinedTagIdsByImageIds()'s own `! is_numeric($imageId)`
    // `continue` guard is not chased here -- same "native-int NOT NULL
    // primary key" reasoning as countImagesPerTagUnrestricted()'s own
    // guard just above (`image_tag.image_id` is the other half of that
    // same composite primary key).

    public function testCountExistingIdsCountsOnlyTheIdsThatExist(): void
    {
        self::assertSame(2, $this->repo->countExistingIds([1, 2, 999_999]));
    }

    public function testCountExistingIdsReturnsZeroForAnEmptyInput(): void
    {
        self::assertSame(0, $this->repo->countExistingIds([]));
    }

    /**
     * Fixture shape (see this class's own findTagIdsByImageIds test): image
     * 1 has tags 1/2/3, images 2/3 have only tag 1, all three sit in
     * category 1 (image_category).
     */
    public function testCountImagesPerTagCountsDistinctImagesPerTag(): void
    {
        $counters = $this->repo->countImagesPerTag([], self::noPermissionRestriction());

        self::assertSame(3, $counters[1] ?? null);
        self::assertSame(1, $counters[2] ?? null);
        self::assertSame(1, $counters[3] ?? null);
    }

    public function testCountImagesPerTagFiltersByTheGivenTagIds(): void
    {
        self::assertSame([
            1 => 3,
        ], $this->repo->countImagesPerTag([1], self::noPermissionRestriction()));
    }

    public function testCountImagesPerTagAppliesTheGivenCondition(): void
    {
        self::assertSame([], $this->repo->countImagesPerTag([], new PermissionCriteria(null, [999_999], null, null, null, null)));
    }

    /**
     * $itemsCsv/$excludedTagIdsCsv are bound as query parameters, not
     * spliced into the SQL. Same fixture shape as countImagesPerTag()'s own
     * tests just above.
     */
    public function testFindCommonTagsReturnsTagsUsedByTheGivenImagesWithCounts(): void
    {
        $rows = $this->repo->findCommonTags([1, 2, 3], 10, []);

        $byId = array_column($rows, 'counter', 'id');
        self::assertSame(3, $byId[1] ?? null);
        self::assertSame(1, $byId[2] ?? null);
        self::assertSame(1, $byId[3] ?? null);
    }

    public function testFindCommonTagsOrdersByCounterDescendingAndRespectsMaxTags(): void
    {
        $rows = $this->repo->findCommonTags([1, 2, 3], 1, []);

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
        self::assertSame(3, $rows[0]['counter']);
    }

    public function testFindCommonTagsExcludesTheGivenTagIds(): void
    {
        $ids = array_column($this->repo->findCommonTags([1, 2, 3], 10, [1]), 'id');
        sort($ids);

        self::assertSame([2, 3], $ids);
    }

    public function testFindCommonTagsReturnsEmptyForNoMatchingImages(): void
    {
        self::assertSame([], $this->repo->findCommonTags([999_999], 10, []));
    }

    /**
     * findImageIdsForTags() is otherwise only exercised indirectly via
     * TagServiceTest's own getImageIdsForTags() tests -- this is the
     * first direct test of its own typed params.
     */
    public function testFindImageIdsForTagsBindsNamedParameters(): void
    {
        $ids = $this->repo->findImageIdsForTags([1], 'AND', false, self::noPermissionRestriction());
        sort($ids);

        self::assertSame([1, 2, 3], $ids);
    }

    public function testFindImageIdsForTagsAppliesAnImageFilterCriteria(): void
    {
        // Proves $filterCriteria (the one legitimate caller-supplied
        // fragment this method still accepts, see its own docblock)
        // reaches the query and stays correctly bound -- fixture: tag 1
        // tags images 1 (rating_score 4.50), 2 (3.00), 3 (5.00);
        // minRate: 4.0 excludes image 2.
        $ids = $this->repo->findImageIdsForTags(
            [1],
            'AND',
            false,
            self::noPermissionRestriction(),
            new ImageFilterCriteria(minRate: 4.0),
        );
        sort($ids);

        self::assertSame([1, 3], $ids);
    }

    public function testExistsByIdIsTrueForARealTag(): void
    {
        self::assertTrue($this->repo->existsById(1));
    }

    public function testExistsByIdIsFalseForAnUnknownId(): void
    {
        self::assertFalse($this->repo->existsById(999_999));
    }

    public function testFindTagsForImageReturnsEveryTagLinkedToThatImage(): void
    {
        // Fixture image_tag: image 1 has tags 1 (nature), 2 (travel), 3 (family).
        $names = array_column($this->repo->findTagsForImage(ImageId::from(1)), 'name');
        sort($names);

        self::assertSame(['family', 'nature', 'travel'], $names);
    }

    public function testFindTagsForImageReturnsEmptyForAnImageWithNoTags(): void
    {
        self::assertSame([], $this->repo->findTagsForImage(ImageId::from(999_999)));
    }

    public function testFindTagsByIdsReturnsEmptyForNoIds(): void
    {
        self::assertSame([], $this->repo->findTagsByIds([]));
    }

    public function testFindTagsByIdsMatchesTheGivenIds(): void
    {
        $rows = $this->repo->findTagsByIds([1, 2]);

        $names = array_column($rows, 'name');
        sort($names);
        self::assertSame(['nature', 'travel'], $names);
    }

    public function testFindIdsByNameLikeMatchesAWildcardPattern(): void
    {
        self::assertSame([1], $this->repo->findIdsByNameLike('%nat%'));
    }

    public function testFindIdsByNameLikeReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->repo->findIdsByNameLike('%no-such-tag%'));
    }

    public function testExistsByNameIsTrueForARealTag(): void
    {
        self::assertTrue($this->repo->existsByName('nature'));
    }

    public function testExistsByNameIsFalseForAnUnknownName(): void
    {
        self::assertFalse($this->repo->existsByName('no-such-tag'));
    }

    public function testFindOtherNamesExcludesTheGivenId(): void
    {
        $names = $this->repo->findOtherNames(1);
        sort($names);

        self::assertSame(['family', 'travel'], $names);
    }

    public function testCountAllReflectsAFreshlyInsertedTag(): void
    {
        $before = $this->repo->countAll();

        $id = $this->repo->insert('cat14-test-tag', 'cat14-test-tag');

        try {
            self::assertSame($before + 1, $this->repo->countAll());
        } finally {
            $this->repo->deleteByIds([$id]);
        }
    }

    public function testCountAllImageTagLinksReflectsAFreshlyInsertedLink(): void
    {
        $before = $this->repo->countAllImageTagLinks();

        $this->conn->insert('image_tag', [
            'image_id' => 5,
            'tag_id' => 2,
        ]);

        try {
            self::assertSame($before + 1, $this->repo->countAllImageTagLinks());
        } finally {
            $this->conn->delete('image_tag', [
                'image_id' => 5,
                'tag_id' => 2,
            ]);
        }
    }

    /**
     * A {@see PermissionCriteria} with every dimension null -- "no
     * restriction on anything," the direct replacement for the old
     * `SqlCondition::fromRawSql('')` sentinel.
     */
    private static function noPermissionRestriction(): PermissionCriteria
    {
        return new PermissionCriteria(null, null, null, null, null, null);
    }
}
