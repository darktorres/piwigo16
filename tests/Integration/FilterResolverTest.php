<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Fixture data used by these tests (tests/Fixtures/piwigo-17.0.sql, all
 * confirmed via direct read, not assumed): 5 images (id 1-5), all sharing
 * width=200/height=150/level=0/filesize=1(KB); category 1 holds images
 * [1,2,3], category 2 holds [4,5]; image_tag has images 1-3 tagged, images
 * 4-5 untagged; favorites has user 1 -> images [1,3,5]; caddie starts
 * empty.
 */
final class FilterResolverTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private FilterResolver $resolver;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->resolver = new FilterResolver($this->conn);
    }

    public function test_resolve_prefilter_favorites_returns_the_users_favorite_image_ids(): void
    {
        $ids = $this->resolver->resolvePrefilter('favorites', ['prefilter' => 'favorites'], 1, '');

        self::assertSame([1, 3, 5], $ids);
    }

    public function test_resolve_prefilter_favorites_returns_empty_for_a_user_with_no_favorites(): void
    {
        $ids = $this->resolver->resolvePrefilter('favorites', ['prefilter' => 'favorites'], 999999, '');

        self::assertSame([], $ids);
    }

    public function test_resolve_prefilter_caddie_returns_the_users_caddie_image_ids(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::caddie())
            ->values(['user_id' => ':user_id', 'element_id' => ':element_id'])
            ->setParameter('user_id', 1)
            ->setParameter('element_id', 2)
            ->executeStatement();

        try {
            $ids = $this->resolver->resolvePrefilter('caddie', ['prefilter' => 'caddie'], 1, '');

            self::assertSame([2], $ids);
        } finally {
            $this->conn->createQueryBuilder()
                ->delete(Tables::caddie())
                ->where('user_id = :user_id')
                ->setParameter('user_id', 1)
                ->executeStatement();
        }
    }

    public function test_resolve_prefilter_no_tag_returns_only_untagged_images(): void
    {
        $ids = $this->resolver->resolvePrefilter('no_tag', ['prefilter' => 'no_tag'], 1, '');

        self::assertSame([4, 5], $ids);
    }

    public function test_resolve_prefilter_all_photos_returns_every_image_only_when_it_is_the_sole_filter(): void
    {
        $ids = $this->resolver->resolvePrefilter('all_photos', ['prefilter' => 'all_photos'], 1, '');

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_resolve_prefilter_all_photos_returns_null_when_other_filters_are_also_active(): void
    {
        $ids = $this->resolver->resolvePrefilter(
            'all_photos',
            ['prefilter' => 'all_photos', 'category' => 1],
            1,
            ''
        );

        self::assertNull($ids, 'legacy only runs the all_photos query when it is the only session filter key');
    }

    public function test_resolve_prefilter_returns_null_for_prefilters_handled_elsewhere(): void
    {
        self::assertNull($this->resolver->resolvePrefilter('no_album', [], 1, ''));
        self::assertNull($this->resolver->resolvePrefilter('no_sync_md5sum', [], 1, ''));
        self::assertNull($this->resolver->resolvePrefilter('some_plugin_prefilter', [], 1, ''));
    }

    public function test_duplicate_photo_ids_groups_every_fixture_image_by_shared_dimensions(): void
    {
        $ids = $this->resolver->duplicatePhotoIds(['width', 'height']);

        sort($ids);
        self::assertSame([1, 2, 3, 4, 5], $ids, 'every fixture image shares 200x150');
    }

    public function test_duplicate_photo_ids_returns_empty_for_no_fields(): void
    {
        self::assertSame([], $this->resolver->duplicatePhotoIds([]));
    }

    public function test_resolve_prefilter_duplicates_uses_checksum_field_when_flagged(): void
    {
        // Every fixture image has a distinct md5sum, so grouping by it alone
        // never finds a duplicate pair.
        $ids = $this->resolver->resolvePrefilter(
            'duplicates',
            ['prefilter' => 'duplicates', 'duplicates_checksum' => true],
            1,
            ''
        );

        self::assertSame([], $ids);
    }

    public function test_category_exists_is_true_for_a_real_category(): void
    {
        self::assertTrue($this->resolver->categoryExists(1));
    }

    public function test_category_exists_is_false_for_a_nonexistent_category(): void
    {
        self::assertFalse($this->resolver->categoryExists(999999));
    }

    public function test_category_image_ids_returns_the_images_linked_to_the_given_categories(): void
    {
        self::assertSame([1, 2, 3], $this->resolver->categoryImageIds([1]));
        self::assertSame([4, 5], $this->resolver->categoryImageIds([2]));
    }

    public function test_category_image_ids_returns_empty_for_no_categories(): void
    {
        self::assertSame([], $this->resolver->categoryImageIds([]));
    }

    public function test_level_photo_ids_matches_the_exact_level_by_default(): void
    {
        $ids = $this->resolver->levelPhotoIds(0, false, '');

        self::assertSame([1, 2, 3, 4, 5], $ids, 'every fixture image is level 0');
    }

    public function test_level_photo_ids_finds_nothing_above_the_fixtures_level(): void
    {
        self::assertSame([], $this->resolver->levelPhotoIds(4, false, ''));
    }

    public function test_dimension_photo_ids_filters_by_a_real_bound(): void
    {
        $ids = $this->resolver->dimensionPhotoIds(['min_width' => 200], '');

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_dimension_photo_ids_excludes_everything_above_the_fixtures_width(): void
    {
        $ids = $this->resolver->dimensionPhotoIds(['min_width' => 9999], '');

        self::assertSame([], $ids);
    }

    public function test_dimension_photo_ids_returns_null_for_no_valid_bounds(): void
    {
        // Real bug found via adversarial review of the legacy inline SQL: a
        // crafted ?filter=dimension-<garbage> URL token could leave
        // $bulkFilter['dimension'] set to an empty array, which the legacy
        // code turned into a malformed "WHERE  ORDER BY ..." query. This
        // must return null (skip the filter), never build that query.
        self::assertNull($this->resolver->dimensionPhotoIds([], ''));
    }

    public function test_filesize_photo_ids_filters_by_a_real_bound(): void
    {
        // filesize is stored in KB; every fixture image is 1 KB.
        $ids = $this->resolver->filesizePhotoIds(['min' => 0], '');

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_filesize_photo_ids_excludes_everything_above_the_fixtures_size(): void
    {
        self::assertSame([], $this->resolver->filesizePhotoIds(['min' => 999], ''));
    }

    public function test_filesize_photo_ids_returns_null_for_no_valid_bounds(): void
    {
        self::assertNull($this->resolver->filesizePhotoIds([], ''));
    }
}
