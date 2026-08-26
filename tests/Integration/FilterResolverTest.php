<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Admin\BatchManager\Projection\DimensionFilter;
use Piwigo\Admin\BatchManager\Projection\DuplicateFieldFlags;
use Piwigo\Admin\BatchManager\Projection\FilesizeFilter;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\ImageDuplicateField;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Users\UserRepository;
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

        $em = EntityManagerFactory::build($this->conn);
        $paths = Kernel::container()->get(Paths::class);
        self::assertInstanceOf(Paths::class, $paths);
        $sessionService = new SessionService(TypedRepository::narrow($em->getRepository(SessionEntity::class), SessionRepository::class), CurrentConfigTestFactory::get());
        $filterState = Kernel::container()->get(FilterState::class);
        if (! $filterState instanceof FilterState) {
            throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
        }
        $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());
        $permissionService = new PermissionService(
            new PermissionRepository($em),
            TypedRepository::narrow($em->getRepository(GroupEntity::class), GroupRepository::class),
            new CategoryRepository($em, CurrentConfigTestFactory::get()),
            CurrentUserTestFactory::get(),
            $filterState,
            $accessLevelChecker,
        );
        $categoryService = new CategoryService(
            LangTestFactory::get(),
            new CategoryRepository($em, CurrentConfigTestFactory::get()),
            $permissionService,
            CurrentConfigTestFactory::get(),
            new EventDispatcher(),
            TranslatorTestFactory::get(),
            $accessLevelChecker,
        );
        $imageService = new ImageService(
            TypedRepository::narrow($em->getRepository(ImageEntity::class), ImageRepository::class),
            new ActivityService(TypedRepository::narrow($em->getRepository(ActivityEntity::class), ActivityRepository::class)),
            new EventDispatcher(),
            CurrentConfigTestFactory::get(),
            $paths,
            $categoryService,
        );
        $caddieRepo = TypedRepository::narrow($em->getRepository(CaddieEntity::class), CaddieRepository::class);
        $userService = new UserService(
            LangTestFactory::get(),
            new UserRepository($em, new EventDispatcher(), CurrentConfigTestFactory::get()),
            TypedRepository::narrow($em->getRepository(GroupEntity::class), GroupRepository::class),
            new ActivityService(TypedRepository::narrow($em->getRepository(ActivityEntity::class), ActivityRepository::class)),
            HtmlServiceTestFactory::build(),
            $sessionService,
            new EventDispatcher(),
            new DeploymentPolicy(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            new InstallationFlag(),
            new ProcessCache(),
            $paths,
            $em,
            $permissionService,
            $categoryService,
            new PasswordService(new PasswordRepository($em), new DeploymentPolicy()),
        );

        $this->resolver = new FilterResolver($imageService, $categoryService, $caddieRepo, $userService);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testResolvePrefilterFavoritesReturnsTheUsersFavoriteImageIds(): void
    {
        $ids = $this->resolver->resolvePrefilter('favorites', new DuplicateFieldFlags(), true, UserId::from(1), '');

        self::assertSame([1, 3, 5], $ids);
    }

    public function testResolvePrefilterFavoritesReturnsEmptyForAUserWithNoFavorites(): void
    {
        $ids = $this->resolver->resolvePrefilter('favorites', new DuplicateFieldFlags(), true, UserId::from(999999), '');

        self::assertSame([], $ids);
    }

    public function testResolvePrefilterCaddieReturnsTheUsersCaddieImageIds(): void
    {
        $this->conn->createQueryBuilder()
            ->insert('caddie')
            ->values([
                'user_id' => ':user_id',
                'element_id' => ':element_id',
            ])
            ->setParameter('user_id', 1)
            ->setParameter('element_id', 2)
            ->executeStatement();

        try {
            $ids = $this->resolver->resolvePrefilter('caddie', new DuplicateFieldFlags(), true, UserId::from(1), '');

            self::assertSame([2], $ids);
        } finally {
            $this->conn->createQueryBuilder()
                ->delete('caddie')
                ->where('user_id = :user_id')
                ->setParameter('user_id', 1)
                ->executeStatement();
        }
    }

    public function testResolvePrefilterNoTagReturnsOnlyUntaggedImages(): void
    {
        $ids = $this->resolver->resolvePrefilter('no_tag', new DuplicateFieldFlags(), true, UserId::from(1), '');

        self::assertSame([4, 5], $ids);
    }

    public function testResolvePrefilterAllPhotosReturnsEveryImageOnlyWhenItIsTheSoleFilter(): void
    {
        $ids = $this->resolver->resolvePrefilter('all_photos', new DuplicateFieldFlags(), true, UserId::from(1), '');

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testResolvePrefilterAllPhotosReturnsNullWhenOtherFiltersAreAlsoActive(): void
    {
        $ids = $this->resolver->resolvePrefilter(
            'all_photos',
            new DuplicateFieldFlags(),
            false,
            UserId::from(1),
            ''
        );

        self::assertNull($ids, 'legacy only runs the all_photos query when it is the only session filter key');
    }

    public function testResolvePrefilterReturnsNullForPrefiltersHandledElsewhere(): void
    {
        self::assertNull($this->resolver->resolvePrefilter('no_album', new DuplicateFieldFlags(), false, UserId::from(1), ''));
        self::assertNull($this->resolver->resolvePrefilter('no_sync_md5sum', new DuplicateFieldFlags(), false, UserId::from(1), ''));
        self::assertNull($this->resolver->resolvePrefilter('some_plugin_prefilter', new DuplicateFieldFlags(), false, UserId::from(1), ''));
    }

    public function testDuplicatePhotoIdsGroupsEveryFixtureImageBySharedDimensions(): void
    {
        $ids = $this->resolver->duplicatePhotoIds([ImageDuplicateField::Width, ImageDuplicateField::Height]);

        sort($ids);
        self::assertSame([1, 2, 3, 4, 5], $ids, 'every fixture image shares 200x150');
    }

    public function testDuplicatePhotoIdsReturnsEmptyForNoFields(): void
    {
        self::assertSame([], $this->resolver->duplicatePhotoIds([]));
    }

    public function testResolvePrefilterDuplicatesUsesChecksumFieldWhenFlagged(): void
    {
        // Every fixture image has a distinct md5sum, so grouping by it alone
        // never finds a duplicate pair.
        $ids = $this->resolver->resolvePrefilter(
            'duplicates',
            new DuplicateFieldFlags(checksum: true),
            false,
            UserId::from(1),
            ''
        );

        self::assertSame([], $ids);
    }

    public function testCategoryExistsIsTrueForARealCategory(): void
    {
        self::assertTrue($this->resolver->categoryExists(CategoryId::from(1)));
    }

    public function testCategoryExistsIsFalseForANonexistentCategory(): void
    {
        self::assertFalse($this->resolver->categoryExists(CategoryId::from(999999)));
    }

    public function testCategoryImageIdsReturnsTheImagesLinkedToTheGivenCategories(): void
    {
        self::assertSame([1, 2, 3], $this->resolver->categoryImageIds([1]));
        self::assertSame([4, 5], $this->resolver->categoryImageIds([2]));
    }

    public function testCategoryImageIdsReturnsEmptyForNoCategories(): void
    {
        self::assertSame([], $this->resolver->categoryImageIds([]));
    }

    public function testLevelPhotoIdsMatchesTheExactLevelByDefault(): void
    {
        $ids = $this->resolver->levelPhotoIds(0, false, '');

        self::assertSame([1, 2, 3, 4, 5], $ids, 'every fixture image is level 0');
    }

    public function testLevelPhotoIdsFindsNothingAboveTheFixturesLevel(): void
    {
        self::assertSame([], $this->resolver->levelPhotoIds(4, false, ''));
    }

    public function testDimensionPhotoIdsFiltersByARealBound(): void
    {
        $ids = $this->resolver->dimensionPhotoIds(new DimensionFilter(minWidth: 200), '');

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testDimensionPhotoIdsExcludesEverythingAboveTheFixturesWidth(): void
    {
        $ids = $this->resolver->dimensionPhotoIds(new DimensionFilter(minWidth: 9999), '');

        self::assertSame([], $ids);
    }

    public function testDimensionPhotoIdsReturnsNullForNoValidBounds(): void
    {
        // Real bug found via adversarial review of the legacy inline SQL: a
        // crafted ?filter=dimension-<garbage> URL token could leave
        // $bulkFilter['dimension'] set to an empty array, which the legacy
        // code turned into a malformed "WHERE  ORDER BY ..." query. This
        // must return null (skip the filter), never build that query.
        self::assertNull($this->resolver->dimensionPhotoIds(new DimensionFilter(), ''));
    }

    public function testFilesizePhotoIdsFiltersByARealBound(): void
    {
        // filesize is stored in KB; every fixture image is 1 KB.
        $ids = $this->resolver->filesizePhotoIds(new FilesizeFilter(min: 0.0), '');

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testFilesizePhotoIdsExcludesEverythingAboveTheFixturesSize(): void
    {
        self::assertSame([], $this->resolver->filesizePhotoIds(new FilesizeFilter(min: 999.0), ''));
    }

    public function testFilesizePhotoIdsReturnsNullForNoValidBounds(): void
    {
        self::assertNull($this->resolver->filesizePhotoIds(new FilesizeFilter(), ''));
    }

    public function testResolvePrefilterLastImportReturnsEmptyWhenTheImagesTableIsEmpty(): void
    {
        // MAX(date_available) over an empty table is NULL, which must trip
        // the `! is_string($lastDate) || $lastDate === ''` guard rather than
        // building a query around a NULL/empty date.
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement('DELETE FROM images');

            $ids = $this->resolver->resolvePrefilter('last_import', new DuplicateFieldFlags(), true, UserId::from(1), '');

            self::assertSame([], $ids);
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testResolvePrefilterLastImportReturnsOnlyImagesWithinTheRecentPeriodOfTheTrueMaxDate(): void
    {
        // The true max date_available across the whole table (not just the
        // fixture's own 2026-08-01 images) drives the window, so every id
        // inserted below is chosen to prove the BETWEEN bound is exact: one
        // becomes the new max, one sits exactly on the inclusive lower
        // boundary (max minus 1 day), and one sits 1 second outside it.
        $this->conn->executeStatement(
            "INSERT INTO images (file, path, date_available) VALUES ('last-import-max.jpg', 'upload/last-import-max.jpg', '2026-08-10 12:00:00')"
        );
        $maxId = (int) $this->conn->lastInsertId();

        $this->conn->executeStatement(
            "INSERT INTO images (file, path, date_available) VALUES ('last-import-boundary.jpg', 'upload/last-import-boundary.jpg', '2026-08-09 12:00:00')"
        );
        $boundaryId = (int) $this->conn->lastInsertId();

        $this->conn->executeStatement(
            "INSERT INTO images (file, path, date_available) VALUES ('last-import-excluded.jpg', 'upload/last-import-excluded.jpg', '2026-08-09 11:59:59')"
        );
        $excludedId = (int) $this->conn->lastInsertId();

        try {
            $ids = $this->resolver->resolvePrefilter('last_import', new DuplicateFieldFlags(), true, UserId::from(1), '');
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
                'DELETE FROM images WHERE id IN (?, ?, ?)',
                [$maxId, $boundaryId, $excludedId]
            );
        }
    }

    public function testResolvePrefilterNoVirtualAlbumExcludesImagesLinkedOnlyToAVirtualCategory(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO categories (name, dir) VALUES ('Real Album', 'real_album')"
        );
        $realCategoryId = (int) $this->conn->lastInsertId();

        $this->conn->executeStatement(
            "INSERT INTO images (file, path) VALUES ('no-virtual-real.jpg', 'upload/no-virtual-real.jpg')"
        );
        $realImageId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO image_category (image_id, category_id) VALUES (?, ?)',
            [$realImageId, $realCategoryId]
        );

        $this->conn->executeStatement(
            "INSERT INTO images (file, path) VALUES ('no-virtual-virtual-only.jpg', 'upload/no-virtual-virtual-only.jpg')"
        );
        $virtualOnlyImageId = (int) $this->conn->lastInsertId();
        // Category 1 is one of the fixture's own virtual categories (dir IS
        // NULL, confirmed via direct read of tests/Fixtures/piwigo-17.0.sql).
        $this->conn->executeStatement(
            'INSERT INTO image_category (image_id, category_id) VALUES (?, 1)',
            [$virtualOnlyImageId]
        );

        try {
            $ids = $this->resolver->resolvePrefilter('no_virtual_album', new DuplicateFieldFlags(), true, UserId::from(1), '');

            // The fixture's own 5 images are all linked only to virtual
            // categories (1 and 2, both dir IS NULL), so the full result is
            // exactly the one image linked to the new real album.
            self::assertSame([$realImageId], $ids);
        } finally {
            $this->conn->executeStatement(
                'DELETE FROM images WHERE id IN (?, ?)',
                [$realImageId, $virtualOnlyImageId]
            );
            $this->conn->executeStatement(
                'DELETE FROM categories WHERE id = ?',
                [$realCategoryId]
            );
        }
    }

    public function testResolvePrefilterNoVirtualAlbumReturnsAllImagesWhenNoVirtualCategoriesExist(): void
    {
        $virtualCategoryIds = array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $this->conn->createQueryBuilder()
                ->select('id')
                ->from('categories')
                ->where('dir IS NULL')
                ->executeQuery()
                ->fetchFirstColumn()
        );

        self::assertNotEmpty(
            $virtualCategoryIds,
            'the fixture must have at least one virtual (dir IS NULL) category for this to be a real before/after test'
        );

        $this->conn->executeStatement(
            "UPDATE categories SET dir = 'temp-real-dir' WHERE dir IS NULL"
        );

        try {
            $ids = $this->resolver->resolvePrefilter('no_virtual_album', new DuplicateFieldFlags(), true, UserId::from(1), '');
            self::assertNotNull($ids);
            sort($ids);

            self::assertSame(
                [1, 2, 3, 4, 5],
                $ids,
                'with zero virtual categories left, $virtualCategoryIds === [] and every image id is returned unfiltered'
            );
        } finally {
            $this->conn->executeStatement(
                'UPDATE categories SET dir = NULL WHERE id IN (' . implode(',', $virtualCategoryIds) . ')'
            );
        }
    }
}
