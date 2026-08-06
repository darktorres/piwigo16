<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Core\Lang;
use Piwigo\Image\ImageEntity;
use Piwigo\Activity\ActivityEntity;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Lang\Translator;
use Piwigo\Core\FilterState;
use Piwigo\Category\CategoryRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Users\CurrentUser;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Users\UserRepository;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageDuplicateField;
use Piwigo\Image\ImageService;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Users\UserService;

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

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();

        $em = EntityManagerFactory::build($this->conn);
        $paths = Kernel::container()->get(Paths::class);
        self::assertInstanceOf(Paths::class, $paths);
        $sessionService = new SessionService($em->getRepository(SessionEntity::class),CurrentConfig::current());
        $imageService = new ImageService(
            Lang::current(),
            $em->getRepository(ImageEntity::class),
            new ActivityService($em->getRepository(ActivityEntity::class)),
            $sessionService,
            new EventDispatcher(),
            CurrentConfig::current(),
            Translator::get(),
            $paths,
        );
        $filterState = Kernel::container()->get(FilterState::class);
        if (! $filterState instanceof FilterState) {
            throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
        }
        $categoryService = new CategoryService(
            Lang::current(),
            new CategoryRepository($em, CurrentConfig::current()),
            new PermissionService(
                new PermissionRepository($em),
                $em->getRepository(GroupEntity::class),
                new CategoryRepository($em, CurrentConfig::current()),
                CurrentUser::current(),
                $filterState,
            ),
            CurrentConfig::current(),
            new EventDispatcher(),
            Translator::get(),
        );
        $caddieRepo = $em->getRepository(CaddieEntity::class);
        $mailer = Kernel::container()->get(MailService::class);
        self::assertInstanceOf(MailService::class, $mailer);
        $userService = new UserService(
            Lang::current(),
            new UserRepository($em, new EventDispatcher(), CurrentConfig::current()),
            $em->getRepository(GroupEntity::class),
            $mailer,
            new ActivityService($em->getRepository(ActivityEntity::class)),
            HtmlServiceTestFactory::build(),
            $this->conn,
            $sessionService,
            new EventDispatcher(),
            new DeploymentPolicy(),
            CurrentUser::current(),
            CurrentConfig::current(),
            new InstallationFlag(),
            new ProcessCache(),
            $paths,
        );

        $this->resolver = new FilterResolver($imageService, $categoryService, $caddieRepo, $userService);
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
        $ids = $this->resolver->duplicatePhotoIds([ImageDuplicateField::Width, ImageDuplicateField::Height]);

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

    public function test_resolve_prefilter_last_import_returns_empty_when_the_images_table_is_empty(): void
    {
        // MAX(date_available) over an empty table is NULL, which must trip
        // the `! is_string($lastDate) || $lastDate === ''` guard rather than
        // building a query around a NULL/empty date.
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images());

            $ids = $this->resolver->resolvePrefilter('last_import', ['prefilter' => 'last_import'], 1, '');

            self::assertSame([], $ids);
        } finally {
            $this->conn->rollBack();
        }
    }

    public function test_resolve_prefilter_last_import_returns_only_images_within_the_recent_period_of_the_true_max_date(): void
    {
        // The true max date_available across the whole table (not just the
        // fixture's own 2026-08-01 images) drives the window, so every id
        // inserted below is chosen to prove the BETWEEN bound is exact: one
        // becomes the new max, one sits exactly on the inclusive lower
        // boundary (max minus 1 day), and one sits 1 second outside it.
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::images() . " (file, path, date_available) VALUES ('last-import-max.jpg', 'upload/last-import-max.jpg', '2026-08-10 12:00:00')"
        );
        $maxId = (int) $this->conn->lastInsertId();

        $this->conn->executeStatement(
            "INSERT INTO " . Tables::images() . " (file, path, date_available) VALUES ('last-import-boundary.jpg', 'upload/last-import-boundary.jpg', '2026-08-09 12:00:00')"
        );
        $boundaryId = (int) $this->conn->lastInsertId();

        $this->conn->executeStatement(
            "INSERT INTO " . Tables::images() . " (file, path, date_available) VALUES ('last-import-excluded.jpg', 'upload/last-import-excluded.jpg', '2026-08-09 11:59:59')"
        );
        $excludedId = (int) $this->conn->lastInsertId();

        try {
            $ids = $this->resolver->resolvePrefilter('last_import', ['prefilter' => 'last_import'], 1, '');
            self::assertNotNull($ids);
            sort($ids);

            // $maxId is inserted before $boundaryId above, so it always gets
            // the lower auto-increment id -- sort()'s ascending order puts
            // it first, not the insertion/narrative order the two ids are
            // introduced in.
            self::assertSame(
                [$maxId, $boundaryId],
                $ids,
                'only the new max-date image and the one exactly on the 1-day-recent boundary should come back; the fixture\'s 2026-08-01 images and the 1-second-early image must be excluded'
            );
        } finally {
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::images() . ' WHERE id IN (?, ?, ?)',
                [$maxId, $boundaryId, $excludedId]
            );
        }
    }

    public function test_resolve_prefilter_no_virtual_album_excludes_images_linked_only_to_a_virtual_category(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::categories() . " (name, dir) VALUES ('Real Album', 'real_album')"
        );
        $realCategoryId = (int) $this->conn->lastInsertId();

        $this->conn->executeStatement(
            "INSERT INTO " . Tables::images() . " (file, path) VALUES ('no-virtual-real.jpg', 'upload/no-virtual-real.jpg')"
        );
        $realImageId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id) VALUES (?, ?)',
            [$realImageId, $realCategoryId]
        );

        $this->conn->executeStatement(
            "INSERT INTO " . Tables::images() . " (file, path) VALUES ('no-virtual-virtual-only.jpg', 'upload/no-virtual-virtual-only.jpg')"
        );
        $virtualOnlyImageId = (int) $this->conn->lastInsertId();
        // Category 1 is one of the fixture's own virtual categories (dir IS
        // NULL, confirmed via direct read of tests/Fixtures/piwigo-17.0.sql).
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id) VALUES (?, 1)',
            [$virtualOnlyImageId]
        );

        try {
            $ids = $this->resolver->resolvePrefilter('no_virtual_album', ['prefilter' => 'no_virtual_album'], 1, '');

            // The fixture's own 5 images are all linked only to virtual
            // categories (1 and 2, both dir IS NULL), so the full result is
            // exactly the one image linked to the new real album.
            self::assertSame([$realImageId], $ids);
        } finally {
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::images() . ' WHERE id IN (?, ?)',
                [$realImageId, $virtualOnlyImageId]
            );
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::categories() . ' WHERE id = ?',
                [$realCategoryId]
            );
        }
    }

    public function test_resolve_prefilter_no_virtual_album_returns_all_images_when_no_virtual_categories_exist(): void
    {
        $virtualCategoryIds = array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $this->conn->createQueryBuilder()
                ->select('id')
                ->from(Tables::categories())
                ->where('dir IS NULL')
                ->executeQuery()
                ->fetchFirstColumn()
        );

        self::assertNotEmpty(
            $virtualCategoryIds,
            'the fixture must have at least one virtual (dir IS NULL) category for this to be a real before/after test'
        );

        $this->conn->executeStatement(
            'UPDATE ' . Tables::categories() . " SET dir = 'temp-real-dir' WHERE dir IS NULL"
        );

        try {
            $ids = $this->resolver->resolvePrefilter('no_virtual_album', ['prefilter' => 'no_virtual_album'], 1, '');
            self::assertNotNull($ids);
            sort($ids);

            self::assertSame(
                [1, 2, 3, 4, 5],
                $ids,
                'with zero virtual categories left, $virtualCategoryIds === [] and every image id is returned unfiltered'
            );
        } finally {
            $this->conn->executeStatement(
                'UPDATE ' . Tables::categories() . ' SET dir = NULL WHERE id IN (' . implode(',', $virtualCategoryIds) . ')'
            );
        }
    }
}
