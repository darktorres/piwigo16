<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use LogicException;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\CurrentThemeConfProvider;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Core\WsContext;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Picture\UploadFile;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Metadata\MetadataService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Url\RootPathOverride;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use ReflectionMethod;
use RuntimeException;

/**
 * Real, minimal fake for SrcImage::setThemeConfProvider() -- only reached
 * by addUploadedFile()'s own "cache a derivative" tail (via
 * `new SrcImage($image_infos)`) when the uploaded file's extension isn't a
 * CurrentConfig::pictureExtensions() member and has no representative_ext
 * either (this suite's finfo-fallback test, item 4/5 below): SrcImage
 * falls back to resolving a static mimetype icon, which normally comes
 * from the real theme filesystem tree (Piwigo\Template\Template) -- out of
 * scope to stand up for real here, so this fake returns a fixed,
 * marker-root-relative directory the test itself pre-populates with a
 * throwaway icon file instead.
 */
final class UploadServiceTestThemeConfProvider implements ThemeConfProviderInterface
{
    #[Override]
    public function themeConf(string $key): string
    {
        return $key === 'mime_icon_dir' ? 'mimeicons/' : '';
    }
}

/**
 * UploadService::addUploadedFile() -- the central orchestrating method
 * (~360 lines) had exactly one existing test (the md5_file() read-failure
 * guard, in the Unit suite) before this file: everything else -- the
 * new-photo insert, the duplicate-detection short-circuit, the
 * update-existing-photo path, image-type dispatch, the SVG MIME-mismatch
 * rejection, the forbidden-file-type rejection, and
 * addUploadedFileAddToCategories()'s lounge-threshold/category-association
 * behavior -- does real DB writes (BatchWriter/DbConnection) and real
 * filesystem writes (StorageRegistry), so it needs this real, DB+
 * filesystem-backed Integration suite rather than a mocked Unit test.
 *
 * Every test overrides Piwigo\Core\CurrentPaths to a private, self-created
 * marker directory (never the real repo root) and resets
 * Piwigo\Storage\StorageRegistry so its 'uploads' disk re-resolves under
 * that marker root -- addUploadedFile()'s own StorageRegistry::disk(
 * 'uploads')->writeStream() call would otherwise write real files into
 * this repo's own upload/ tree. Same isolation rationale as
 * tests/Unit/Admin/Upload/UploadServiceTest.php's own marker-directory
 * docblock.
 *
 * [BUG 1] addUploadedFile()'s $image_id-not-null ("update") branch sets
 * $file_path directly from the DB's `path` column, which is stored
 * root-relative (see the "new photo" branch's own preg_replace() against
 * CurrentPathsTestFactory::get()->root, and addFormat()'s own identical "images.path
 * ... is relative, not yet an absolute path" handling elsewhere in this
 * same class) -- every downstream use of $file_path in this method
 * (StorageRegistry::stripRoot(), chmod(), get_rotation_angle()/
 * pwgImageInfos()'s own getimagesize()/filesize() calls) requires an
 * absolute path, exactly like the "new photo" branch's $file_path always
 * is.
 *
 * [BUG 2] The "cache a derivative" SELECT that builds $image_infos for
 * `new SrcImage($image_infos)` must select the `file` column --
 * SrcImage::__construct()'s own docblock states id/path/file are all
 * trusted NOT-NULL DB columns (unlike representative_ext, read via
 * `?? null` as genuinely optional two lines below the same read).
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql), same as
 * MetadataRepositoryTest/BatchUploadHandlerTest: images 1-5 exist before
 * any test runs here; image 1's md5sum is the well-known
 * '2e7ee450c4a4cffe42945205029782b9' (BatchUploadHandlerTest's own
 * duplicate-detection fixture value, reused here for the same reason);
 * category 1 "Sample Album" and category 2 "Nested Sub Album" both exist,
 * with image 1 linked only to category 1 (not 2) in the stock fixture.
 * config's own `lounge_active` row is 'true' in the fixture, but
 * this suite deliberately never calls ConfigService::loadConfFromDb() --
 * ImageStdParams::loadFromDb() (needed for DerivativeImage::url(),
 * addUploadedFile()'s own "cache a derivative" tail) is independent of
 * ConfigService/CurrentConfig entirely (reaches derivative_settings/
 * derivative_size via its own fresh EntityManagerFactory::build(DbConnection::
 * build())) and already falls back to sane built-in sizing when the
 * fixture's rows for those two tables are empty, same as
 * TemplateDefineDerivativeTest's own identical setup, so skipping
 * loadConfFromDb() avoids the fixture's own DB-stored 'lounge_active'
 * value leaking into CurrentConfig::loungeActive()'s in-memory state.
 * Every test controls that flag (and loungeActivateThreshold, forced high
 * by default below to stop the fixture's pre-existing 5 images from
 * accidentally tripping it) explicitly instead.
 */
final class UploadServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private UrlService $urlService;

    private CurrentLogger $currentLogger;

    private StorageRegistry $storageRegistry;

    private ConfigService $configService;

    private EntityManagerInterface $entityManager;

    private ActivityService $activityService;

    private MetadataService $metadataService;

    private ImageService $imageService;

    private CurrentConfig $currentConfig;

    private WsContext $wsContext;

    private CurrentUser $currentUser;

    private string $marker;

    /**
     * @var list<int>
     */
    private array $imageIdsToDelete = [];

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

        $this->marker = sys_get_temp_dir() . '/piwigo-upload-service-integration-test-' . bin2hex(random_bytes(8));
        mkdir($this->marker, 0o777, true);

        // ActivityService/MetadataService/EntityManagerInterface/
        // ImageService are all constructor-injected into UploadService --
        // resolved from the container below just to seed those constructor
        // args, same rationale as CategoryAdminServiceTest's own
        // Kernel::boot() call. Re-boot Kernel (parent::setUp() already
        // booted it against the real repo root) against this test's own
        // throwaway marker root instead: the StorageRegistry resolution
        // below is a container factory that reads Paths::class at first
        // resolution, and CurrentPaths is a pure shim with no state of its
        // own left to rebind after the fact.
        Kernel::reset();
        Kernel::boot(Paths::fromRoot($this->marker));

        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }
        $currentLogger->set(new Logger([
            'severity' => Logger::OFF,
        ]));
        $this->currentLogger = $currentLogger;

        $entityManager = Kernel::container()->get(EntityManagerInterface::class);
        if (! $entityManager instanceof EntityManagerInterface) {
            throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
        }
        $this->entityManager = $entityManager;

        $activityService = Kernel::container()->get(ActivityService::class);
        if (! $activityService instanceof ActivityService) {
            throw new LogicException('Container returned an unexpected type for ' . ActivityService::class);
        }
        $this->activityService = $activityService;

        $metadataService = Kernel::container()->get(MetadataService::class);
        if (! $metadataService instanceof MetadataService) {
            throw new LogicException('Container returned an unexpected type for ' . MetadataService::class);
        }
        $this->metadataService = $metadataService;

        $imageService = Kernel::container()->get(ImageService::class);
        if (! $imageService instanceof ImageService) {
            throw new LogicException('Container returned an unexpected type for ' . ImageService::class);
        }
        $this->imageService = $imageService;

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $this->currentConfig = $currentConfig;

        // Kernel::reset() above also discards the container-shared
        // CurrentUser instance parent::setUp()'s own attachGlobals() seed
        // populated -- without reseeding here, addUploadedFile()'s own
        // AccessControl-reaching call chain throws "not initialised"
        // against this fresh, unseeded container.
        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        $currentUser->attachGlobals();
        $this->currentUser = $currentUser;

        $wsContext = Kernel::container()->get(WsContext::class);
        if (! $wsContext instanceof WsContext) {
            throw new LogicException('Container returned an unexpected type for ' . WsContext::class);
        }
        $this->wsContext = $wsContext;

        $this->conn = DbConnection::build();

        // Deliberately not followed by $configService->loadConfFromDb() --
        // see this class's own docblock.
        $configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get());
        CurrentConfigServiceTestFactory::get()->set($configService);
        $this->configService = $configService;
        // Needed for DerivativeImage::url()'s own ImageStdParams::
        // getByType() call (addUploadedFile()'s "cache a derivative"
        // tail) to resolve real sizing; falls back to sane built-in
        // defaults since the fixture's derivative_settings/derivative_size
        // rows are empty here (see this class's own docblock), matching
        // TemplateDefineDerivativeTest's own identical setup.
        ImageStdParamsTestFactory::get()->loadFromDb();

        // Deliberate baseline, independent of the fixture's own DB-stored
        // 'lounge_active' row (see this class's own docblock) -- every test
        // that cares sets these explicitly; the high threshold here just
        // stops the fixture's pre-existing 5 images from tripping
        // addUploadedFileAddToCategories()'s own count check by accident in
        // every other test.
        CurrentConfigTestFactory::get()->loungeActive = false;
        CurrentConfigTestFactory::get()->loungeActivateThreshold = 1_000_000;

        $htmlService = HtmlServiceTestFactory::build();
        // RootPathOverride must be the one real, container-shared instance
        // -- addUploadedFileAddToCategories()'s own
        // $urlService->setMakeFullUrl() call (on this instance) must be
        // visible to DerivativeImage's/SrcImage's own internal urlService()
        // reads (also container-resolved), which use a *different*
        // UrlService object than this one unless both share the same
        // RootPathOverride. See that class's own docblock.
        $rootPathOverride = Kernel::container()->get(RootPathOverride::class);
        if (! $rootPathOverride instanceof RootPathOverride) {
            throw new LogicException('Container returned an unexpected type for ' . RootPathOverride::class);
        }
        $this->urlService = UrlServiceTestFactory::build($htmlService, $rootPathOverride);
        // See UploadServiceTestThemeConfProvider's own docblock -- harmless
        // for every test but the finfo-fallback one, which only reaches
        // SrcImage's real theme lookup when the stored extension isn't a
        // picture extension and has no representative_ext. Resolved via
        // Piwigo\Core\CurrentThemeConfProvider -- see that wrapper's own
        // docblock for why it's independent of CurrentTemplate.
        $currentThemeConfProvider = Kernel::container()->get(CurrentThemeConfProvider::class);
        if (! $currentThemeConfProvider instanceof CurrentThemeConfProvider) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentThemeConfProvider::class);
        }
        $currentThemeConfProvider->set(new UploadServiceTestThemeConfProvider());

        // StorageRegistry is a container factory binding that reads
        // CurrentPathsTestFactory::get() at first resolution -- resolved
        // here, against the marker-rooted container booted above, so every
        // disk (uploads, derivatives, ...) correctly resolves under the
        // marker root rather than the real project root. Nothing else
        // resolves StorageRegistry::class from this container before this
        // point.
        $storageRegistry = Kernel::container()->get(StorageRegistry::class);
        if (! $storageRegistry instanceof StorageRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . StorageRegistry::class);
        }
        $this->storageRegistry = $storageRegistry;

        $this->imageIdsToDelete = [];
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->imageIdsToDelete !== []) {
            $ids = implode(',', array_map(static fn (int $id): string => (string) $id, $this->imageIdsToDelete));
            // ON DELETE CASCADE on both image_category.image_id and
            // lounge.image_id (see the schema) takes care of any
            // association rows a test created for these ids too.
            $this->conn->executeStatement("DELETE FROM images WHERE id IN ({$ids})");
        }
        // Cleans up the one fixture-image association a test adds (image 1
        // -> category 2, not present in the stock fixture) without
        // disturbing image 1 itself, which every other test in this class
        // still relies on for duplicate-detection.
        $this->conn->executeStatement('DELETE FROM image_category WHERE image_id = 1 AND category_id = 2');
        // Same belt-and-suspenders reasoning as the image_category cleanup
        // above -- addFormat()'s own tests write real image_format
        // rows against the shared fixture image 1, not a throwaway id this
        // class deletes wholesale.
        $this->conn->executeStatement('DELETE FROM image_format WHERE image_id = 1');
        $this->conn->executeStatement("DELETE FROM config WHERE param IN ('lounge_active', 'count_orphans')");

        self::rrmdir($this->marker);

        parent::tearDown();
    }

    private static function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        foreach ($entries !== false ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeImage(string $path, string $format, int $width = 40, int $height = 30): void
    {
        assert($width >= 1 && $height >= 1);
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        $color = imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        if ($color === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        imagefill($img, 0, 0, $color);

        $ok = match ($format) {
            'png' => imagepng($img, $path),
            'jpeg' => imagejpeg($img, $path),
            'gif' => imagegif($img, $path),
            'webp' => imagewebp($img, $path),
            default => throw new InvalidArgumentException("unsupported format {$format}"),
        };

        if (! $ok) {
            throw new RuntimeException("failed to write a {$format} image to {$path}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchImageRow(int $imageId): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM images WHERE id = ' . $imageId);
        self::assertIsArray($row, "image #{$imageId} not found");

        return $row;
    }

    /**
     * Matches this project's own is_numeric()-guarded-cast convention for a
     * DBAL-returned scalar (e.g. HistoryServiceTest.php's own count/value
     * helpers) -- MySQL/DBAL can hand back a numeric column as a string,
     * never a bare int PHPStan can trust a raw (int) cast against.
     */
    private function countRows(string $sql): int
    {
        $value = $this->conn->fetchOne($sql);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function rowInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param callable(UploadFile): UploadFile $handler
     */
    private function uploadFileExt(callable $handler, ?string $representativeExt, string $filePath): ?string
    {
        return $handler(new UploadFile($representativeExt, $filePath))
            ->representativeExt;
    }

    public function testAddUploadedFileInsertsANewPhotoAndWritesItToRealStorage(): void
    {
        $source = $this->marker . '/incoming.png';
        $this->makeImage($source, 'png', 64, 48);
        $expectedMd5 = md5_file($source);
        self::assertIsString($expectedMd5);

        $imageId = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($source, $this->urlService, 'holiday.png');
        $id = $imageId;
        self::assertGreaterThan(0, $id);
        $this->imageIdsToDelete[] = $id;

        $row = $this->fetchImageRow($id);
        self::assertSame('holiday.png', $row['file']);
        self::assertSame(64, $this->rowInt($row['width']));
        self::assertSame(48, $this->rowInt($row['height']));
        self::assertSame($expectedMd5, $row['md5sum']);
        // A plain GD-generated PNG carries no EXIF orientation (and isn't
        // even a JPEG) -- PwgImage::get_rotation_angle() returns null for
        // any non-JPEG source, which get_rotation_code_from_angle() maps to
        // rotation code 0.
        self::assertSame(0, $this->rowInt($row['rotation']));
        self::assertIsString($row['path']);
        self::assertStringStartsWith('upload/', $row['path']);
        self::assertStringEndsWith('.png', $row['path']);

        $absolutePath = $this->marker . '/' . $row['path'];
        self::assertFileExists($absolutePath);
        self::assertGreaterThan(0, filesize($absolutePath));

        // move_uploaded_file()/rename() semantics: the source is consumed.
        self::assertFileDoesNotExist($source);
    }

    public function testAddUploadedFileShortCircuitsOnDuplicateMd5sumAndAssociatesCategories(): void
    {
        $source = $this->marker . '/dup-source.jpg';
        file_put_contents($source, 'duplicate-upload-bytes');

        $countBefore = $this->countRows('SELECT COUNT(*) FROM images');

        $result = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile(
            $source,
            $this->urlService,
            'dup.jpg',
            categories: [2],
            // Matches tests/Fixtures/piwigo-17.0.sql's own image #1
            // (fixture-photo-1.jpg) exactly, same fixture value
            // BatchUploadHandlerTest.php's own duplicate-detection test
            // already relies on.
            original_md5sum: '2e7ee450c4a4cffe42945205029782b9',
        );

        self::assertSame(1, $result);
        self::assertFileDoesNotExist($source);

        // No second row was inserted -- the short-circuit returned the
        // existing image_id instead.
        $countAfter = $this->countRows('SELECT COUNT(*) FROM images');
        self::assertSame($countBefore, $countAfter);

        // Image 1 is only linked to category 1 in the stock fixture --
        // addUploadedFileAddToCategories() still associates it to the
        // $categories given here, even on the short-circuit path.
        $linked = $this->countRows('SELECT COUNT(*) FROM image_category WHERE image_id = 1 AND category_id = 2');
        self::assertSame(1, $linked);
    }

    /**
     * Regression test for [BUG 1]/[BUG 2] -- see this class's own
     * docblock. Without both invariants holding, this exact call throws
     * ("Undefined array key 'file'" from the SrcImage read, or a
     * getimagesize() "Failed to open stream" from an un-prefixed relative
     * $file_path, depending on which line executes first).
     */
    public function testAddUploadedFileUpdatesAnExistingPhotoInPlace(): void
    {
        $service = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get());

        $firstSource = $this->marker . '/first.png';
        $this->makeImage($firstSource, 'png', 40, 30);
        $firstId = $service->addUploadedFile($firstSource, $this->urlService, 'first.png');
        $id = $firstId;
        $this->imageIdsToDelete[] = $id;

        $countBefore = $this->countRows('SELECT COUNT(*) FROM images');

        $secondSource = $this->marker . '/second.png';
        $this->makeImage($secondSource, 'png', 20, 15);
        $expectedMd5 = md5_file($secondSource);
        self::assertIsString($expectedMd5);

        $result = $service->addUploadedFile($secondSource, $this->urlService, 'second.png', image_id: $id);

        // Same id back -- an UPDATE, not a fresh INSERT.
        self::assertSame($id, $result);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM images');
        self::assertSame($countBefore, $countAfter);

        $row = $this->fetchImageRow($id);
        self::assertSame(20, $this->rowInt($row['width']));
        self::assertSame(15, $this->rowInt($row['height']));
        self::assertSame($expectedMd5, $row['md5sum']);
        self::assertSame('second.png', $row['file']);

        // The update branch reuses the same stored path (see this class's
        // own [BUG 1] docblock) -- its real on-disk content is now the
        // *second* file's, not the first's.
        self::assertIsString($row['path']);
        $absolutePath = $this->marker . '/' . $row['path'];
        self::assertFileExists($absolutePath);
        $size = getimagesize($absolutePath);
        self::assertIsArray($size);
        self::assertSame(20, $size[0]);
        self::assertSame(15, $size[1]);
    }

    public function testAddUploadedFileThrowsWhenTheGivenImageIdDoesNotExist(): void
    {
        $source = $this->marker . '/orphan-update.png';
        $this->makeImage($source, 'png', 10, 8);

        $countBefore = $this->countRows('SELECT COUNT(*) FROM images');

        $threw = null;
        try {
            new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($source, $this->urlService, 'orphan.png', image_id: 999_999);
        } catch (ImageProcessingException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'addUploadedFile() should have thrown for a nonexistent image_id');
        self::assertStringContainsString('this photo does not exist in the database', $threw->getMessage());

        // Nothing was inserted or deleted -- the throw happens before any
        // write, unlike the SVG-mismatch/forbidden-type rejection tests
        // below, which unlink() the source first.
        self::assertFileExists($source);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM images');
        self::assertSame($countBefore, $countAfter);
    }

    public function testAddUploadedFileUpdatesTheLevelWhenGivenOnTheUpdateBranch(): void
    {
        $service = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get());

        $first = $this->marker . '/lvl-first.png';
        $this->makeImage($first, 'png', 20, 20);
        $id = $service->addUploadedFile($first, $this->urlService, 'lvl-first.png');
        $this->imageIdsToDelete[] = $id;

        // The stock fixture/default insert path never sets a non-zero
        // level -- confirm the baseline before proving the update branch's
        // own `if (isset($level)) { $update['level'] = $level; }` really
        // changed it.
        $before = $this->fetchImageRow($id);
        self::assertSame(0, $this->rowInt($before['level']));

        $second = $this->marker . '/lvl-second.png';
        $this->makeImage($second, 'png', 15, 15);

        $result = $service->addUploadedFile($second, $this->urlService, 'lvl-second.png', image_id: $id, level: 4);

        self::assertSame($id, $result);
        $row = $this->fetchImageRow($id);
        self::assertSame(4, $this->rowInt($row['level']));
    }

    public function testAddUploadedFileDispatchesTheCorrectExtensionForEachRasterImageType(): void
    {
        $service = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get());
        $cases = [
            'png' => 'png',
            'jpeg' => 'jpg',
            'gif' => 'gif',
            'webp' => 'webp',
        ];

        foreach ($cases as $gdFormat => $expectedExt) {
            // The original filename deliberately does not carry the real
            // extension -- dispatch is driven by getimagesize()'s real
            // detected type, not by the given name.
            $source = $this->marker . '/type-test-' . $gdFormat;
            $this->makeImage($source, $gdFormat, 10, 8);

            $imageId = $service->addUploadedFile($source, $this->urlService, 'incoming.dat');
            $id = $imageId;
            $this->imageIdsToDelete[] = $id;

            $row = $this->fetchImageRow($id);
            self::assertIsString($row['path']);
            self::assertStringEndsWith('.' . $expectedExt, $row['path'], "expected .{$expectedExt} for a real {$gdFormat} source");
        }
    }

    public function testAddUploadedFileResolvesANonStandardExtensionViaTheFinfoFallbackWhenAllTypesAllowed(): void
    {
        CurrentConfigTestFactory::get()->uploadFormAllTypes = true;

        // Since 'zip' has no representative_ext (no upload_file_* handler
        // matches it) and isn't a picture extension either,
        // addUploadedFile()'s own "cache a derivative" tail resolves it
        // through SrcImage's static-mimetype-icon branch -- pre-seed the
        // fake theme-icon location UploadServiceTestThemeConfProvider
        // points at so that resolves too, instead of throwing for a
        // missing icon this suite has no real theme tree to provide.
        mkdir($this->marker . '/mimeicons', 0o777, true);
        $this->makeImage($this->marker . '/mimeicons/zip.png', 'png', 8, 8);

        // 'zip' is one of CurrentConfig::fileExtensions()'s own default
        // entries -- not a picture/video type getimagesize() recognizes, so
        // this only resolves through the finfo-based
        // uploadFormAllTypes()/fileExtensions() fallback, not the
        // IMAGETYPE_* branches above it.
        $source = $this->marker . '/archive.zip';
        file_put_contents($source, "PK\x03\x04" . str_repeat('x', 32));

        $imageId = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($source, $this->urlService, 'archive.zip');
        $id = $imageId;
        $this->imageIdsToDelete[] = $id;

        $row = $this->fetchImageRow($id);
        self::assertIsString($row['path']);
        self::assertStringEndsWith('.zip', $row['path']);

        $absolutePath = $this->marker . '/' . $row['path'];
        self::assertFileExists($absolutePath);
    }

    /**
     * addUploadedFile()'s own `if (PwgImage::get_library() !== 'gd')`
     * guard around originalResize()/needResize()/pwg_resize() -- this
     * environment's real ImageMagick CLI makes CurrentConfig::
     * graphicsLibrary()'s own 'auto' default resolve to 'ext_imagick'
     * before ever considering 'gd' (see tests/Unit/Admin/Upload/
     * UploadServiceTest.php's own "converts a real ... via the ext_imagick
     * CLI" tests for the same reasoning), so this real trigger path is
     * genuinely reachable without touching any library config -- only
     * skipped, defensively, on an environment where it truly isn't.
     */
    public function testAddUploadedFileResizesTheOriginalWhenItExceedsTheConfiguredMaxDimensions(): void
    {
        if (PwgImage::get_library() === 'gd') {
            self::markTestSkipped('No non-GD image library (ext_imagick/imagick) available in this environment -- addUploadedFile() never reaches its own originalResize()/needResize()/pwg_resize() block when PwgImage::get_library() is gd.');
        }

        CurrentConfigTestFactory::get()->originalResize = true;
        CurrentConfigTestFactory::get()->originalResizeMaxwidth = 50;
        CurrentConfigTestFactory::get()->originalResizeMaxheight = 50;
        CurrentConfigTestFactory::get()->originalResizeQuality = 90;

        $source = $this->marker . '/big.jpg';
        $this->makeImage($source, 'jpeg', 200, 150);

        $imageId = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($source, $this->urlService, 'big.jpg');
        $id = $imageId;
        $this->imageIdsToDelete[] = $id;

        $row = $this->fetchImageRow($id);
        self::assertLessThanOrEqual(50, $this->rowInt($row['width']));
        self::assertLessThanOrEqual(50, $this->rowInt($row['height']));

        // The resize happens in place, against the same $file_path the row
        // itself points at -- the real on-disk bytes are genuinely smaller
        // too, not just the DB-recorded dimensions.
        self::assertIsString($row['path']);
        $absolutePath = $this->marker . '/' . $row['path'];
        $size = getimagesize($absolutePath);
        self::assertIsArray($size);
        self::assertLessThanOrEqual(50, $size[0]);
        self::assertLessThanOrEqual(50, $size[1]);
    }

    /**
     * addUploadedFile()'s own "new photo" insert branch only sets
     * `$insert['representative_ext']` when the 'upload_file' PluginConfig
     * event actually returns one -- those 6 handlers are normally
     * registered by Bootstrap\RequestBootstrap (a real HTTP-request
     * bootstrap this Integration suite's own Kernel::boot() call never
     * runs, see this class's own docblock), so every other test in this
     * file uploads against an empty 'upload_file' handler chain. Registers
     * just the one handler this test needs directly instead, same
     * established pattern as RateServiceTest's own
     * EventDispatcherTestFactory::get()->addEventHandler()/EventDispatcher::reset()
     * pair.
     */
    public function testAddUploadedFileStoresTheRepresentativeExtWhenAnUploadFileHandlerMatches(): void
    {
        CurrentConfigTestFactory::get()->uploadFormAllTypes = true;

        EventDispatcherTestFactory::get()->addTypedHandler(UploadFile::class, UploadService::uploadFilePdf(...));

        try {
            $png = $this->marker . '/pdf-source.png';
            $this->makeImage($png, 'png', 40, 40);
            $pdf = $this->marker . '/document.pdf';
            $cmd = 'convert ' . escapeshellarg($png) . ' ' . escapeshellarg($pdf) . ' 2>&1';
            exec($cmd, $out, $status);
            if ($status !== 0) {
                self::markTestSkipped('ImageMagick convert failed to build a PDF fixture: ' . implode("\n", $out));
            }

            $imageId = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($pdf, $this->urlService, 'document.pdf');
            $id = $imageId;
            $this->imageIdsToDelete[] = $id;

            $row = $this->fetchImageRow($id);
            self::assertSame('jpg', $row['representative_ext']);
        } finally {
            EventDispatcherTestFactory::get()->reset();
        }
    }

    public function testAddUploadedFileRejectsAMismatchedSvgMimeTypeAndDeletesTheSourceFile(): void
    {
        CurrentConfigTestFactory::get()->uploadFormAllTypes = true;

        // Genuine SVG content (finfo sniffs 'image/svg+xml'), but the
        // original filename claims '.png' -- the exact mismatch
        // sanitizeSvgIfNeeded()'s own Unit tests build SVG fixtures for,
        // reused here to reach addUploadedFile()'s own MIME/extension
        // mismatch guard instead of the sanitizer directly.
        $source = $this->marker . '/fake.png';
        file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');

        $countBefore = $this->countRows('SELECT COUNT(*) FROM images');

        $threw = null;
        try {
            new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($source, $this->urlService, 'fake.png');
        } catch (ImageProcessingException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'addUploadedFile() should have thrown for a mismatched SVG MIME type');
        self::assertStringContainsString('does not match file MIME type', $threw->getMessage());

        self::assertFileDoesNotExist($source);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM images');
        self::assertSame($countBefore, $countAfter);
    }

    public function testAddUploadedFileRejectsAnExtensionAbsentFromFileExtensionsEvenWhenAllTypesAreAllowed(): void
    {
        CurrentConfigTestFactory::get()->uploadFormAllTypes = true;

        // Real, non-image bytes with an extension that is NOT one of
        // CurrentConfig::fileExtensions()'s own default entries -- unlike
        // the finfo-fallback test above, which deliberately uses '.zip', a
        // member of that list. getimagesize() fails (not a picture), and
        // finfo sniffs some non-SVG mimetype here (no MIME/extension
        // mismatch to catch), so this reaches addUploadedFile()'s own
        // conf_file_ext whitelist check instead -- a distinct rejection
        // path from the SVG-mismatch test above.
        $source = $this->marker . '/payload.exe';
        file_put_contents($source, "MZ\x90\x00" . str_repeat('x', 32));

        $countBefore = $this->countRows('SELECT COUNT(*) FROM images');

        $threw = null;
        try {
            new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($source, $this->urlService, 'payload.exe');
        } catch (ImageProcessingException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'addUploadedFile() should have thrown for an extension absent from fileExtensions()');
        self::assertSame('unexpected file type', $threw->getMessage());

        self::assertFileDoesNotExist($source);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM images');
        self::assertSame($countBefore, $countAfter);
    }

    public function testAddUploadedFileRejectsAForbiddenFileTypeAndDeletesTheSourceFile(): void
    {
        CurrentConfigTestFactory::get()->uploadFormAllTypes = false;

        $source = $this->marker . '/notes.txt';
        file_put_contents($source, 'just some plain text, not an image at all');

        $countBefore = $this->countRows('SELECT COUNT(*) FROM images');

        $threw = null;
        try {
            new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($source, $this->urlService, 'notes.txt');
        } catch (ImageProcessingException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'addUploadedFile() should have thrown for a forbidden file type');
        self::assertSame('forbidden file type', $threw->getMessage());

        self::assertFileDoesNotExist($source);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM images');
        self::assertSame($countBefore, $countAfter);
    }

    public function testAddUploadedFileAddToCategoriesFlipsLoungeActiveOnceThePhotoCountReachesTheThreshold(): void
    {
        // The fixture's own config row already has 'lounge_active' =
        // 'true' (see this class's own docblock) -- force it to 'false' here
        // so the assertion below actually proves confUpdateParam() wrote a
        // real change, not just a coincidental match with pre-existing data.
        $this->conn->executeStatement("UPDATE config SET value = 'false' WHERE param = 'lounge_active'");

        $countBefore = $this->countRows('SELECT COUNT(*) FROM images');
        CurrentConfigTestFactory::get()->loungeActivateThreshold = $countBefore + 1;
        self::assertFalse(CurrentConfigTestFactory::get()->loungeActive);

        $source = $this->marker . '/threshold.png';
        $this->makeImage($source, 'png', 10, 8);

        $imageId = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($source, $this->urlService, 'threshold.png');
        $this->imageIdsToDelete[] = $imageId;

        // addUploadedFileAddToCategories()'s own COUNT(*) check (running
        // right after the insert, so it already sees the new row) now
        // meets the threshold set above.
        self::assertTrue(CurrentConfigTestFactory::get()->loungeActive);

        $dbValue = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'lounge_active'");
        self::assertSame('true', $dbValue);
    }

    public function testAddUploadedFileAddToCategoriesAssociatesANewPhotoToTheGivenCategoriesWhenLoungeIsInactive(): void
    {
        $source = $this->marker . '/associate.png';
        $this->makeImage($source, 'png', 10, 8);

        $imageId = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get())->addUploadedFile($source, $this->urlService, 'associate.png', categories: [1]);
        $id = $imageId;
        $this->imageIdsToDelete[] = $id;

        // setUp()'s own high default threshold keeps the lounge inactive
        // here, so this exercises associateImagesToCategories(), not
        // fillLounge().
        self::assertFalse(CurrentConfigTestFactory::get()->loungeActive);

        $linked = $this->countRows('SELECT COUNT(*) FROM image_category WHERE image_id = ' . $id . ' AND category_id = 1');
        self::assertSame(1, $linked);

        $inLounge = $this->countRows('SELECT COUNT(*) FROM lounge WHERE image_id = ' . $id);
        self::assertSame(0, $inLounge);
    }

    /**
     * uploadFileTiff()'s own "sometimes ImageMagick creates file-0.jpg
     * (full size) + file-1.jpg (thumbnail)" fallback -- a genuine
     * multi-page TIFF (unlike the Unit suite's single-page fixture, which
     * never exercises this branch) makes a bare `convert multi.tiff
     * out.jpg` produce `out-0.jpg`/`out-1.jpg` instead of `out.jpg`
     * (confirmed live with this exact ImageMagick build), which this
     * method's own rename() fallback then recovers from.
     */
    public function testUploadFileTiffFallsBackToTheSplitFileRenameWhenImagemagickProducesPerPageOutput(): void
    {
        $frame1 = $this->marker . '/frame1.png';
        $frame2 = $this->marker . '/frame2.png';
        $this->makeImage($frame1, 'png', 20, 20);
        $this->makeImage($frame2, 'png', 20, 20);

        $tiff = $this->marker . '/multi.tiff';
        $cmd = 'convert ' . escapeshellarg($frame1) . ' ' . escapeshellarg($frame2) . ' ' . escapeshellarg($tiff) . ' 2>&1';
        exec($cmd, $out, $status);
        if ($status !== 0) {
            self::markTestSkipped('ImageMagick convert failed to build a multi-page TIFF fixture: ' . implode("\n", $out));
        }

        $result = $this->uploadFileExt(UploadService::uploadFileTiff(...), null, $tiff);

        self::assertIsString($result);
        $representativePath = $this->marker . '/pwg_representative/multi.' . $result;
        self::assertFileExists($representativePath);
        self::assertGreaterThan(0, filesize($representativePath));
        // The per-page sibling was renamed away, not left behind alongside
        // the recovered file.
        self::assertFileDoesNotExist($this->marker . '/pwg_representative/multi-0.' . $result);
    }

    public function testAddFormatThrowsWhenTheFormatOfImageDoesNotExist(): void
    {
        CurrentConfigTestFactory::get()->isFormatsEnabled = true;
        CurrentConfigTestFactory::get()->formatExtensions = ['tif'];

        try {
            $service = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get());
            $source = $this->marker . '/orphan-format.tif';
            file_put_contents($source, 'not a real tiff, just needs bytes on disk');

            $threw = null;
            try {
                $service->addFormat($source, 'tif', 999_999);
            } catch (ImageProcessingException $e) {
                $threw = $e;
            }

            self::assertNotNull($threw, 'addFormat() should have thrown for a nonexistent format_of image id');
            self::assertStringContainsString('this photo does not exist in the database', $threw->getMessage());
        } finally {
            CurrentConfigTestFactory::get()->isFormatsEnabled = false;
            CurrentConfigTestFactory::get()->formatExtensions = ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];
        }
    }

    /**
     * addFormat()'s own getFormatIdByImageAndExt()-then-branch: a first
     * call for a given (image, ext) pair inserts a new
     * image_format row ('add'); a second call for the exact same
     * pair updates that same row's filesize in place instead of inserting
     * a duplicate ('update') -- neither status was exercised by any
     * existing test before this one (only the two disabled-formats/
     * unauthorized-extension guard tests existed in
     * tests/Unit/Admin/Upload/UploadServiceTest.php).
     */
    public function testAddFormatInsertsThenUpdatesTheSameFormatRowOnASecondCall(): void
    {
        CurrentConfigTestFactory::get()->isFormatsEnabled = true;
        CurrentConfigTestFactory::get()->formatExtensions = ['tif'];

        try {
            $service = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get());

            $sourceV1 = $this->marker . '/format-v1.tif';
            file_put_contents($sourceV1, str_repeat('a', 128));
            $result1 = $service->addFormat($sourceV1, 'tif', 1);
            self::assertSame('add', $result1);

            $sourceV2 = $this->marker . '/format-v2.tif';
            file_put_contents($sourceV2, str_repeat('b', 512));
            $result2 = $service->addFormat($sourceV2, 'tif', 1);
            self::assertSame('update', $result2);

            $count = $this->countRows('SELECT COUNT(*) FROM image_format' . " WHERE image_id = 1 AND ext = 'tif'");
            self::assertSame(1, $count, 'a second addFormat() call for the same image/ext should UPDATE, not duplicate, the row');
        } finally {
            CurrentConfigTestFactory::get()->isFormatsEnabled = false;
            CurrentConfigTestFactory::get()->formatExtensions = ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];
        }
    }

    /**
     * uploadFileHeic() calls getOptimalDimensionsForRepresentative()
     * (private, static) right before its own real `convert` invocation --
     * that helper's own per-type loop is dead in
     * tests/Unit/Admin/Upload/UploadServiceTest.php's own "returns a
     * positive width/height pair" test (that suite never calls
     * ImageStdParams::loadFromDb(), so every type's own $params stays
     * null there), unlike here, where this class's own setUp() already
     * does -- genuinely exercising it. Whether or not this environment's
     * ImageMagick build carries a libheif delegate (unconfirmed, unlike
     * the ext_imagick binary itself -- see this file's own uploadFileTiff/
     * Pdf/Psd/Eps tests), uploadFileHeic() always reaches its own tail
     * `return $representative_ext;`: null if the real `convert`
     * invocation failed to produce a file, 'jpg' if a genuinely available
     * delegate succeeded.
     */
    public function testUploadFileHeicReachesItsTailReturnViaGetOptimalDimensionsForRepresentative(): void
    {
        $heic = $this->marker . '/photo.heic';
        file_put_contents($heic, 'not a genuine heic payload -- only the .heic extension needs to dispatch here');

        $result = $this->uploadFileExt(UploadService::uploadFileHeic(...), null, $heic);

        self::assertTrue($result === null || $result === 'jpg');

        if ($result === 'jpg') {
            $representativePath = $this->marker . '/pwg_representative/photo.jpg';
            self::assertFileExists($representativePath);
        }
    }

    /**
     * uploadFileVideo()'s isset($representative_ext) early return never
     * touches ffmpeg/ffprobe or the filesystem -- included here for
     * symmetry with the other uploadFile*() branch tests in this class,
     * even though tests/Unit/Admin/Upload/UploadServiceTest.php's own "6
     * upload_file_* handlers pass an already-set representative_ext
     * straight through" test already exercises the same 2 lines.
     */
    public function testUploadFileVideoPassesThroughAnAlreadySetRepresentativeExt(): void
    {
        $result = $this->uploadFileExt(UploadService::uploadFileVideo(...), 'already-set', $this->marker . '/whatever.mp4');

        self::assertSame('already-set', $result);
    }

    /**
     * uploadFileVideo()'s main success path: a real, valid MP4 (synthesized
     * with ffmpeg's own lavfi test source at test time, not a checked-in
     * binary -- same "real CLI fixture" convention as this class's own
     * TIFF test above) makes ffprobe report a real, parseable duration,
     * exercising the isset($O[0])-true branch that computes $second from
     * it; ffmpeg then genuinely extracts a poster frame from that second.
     * The 3s duration / 2s poster-second gap (the method's own `min(...,
     * 2)` cap) leaves enough margin that seeking never lands past EOF
     * (confirmed live).
     *
     * This same real ffmpeg 8.0.1 build also always writes a couple of
     * diagnostic lines to stderr for this exact command shape
     * (`-frames:v 1` into a bare `image2` output path, no `-update`:
     * "does not contain an image sequence pattern ... Use ... -update
     * ...") even on a fully successful run -- captured into $FO via the
     * method's own `2>&1` redirection -- so this one real fixture also
     * exercises the isset($FO[0])-true debug-log branch (confirmed live,
     * not assumed): a successful run is never silent on this ffmpeg
     * build, so there is no separate "success but still logs stderr"
     * fixture to contrive here.
     */
    public function testUploadFileVideoGeneratesAPosterFromARealVideoAndLogsFfmpegsStderrOutput(): void
    {
        $video = $this->marker . '/clip.mp4';
        $cmd = 'ffmpeg -y -f lavfi -i ' . escapeshellarg('color=c=blue:s=64x64:d=3') . ' -t 3 -pix_fmt yuv420p ' . escapeshellarg($video) . ' 2>&1';
        exec($cmd, $out, $status);
        if ($status !== 0) {
            self::markTestSkipped('ffmpeg failed to synthesize the test video fixture: ' . implode("\n", $out));
        }

        $result = $this->uploadFileExt(UploadService::uploadFileVideo(...), null, $video);

        self::assertSame('jpg', $result);
        $posterPath = $this->marker . '/pwg_representative/clip.jpg';
        self::assertFileExists($posterPath);
        self::assertGreaterThan(0, filesize($posterPath));
    }

    /**
     * uploadFileVideo()'s failure path, all in one real, un-contrived
     * fixture: a plain text file saved with a video-like extension is
     * exactly what a corrupt/non-decodable upload looks like in
     * production -- not a synthetic mock of ffmpeg/ffprobe.
     *
     * ffprobe (run without this method's own `2>&1`, unlike the ffmpeg/
     * avconv calls below) genuinely fails to open the file at all: it
     * writes its "moov atom not found" error to stderr only, leaving $O a
     * genuinely empty array (isset($O[0]) is false) -- precisely the
     * $second = 0 fallback branch. Confirmed live that this is the *only*
     * real way to reach that fallback: ffprobe and ffmpeg share the same
     * demuxer/probing code, so any input that leaves ffprobe's $O empty is,
     * for that same reason, also undecodable by the ffmpeg poster-
     * extraction call immediately below -- this single fixture is the
     * genuine way both branches occur together in production, not a
     * contrivance stitching two unrelated scenarios into one test.
     *
     * ffmpeg's own attempt then fails outright (it still writes its
     * version banner to stderr, captured via the method's `2>&1`, so
     * isset($FO[0]) is true there too) and no poster file is produced, so
     * the method falls through to the avconv retry. avconv itself is not
     * installable in this environment (see this repo's own project notes
     * on avconv), so the retry also fails -- but the shell's own "avconv:
     * not found" message is what lands in $AO[0] (confirmed live), which
     * is exactly the real, genuine behavior of this code path in any
     * environment where avconv truly isn't available, exercising the
     * isset($AO[0]) debug branch too. The method then returns null.
     */
    public function testUploadFileVideoReturnsNullAndFallsBackThroughAvconvWhenFfmpegCannotDecodeTheFile(): void
    {
        $fake = $this->marker . '/broken.mp4';
        file_put_contents($fake, "this is not a video file, just plain text with a video-like extension\n");

        $result = $this->uploadFileExt(UploadService::uploadFileVideo(...), null, $fake);

        self::assertNull($result);
        self::assertFileDoesNotExist($this->marker . '/pwg_representative/broken.jpg');
    }

    // === Mutation-testing gap closures below ===

    private function newService(): UploadService
    {
        return new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, CurrentPathsTestFactory::get(), DbCredentialsTestFactory::get());
    }

    /**
     * Real gap: the `continue` inside saveUploadFormConfig()'s own
     * PHPStan-narrowing guard (whose only realistically-triggerable clause
     * is `! is_scalar($value)`, per that method's own comment) must skip
     * only the current field and keep the loop going, not abort every
     * field after it.
     */
    public function testSaveUploadFormConfigKeepsProcessingALaterFieldAfterSkippingANonScalarOne(): void
    {
        $service = $this->newService();
        $errors = [];
        $formErrors = [];

        $result = $service->saveUploadFormConfig(
            [
                'original_resize_maxwidth' => ['not', 'scalar'],
                'original_resize_maxheight' => '1500',
            ],
            $errors,
            $formErrors
        );

        try {
            self::assertTrue($result);
            self::assertSame([], $errors);

            $stored = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'original_resize_maxheight'");
            self::assertSame('1500', $stored, 'the field after the skipped non-scalar one must still be processed and persisted, not abandoned by a wrongly-broken loop');
        } finally {
            $this->conn->executeStatement("UPDATE config SET value = '2016' WHERE param = 'original_resize_maxheight'");
            $this->entityManager->clear();
        }
    }

    /**
     * Real gap: saveUploadFormConfig()'s own massUpdateValues() call writes
     * through a completely separate, throwaway EntityManager
     * (EntityManagerFactory::build(DbConnection::build())), never touching
     * $this->entityManager's own identity map -- only the explicit
     * `$this->entityManager->clear()` call right after evicts any
     * already-cached, now-stale ConfigEntry so a later find() re-queries
     * instead of returning the pre-write copy.
     */
    public function testSaveUploadFormConfigClearsTheInjectedEntityManagersIdentityMapAfterASuccessfulWrite(): void
    {
        $this->conn->executeStatement("UPDATE config SET value = '2016' WHERE param = 'original_resize_maxheight'");
        $this->entityManager->clear();

        // Populates $this->entityManager's own identity map with the
        // pre-write value -- find() checks that identity map FIRST, before
        // ever issuing a fresh query, so this is the only way to observe
        // whether the write path's own clear() call genuinely ran.
        $before = $this->entityManager->find(ConfigEntry::class, 'original_resize_maxheight');
        self::assertInstanceOf(ConfigEntry::class, $before);
        self::assertSame('2016', $before->value);

        $service = $this->newService();
        $errors = [];
        $formErrors = [];

        try {
            $result = $service->saveUploadFormConfig([
                'original_resize_maxheight' => '1500',
            ], $errors, $formErrors);

            self::assertTrue($result);
            self::assertSame([], $errors);

            $after = $this->entityManager->find(ConfigEntry::class, 'original_resize_maxheight');
            self::assertInstanceOf(ConfigEntry::class, $after);
            self::assertSame('1500', $after->value, 'entityManager->clear() must genuinely evict the stale cached entity so this find() re-queries instead of returning the pre-write identity-map copy');
        } finally {
            $this->conn->executeStatement("UPDATE config SET value = '2016' WHERE param = 'original_resize_maxheight'");
            $this->entityManager->clear();
        }
    }

    /**
     * Real, observable gap: addUploadedFile() html-escapes
     * $original_filename before it's ever used (stored into
     * `file`/`name`) -- a raw, un-escaped filename containing HTML
     * metacharacters would be a stored-XSS vector wherever that column is
     * later rendered.
     */
    public function testAddUploadedFileHtmlEscapesTheOriginalFilenameBeforeStoringIt(): void
    {
        $source = $this->marker . '/escape-test.png';
        $this->makeImage($source, 'png', 12, 9);

        $rawFilename = 'a & b <script>.png';
        $imageId = $this->newService()
            ->addUploadedFile($source, $this->urlService, $rawFilename);
        $id = $imageId;
        $this->imageIdsToDelete[] = $id;

        $row = $this->fetchImageRow($id);
        self::assertSame(htmlspecialchars($rawFilename), $row['file']);
        self::assertNotSame($rawFilename, $row['file'], 'the raw, unescaped filename must never reach storage verbatim when it contains HTML metacharacters');
    }

    /**
     * Real gap: `! isset($image_id) and $this->currentConfig->
     * uploadDetectDuplicate()` -- an `or` here would make the whole
     * duplicate-detection block (including its own short-circuit "already
     * exists, return the OTHER image's id" branch) run even on the UPDATE
     * branch (image_id given) whenever detection is merely enabled,
     * wrongly hijacking a legitimate update into returning some unrelated
     * existing image's id instead.
     */
    public function testAddUploadedFileNeverRunsDuplicateDetectionOnTheUpdateBranchEvenWithDetectionEnabled(): void
    {
        CurrentConfigTestFactory::get()->uploadDetectDuplicate = true;
        try {
            $service = $this->newService();

            $first = $this->marker . '/dupguard-first.png';
            $this->makeImage($first, 'png', 12, 9);
            $id = $service->addUploadedFile($first, $this->urlService, 'dupguard-first.png');
            $this->imageIdsToDelete[] = $id;

            $second = $this->marker . '/dupguard-second.png';
            file_put_contents($second, 'irrelevant bytes for the update branch');

            // The given original_md5sum deliberately matches fixture image
            // #1 (see this class's own docblock) -- if the
            // duplicate-detection block wrongly ran here, it would
            // short-circuit and return image #1's id instead of genuinely
            // updating $id.
            $result = $service->addUploadedFile(
                $second,
                $this->urlService,
                'dupguard-second.png',
                image_id: $id,
                original_md5sum: '2e7ee450c4a4cffe42945205029782b9',
            );

            self::assertSame($id, $result, 'updating an existing photo must never be hijacked into the duplicate-detection short-circuit, even with detection enabled');
            // Image #1's own row (the same fixture-photo-1.jpg other
            // duplicate-detection tests in this class rely on) must be
            // completely untouched -- the short-circuit's own unlink()
            // never ran.
            $image1Row = $this->fetchImageRow(1);
            self::assertSame('2e7ee450c4a4cffe42945205029782b9', $image1Row['md5sum']);
        } finally {
            CurrentConfigTestFactory::get()->uploadDetectDuplicate = true;
        }
    }

    /**
     * Mirrors addUploadedFile()'s own private duplicate-detection
     * lock-name formula exactly (see that method's own comment right
     * above its `$dup_detect_lock_name` computation).
     */
    private function dupDetectLockName(string $md5sum): string
    {
        return 'piwigo_iud_' . sha1($this->dbName . ':' . $md5sum);
    }

    /**
     * Spawns a real, separate OS process that acquires $lockName, sleeps
     * briefly, then releases it -- same genuine, separate-session
     * technique as tests/Contract/WsImagesUploadConcurrencyTest.php's own
     * spawnBackgroundLockHolderThatInsertsThenReleases(), pared down to a
     * bare acquire-sleep-release.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawnBackgroundLockHolder(string $lockName): array
    {
        if ($this->dbDriver === 'pgsql') {
            $key = AdvisorySessionLock::key($lockName);
            $sql = sprintf(
                "SET lock_timeout = '5s'; SELECT pg_advisory_lock(%d); SELECT pg_sleep(0.3); SELECT pg_advisory_unlock(%d);",
                $key,
                $key,
            );
            $cmd = ['psql', '-U' . $this->dbUser, '-h' . $this->dbHost, '-d' . $this->dbName, '-q', '-t', '-c', $sql];
            $env = $this->dbPass !== '' ? array_merge(getenv(), [
                'PGPASSWORD' => $this->dbPass,
            ]) : null;
        } else {
            $sql = sprintf(
                "SELECT GET_LOCK('%s', 5); SELECT SLEEP(0.3); SELECT RELEASE_LOCK('%s');",
                $lockName,
                $lockName,
            );
            $cmd = ['mysql', '-u' . $this->dbUser];
            if ($this->dbPass !== '') {
                $cmd[] = '-p' . $this->dbPass;
            }
            $cmd[] = str_starts_with($this->dbHost, '/') ? '--socket=' . $this->dbHost : '-h' . $this->dbHost;
            $cmd[] = $this->dbName;
            $cmd[] = '-e';
            $cmd[] = $sql;
            $env = null;
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($proc, 'proc_open failed for the background lock-holder process');

        return [$proc, $pipes];
    }

    /**
     * Real gap: the duplicate-detection lock name is built from
     * `$this->dbCredentials->database . ':' . $md5sum`, hashed and
     * prefixed with 'piwigo_iud_' -- a wrong formula (dropped database
     * name, dropped separator, swapped operand order, or a hardcoded
     * literal with no per-md5sum hashing at all) would let two genuinely
     * concurrent duplicate uploads of the SAME file race past each other
     * uncontended, defeating the whole point of the lock. A real,
     * separate connection holding the EXACT documented lock name for a
     * short window is the only way to prove addUploadedFile() computes
     * that same name: it can only genuinely block on (and then unblock
     * promptly once released) a name it independently computed
     * identically.
     */
    public function testAddUploadedFileComputesTheDuplicateDetectionLockNameFromTheDocumentedDatabaseNameAndMd5sumFormula(): void
    {
        CurrentConfigTestFactory::get()->uploadDetectDuplicate = true;

        $source = $this->marker . '/lock-name-probe.png';
        $this->makeImage($source, 'png', 10, 8);
        $md5sum = md5_file($source);
        self::assertIsString($md5sum);

        $lockName = $this->dupDetectLockName($md5sum);
        [$proc, $pipes] = $this->spawnBackgroundLockHolder($lockName);

        try {
            // A head start for the background process to actually acquire
            // the lock before our own call reaches it.
            usleep(50_000);

            $start = microtime(true);
            $imageId = $this->newService()
                ->addUploadedFile($source, $this->urlService, 'lock-name-probe.png', original_md5sum: $md5sum);
            $elapsed = microtime(true) - $start;
            $this->imageIdsToDelete[] = $imageId;

            self::assertGreaterThan(0.15, $elapsed, 'must have genuinely blocked on the exact same held lock name computed from the documented formula, not raced past it');
            self::assertLessThan(10.0, $elapsed, 'must unblock promptly once the background process releases, not wait anywhere near the full 30s production timeout');
        } finally {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            $exit = proc_close($proc);
            self::assertSame(0, $exit, "background lock-holder process failed. stdout=[{$stdout}] stderr=[{$stderr}]");
        }
    }

    /**
     * Real gap: `count($images_found) > 0` -- a `0` result (no genuine
     * duplicate) must NOT be treated as found. Under a `>= 0`/`> -1`
     * mutant this branch wrongly reads `$images_found[0]` on an empty
     * array (an undefined-array-key warning under this suite's
     * failOnWarning), or -- were that warning somehow not fatal -- would
     * short-circuit-return instead of performing a genuine new-photo
     * insert.
     */
    public function testAddUploadedFileDoesNotTreatZeroDuplicateMatchesAsADuplicate(): void
    {
        $source = $this->marker . '/no-duplicate.png';
        $this->makeImage($source, 'png', 11, 7);

        $countBefore = $this->countRows('SELECT COUNT(*) FROM images');

        $imageId = $this->newService()
            ->addUploadedFile($source, $this->urlService, 'no-duplicate.png');
        $this->imageIdsToDelete[] = $imageId;

        $countAfter = $this->countRows('SELECT COUNT(*) FROM images');
        self::assertSame($countBefore + 1, $countAfter, 'a genuinely new photo (zero real duplicate matches) must be INSERTed, not short-circuited');
        self::assertFileDoesNotExist($source);
    }

    /**
     * Real gap: sanitizeSvgIfNeeded()'s own initial `return;` (for a
     * finfo_type that doesn't match the SVG whitelist) must short-circuit
     * BEFORE the file is ever read/parsed/re-serialized -- a non-XML
     * fixture can't distinguish this (it would also bail out later, via
     * the loadXML()-failure guard, leaving the file untouched either
     * way), so this uses genuinely well-formed XML that DOMDocument's own
     * saveXML() would reformat (single- to double-quoted attribute) if it
     * were ever actually parsed and re-serialized.
     */
    public function testSanitizeSvgIfNeededReturnsBeforeEverReadingTheFileForANonMatchingFinfoType(): void
    {
        $path = $this->marker . '/mismatched-type.xml';
        $original = "<root attr='value'/>";
        file_put_contents($path, $original);

        $service = $this->newService();
        $method = new ReflectionMethod($service, 'sanitizeSvgIfNeeded');
        $method->invoke($service, $path, 'image/jpeg');

        self::assertSame($original, file_get_contents($path), 'a non-matching finfo_type must return immediately, before any read/parse/re-serialize could reformat the file');
    }

    /**
     * Real gap: `libxml_use_internal_errors($previous_use_errors);`
     * restores the PRIOR internal-errors setting -- without it, internal
     * error handling leaks on (true) for the rest of the process, which
     * `libxml_use_internal_errors()` (queried with no argument) can
     * observe directly.
     */
    public function testSanitizeSvgIfNeededRestoresThePriorLibxmlUseInternalErrorsSettingAfterward(): void
    {
        $path = $this->marker . '/restore-libxml.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');

        $previous = libxml_use_internal_errors(false);
        try {
            $service = $this->newService();
            $method = new ReflectionMethod($service, 'sanitizeSvgIfNeeded');
            $method->invoke($service, $path, 'image/svg+xml');

            self::assertFalse(libxml_use_internal_errors(), 'must restore the prior (false) internal-errors setting, not leave it permanently toggled on for the rest of the process');
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    private function readonlyDir(): string
    {
        $dir = $this->marker . '/readonly-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o777, true);
        chmod($dir, 0o555);

        return $dir;
    }

    /**
     * Real gap: strtolower() before the in_array() extension check on all
     * 5 ext_imagick handlers -- without it, a real, common uppercase
     * extension (e.g. many camera/OS uploads produce '.PDF') would
     * silently fail to dispatch to any of them.
     */
    public function testThe5ExtImagickHandlersRecognizeAnUppercaseExtensionCaseInsensitively(): void
    {
        $dir = $this->readonlyDir();
        // mkdir() on a permission-denied directory emits a real PHP
        // warning that phpunit.xml's failOnWarning="true" would otherwise
        // turn into a failure right at the call site.
        set_error_handler(static fn (): bool => true);
        try {
            foreach ([
                [UploadService::uploadFilePdf(...), 'document.PDF'],
                [UploadService::uploadFileHeic(...), 'photo.HEIC'],
                [UploadService::uploadFileTiff(...), 'photo.TIFF'],
                [UploadService::uploadFilePsd(...), 'layered.PSD'],
                [UploadService::uploadFileEps(...), 'vector.EPS'],
            ] as [$handler, $filename]) {
                $threw = null;
                try {
                    $this->uploadFileExt($handler, null, $dir . '/' . $filename);
                } catch (ImageProcessingException $e) {
                    $threw = $e;
                }
                self::assertNotNull($threw, "expected {$filename} to be recognized case-insensitively and reach prepareDirectoryStatic()");
            }
        } finally {
            restore_error_handler();
            chmod($dir, 0o777);
        }
    }

    /**
     * Real gap: uploadFileTiff()'s whitelist is `['tif', 'tiff']` -- a
     * bare '.tif' (distinct from '.tiff') must also be recognized.
     */
    public function testUploadFileTiffAlsoRecognizesTheBareTifExtensionNotJustTiff(): void
    {
        $dir = $this->readonlyDir();
        set_error_handler(static fn (): bool => true);
        try {
            $threw = null;
            try {
                $this->uploadFileExt(UploadService::uploadFileTiff(...), null, $dir . '/photo.tif');
            } catch (ImageProcessingException $e) {
                $threw = $e;
            }
            self::assertNotNull($threw, 'a bare .tif extension (not just .tiff) must also be recognized');
        } finally {
            restore_error_handler();
            chmod($dir, 0o777);
        }
    }

    private function makeSamplePng(string $path): void
    {
        $exec = 'convert -size 30x30 xc:blue ' . escapeshellarg($path) . ' 2>&1';
        exec($exec, $out, $status);
        if ($status !== 0) {
            self::markTestSkipped('ImageMagick convert failed to build a sample PNG: ' . implode("\n", $out));
        }
    }

    private function convertSample(string $sourcePng, string $destPath): void
    {
        $exec = 'convert ' . escapeshellarg($sourcePng) . ' ' . escapeshellarg($destPath) . ' 2>&1';
        exec($exec, $out, $status);
        if ($status !== 0) {
            self::markTestSkipped("ImageMagick convert failed to build {$destPath}: " . implode("\n", $out));
        }
    }

    /**
     * ImageMagick decodes arbitrary content by magic bytes regardless of a
     * misleading extension, but has no HEIC encode delegate in this
     * environment -- same technique as tests/Unit/Admin/Upload/
     * UploadServiceTest.php's own upload_service_make_fake_heic().
     */
    private function makeFakeHeic(string $path): void
    {
        $realPng = $path . '.real.png';
        $this->makeSamplePng($realPng);
        rename($realPng, $path);
    }

    private function realExtImagickDir(): string
    {
        exec('command -v magick 2>/dev/null', $out, $status);
        if ($status !== 0 || ! isset($out[0]) || $out[0] === '') {
            $out = [];
            exec('command -v convert 2>/dev/null', $out, $status);
        }
        self::assertSame(0, $status, 'no working magick/convert binary found on PATH in this environment');
        self::assertNotEmpty($out, 'command -v produced no output');

        return rtrim(dirname($out[0]), '/') . '/';
    }

    /**
     * Real gap: `escapeshellarg($ext_imagick_dir) . PwgImage::
     * get_ext_imagick_command()` -- a bogus configured dir must genuinely
     * be prepended into the exec() command (making the whole invocation
     * fail, since a slash-containing command name bypasses PATH lookup
     * entirely), not silently dropped in favor of a bare, PATH-resolved
     * command name that would still find the real system binary.
     * uploadFileTiff()/uploadFilePsd() always extract a representative
     * extension string regardless of whether the file was actually
     * produced, so their own real conversion failure is checked via real
     * on-disk file existence, not via their return value.
     */
    public function testThe5ExtImagickHandlersUseTheConfiguredDirPrefixRatherThanABarePathLookup(): void
    {
        $bogusDir = sys_get_temp_dir() . '/definitely-not-a-real-imagick-dir-' . bin2hex(random_bytes(4)) . '/';
        CurrentConfigTestFactory::get()->extImagickDir = $bogusDir;
        try {
            $dir = $this->marker;

            $pdfPng = $dir . '/dirbogus-pdf-src.png';
            $this->makeSamplePng($pdfPng);
            $pdf = $dir . '/dirbogus.pdf';
            $this->convertSample($pdfPng, $pdf);
            self::assertNull($this->uploadFileExt(UploadService::uploadFilePdf(...), null, $pdf), 'a bogus configured ext_imagick dir must genuinely be prepended to the exec() command, not silently dropped in favor of a bare PATH lookup');

            $heic = $dir . '/dirbogus.heic';
            $this->makeFakeHeic($heic);
            self::assertNull($this->uploadFileExt(UploadService::uploadFileHeic(...), null, $heic));

            $epsPng = $dir . '/dirbogus-eps-src.png';
            $this->makeSamplePng($epsPng);
            $eps = $dir . '/dirbogus.eps';
            $this->convertSample($epsPng, $eps);
            self::assertNull($this->uploadFileExt(UploadService::uploadFileEps(...), null, $eps));

            $tiffPng = $dir . '/dirbogus-tiff-src.png';
            $this->makeSamplePng($tiffPng);
            $tiff = $dir . '/dirbogus.tiff';
            $this->convertSample($tiffPng, $tiff);
            $this->uploadFileExt(UploadService::uploadFileTiff(...), null, $tiff);
            self::assertFileDoesNotExist($dir . '/pwg_representative/dirbogus.' . $this->currentConfig->tiffRepresentativeExt);

            $psdPng = $dir . '/dirbogus-psd-src.png';
            $this->makeSamplePng($psdPng);
            $psd = $dir . '/dirbogus.psd';
            $this->convertSample($psdPng, $psd);
            $this->uploadFileExt(UploadService::uploadFilePsd(...), null, $psd);
            self::assertFileDoesNotExist($dir . '/pwg_representative/dirbogus.png');
        } finally {
            CurrentConfigTestFactory::get()->extImagickDir = '';
        }
    }

    /**
     * Real gap: same construction, opposite direction -- with a REAL,
     * working ext_imagick dir configured (this project's own tests never
     * set a non-default, non-empty extImagickDir anywhere else, so the
     * concatenation ORDER has never actually been exercised with a real
     * value before), the dir must come BEFORE the command name
     * (`escapeshellarg($dir) . command`), not after it -- a swapped order
     * concatenates into a single, nonexistent shell word (e.g.
     * `convert'/usr/bin/'`), failing every conversion even though the
     * configured dir is genuinely correct.
     */
    public function testThe5ExtImagickHandlersPrependTheConfiguredDirBeforeTheCommandNameNotAfter(): void
    {
        $realDir = $this->realExtImagickDir();
        CurrentConfigTestFactory::get()->extImagickDir = $realDir;
        try {
            $dir = $this->marker;

            $pdfPng = $dir . '/dirreal-pdf-src.png';
            $this->makeSamplePng($pdfPng);
            $pdf = $dir . '/dirreal.pdf';
            $this->convertSample($pdfPng, $pdf);
            self::assertSame('jpg', $this->uploadFileExt(UploadService::uploadFilePdf(...), null, $pdf), 'the configured dir must be prepended before the command name, not appended after it, or the resulting shell word never resolves to a real executable');

            $heic = $dir . '/dirreal.heic';
            $this->makeFakeHeic($heic);
            self::assertSame('jpg', $this->uploadFileExt(UploadService::uploadFileHeic(...), null, $heic));

            $epsPng = $dir . '/dirreal-eps-src.png';
            $this->makeSamplePng($epsPng);
            $eps = $dir . '/dirreal.eps';
            $this->convertSample($epsPng, $eps);
            self::assertSame('png', $this->uploadFileExt(UploadService::uploadFileEps(...), null, $eps));

            $tiffPng = $dir . '/dirreal-tiff-src.png';
            $this->makeSamplePng($tiffPng);
            $tiff = $dir . '/dirreal.tiff';
            $this->convertSample($tiffPng, $tiff);
            $this->uploadFileExt(UploadService::uploadFileTiff(...), null, $tiff);
            self::assertFileExists($dir . '/pwg_representative/dirreal.' . $this->currentConfig->tiffRepresentativeExt);

            $psdPng = $dir . '/dirreal-psd-src.png';
            $this->makeSamplePng($psdPng);
            $psd = $dir . '/dirreal.psd';
            $this->convertSample($psdPng, $psd);
            $this->uploadFileExt(UploadService::uploadFilePsd(...), null, $psd);
            self::assertFileExists($dir . '/pwg_representative/dirreal.png');
        } finally {
            CurrentConfigTestFactory::get()->extImagickDir = '';
        }
    }

    /**
     * Real gap: `escapeshellarg((string) realpath($file_path) . '[0]')`
     * -- the trailing '[0]' ImageMagick page-selector limits the PDF
     * conversion to its first page. uploadFilePdf() has no per-page-split
     * rename fallback (unlike uploadFileTiff()/uploadFilePsd()), so
     * dropping '[0]' on a real multi-page PDF makes ImageMagick emit
     * per-page-suffixed files instead of the exact expected filename,
     * which file_exists() then never finds.
     */
    public function testUploadFilePdfConvertsOnlyTheFirstPageViaThe0PageSelector(): void
    {
        $frame1 = $this->marker . '/pdf-frame1.png';
        $frame2 = $this->marker . '/pdf-frame2.png';
        $this->makeImage($frame1, 'png', 20, 20);
        $this->makeImage($frame2, 'png', 20, 20);

        $pdf = $this->marker . '/multi-page.pdf';
        $cmd = 'convert ' . escapeshellarg($frame1) . ' ' . escapeshellarg($frame2) . ' ' . escapeshellarg($pdf) . ' 2>&1';
        exec($cmd, $out, $status);
        if ($status !== 0) {
            self::markTestSkipped('ImageMagick convert failed to build a multi-page PDF fixture: ' . implode("\n", $out));
        }

        $result = $this->uploadFileExt(UploadService::uploadFilePdf(...), null, $pdf);

        self::assertSame('jpg', $result, 'the [0] page selector must limit conversion to a single page, not fall through to a per-page-suffixed filename that never matches the expected path');
        $representativePath = $this->marker . '/pwg_representative/multi-page.jpg';
        self::assertFileExists($representativePath);
        // Only the first frame's page was rendered -- no per-page sibling
        // was ever produced.
        self::assertFileDoesNotExist($this->marker . '/pwg_representative/multi-page-0.jpg');
    }

    /**
     * Real gap: `-resize "' . $w . 'x' . $h . '>"'` -- checked via the
     * exact logged exec string (uploadFileHeic() logs its own $exec),
     * rather than a real oversized conversion: any dropped/reordered
     * fragment breaks this exact substring.
     */
    public function testUploadFileHeicBuildsTheResizeGeometryFromTheExactOptimalWidthAndHeight(): void
    {
        $method = new ReflectionMethod(UploadService::class, 'getOptimalDimensionsForRepresentative');
        /** @var array{0: int, 1: int} $dims */
        $dims = $method->invoke(null);
        [$maxW, $maxH] = $dims;

        $logDir = $this->marker . '/heic-geometry-logs';
        mkdir($logDir, 0o777, true);
        $this->currentLogger->set(new Logger([
            'severity' => Logger::INFO,
            'directory' => $logDir,
            'filename' => 'heic-geometry.log',
        ]));

        try {
            $heic = $this->marker . '/geometry-check.heic';
            $this->makeFakeHeic($heic);

            $this->uploadFileExt(UploadService::uploadFileHeic(...), null, $heic);

            $logged = file_get_contents($logDir . '/heic-geometry.log');
            self::assertIsString($logged);
            self::assertStringContainsString('-resize "' . $maxW . 'x' . $maxH . '>"', $logged, 'the -resize geometry must be built from exactly $w . \'x\' . $h . \'>\', with no dropped or reordered fragment');
        } finally {
            $this->currentLogger->set(new Logger([
                'severity' => Logger::OFF,
            ]));
        }
    }

    /**
     * Real gap: uploadFileTiff()'s hardcoded `-quality 98` flag only when
     * `$representative_ext === 'jpg'` -- proven via a real file-size
     * comparison against an independent baseline (the SAME TIFF converted
     * directly with NO quality flag at all, i.e. ImageMagick's own
     * unconfigured JPEG default, well under 98), mirroring this file's
     * own "uploadFilePdf actually applies pdfJpgQuality" test's proven
     * technique.
     */
    public function testUploadFileTiffAppliesTheHardcodedQuality98FlagOnlyWhenRepresentativeExtIsJpg(): void
    {
        CurrentConfigTestFactory::get()->tiffRepresentativeExt = 'jpg';
        try {
            $dir = $this->marker;
            $png = $dir . '/tiff-quality-src.png';
            $exec = 'convert -size 400x400 plasma: ' . escapeshellarg($png) . ' 2>&1';
            exec($exec, $out, $status);
            if ($status !== 0) {
                self::markTestSkipped('ImageMagick convert failed to build the plasma source: ' . implode("\n", $out));
            }
            $tiff = $dir . '/tiff-quality.tiff';
            $this->convertSample($png, $tiff);

            // Independent baseline: the same TIFF converted directly, with
            // no -quality flag at all.
            $baseline = $dir . '/tiff-quality-baseline.jpg';
            $this->convertSample($tiff, $baseline);
            $baselineSize = filesize($baseline);
            if ($baselineSize === false) {
                throw new RuntimeException('filesize (baseline) failed');
            }

            $result = $this->uploadFileExt(UploadService::uploadFileTiff(...), null, $tiff);
            self::assertSame('jpg', $result);
            $producedPath = $dir . '/pwg_representative/tiff-quality.jpg';
            $producedSize = filesize($producedPath);
            if ($producedSize === false) {
                throw new RuntimeException('filesize (produced) failed');
            }

            self::assertGreaterThan($baselineSize, $producedSize, 'the hardcoded -quality 98 flag must produce a larger, higher-quality file than ImageMagick\'s own unconfigured default');
        } finally {
            CurrentConfigTestFactory::get()->tiffRepresentativeExt = 'png';
        }
    }

    /**
     * Real gap: `throw new Exception(__METHOD__ . '(): filesize()
     * failed for ' . $path);` -- pwgImageInfos() has no Integration-level
     * test at all yet; this checks the FULL message, including the
     * __METHOD__ prefix a bare substring check would miss.
     */
    public function testPwgImageInfosThrowsWithTheExactMethodPrefixedMessageWhenFilesizeFails(): void
    {
        $service = $this->newService();
        $missing = $this->marker . '/does-not-exist-at-all-integration.jpg';

        set_error_handler(static fn (): bool => true);
        try {
            $threw = null;
            try {
                $service->pwgImageInfos($missing);
            } catch (Exception $e) {
                $threw = $e;
            }
            self::assertNotNull($threw);
            self::assertSame(
                'Piwigo\Admin\Upload\UploadService::pwgImageInfos(): filesize() failed for ' . $missing,
                $threw->getMessage()
            );
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Real gap: `$filesize = floor($filesize_bytes / 1024);` --
     * distinguishes floor() from round() and from dividing by 1023/1025.
     */
    public function testPwgImageInfosComputesTheExactFloorOfBytesOver1024ForFilesize(): void
    {
        $path = $this->marker . '/pwgimageinfos-filesize.png';
        $this->makeImage($path, 'png', 33, 17);

        $infos = $this->newService()
            ->pwgImageInfos($path);
        $realBytes = filesize($path);
        if ($realBytes === false) {
            throw new RuntimeException('filesize failed');
        }

        self::assertSame(floor($realBytes / 1024), $infos->filesize);
    }

    /**
     * Real gap: `@chmod($upload_dir, 0777);` -- a narrower 0776 mode still
     * satisfies is_writable() from the owning user's own perspective
     * (only the OTHER-execute bit differs), so this checks the exact raw
     * permission bits instead.
     */
    public function testReadyForUploadMessageChmodsAnUnwritableExistingDirectoryToExactly0777(): void
    {
        $root = $this->marker . '/ready-root/';
        mkdir($root . 'upload', 0o777, true);
        chmod($root . 'upload', 0o555);
        CurrentConfigTestFactory::get()->uploadDir = 'upload/';

        try {
            $service = new UploadService(LangTestFactory::get(), $this->currentLogger, $this->storageRegistry, EventDispatcherTestFactory::get(), $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, Paths::fromRoot($root), DbCredentialsTestFactory::get());

            $message = $service->readyForUploadMessage();

            self::assertNull($message);
            self::assertSame(0o777, fileperms($root . 'upload') & 0o777, 'the recovery chmod() must set exactly 0777, not a narrower mode that happens to still satisfy is_writable() from the owning user\'s own perspective');
        } finally {
            chmod($root . 'upload', 0o777);
        }
    }

    /**
     * Real gap: prepareDirectoryStatic()'s own `@chmod($directory,
     * 0777);` recovery -- same "exact raw bits, not just is_writable()"
     * reasoning as the readyForUploadMessage() test above.
     */
    public function testPrepareDirectoryStaticFixesAnExistingUnwritableDirectoryToExactly0777(): void
    {
        $dir = $this->marker . '/prep-perm-check';
        mkdir($dir, 0o777, true);
        chmod($dir, 0o555);

        $method = new ReflectionMethod(UploadService::class, 'prepareDirectoryStatic');
        $method->invoke(null, $dir);

        self::assertSame(0o777, fileperms($dir) & 0o777);
    }

    /**
     * Real gap: the `umask(0000)` / `mkdir($directory, 0777, ...)` pair --
     * either a non-zero umask value or a narrower requested mkdir() mode
     * would silently produce a directory with less than the full 0777
     * this method's own docblock says it must guarantee.
     */
    public function testPrepareDirectoryStaticCreatesANewDirectoryWithExactly0777RegardlessOfProcessUmask(): void
    {
        $dir = $this->marker . '/new-dir-perm-check';
        self::assertDirectoryDoesNotExist($dir);

        $method = new ReflectionMethod(UploadService::class, 'prepareDirectoryStatic');
        $method->invoke(null, $dir);

        self::assertDirectoryExists($dir);
        self::assertSame(0o777, fileperms($dir) & 0o777, 'mkdir() must genuinely produce mode 0777 -- the umask(0)/restore pair around it exists precisely to prevent the process umask from narrowing this');
    }

    /**
     * Real gap ("Real bug" class already fixed once elsewhere in this
     * exact codebase, see this method's own source comment): the
     * `umask($umask);` restore -- without it, the process-wide umask
     * would stay permanently zeroed for the rest of this test process.
     */
    public function testPrepareDirectoryStaticRestoresTheProcessUmaskAfterwardInsteadOfLeavingItAtZero(): void
    {
        $originalUmask = umask();
        // A known, deliberately non-zero baseline first, so the
        // "restored" assertion below can't pass by coincidence if this
        // process's own baseline umask already happened to be 0.
        umask(0o022);

        try {
            $dir = $this->marker . '/umask-restore-check';
            $method = new ReflectionMethod(UploadService::class, 'prepareDirectoryStatic');
            $method->invoke(null, $dir);

            self::assertSame(0o022, umask(), 'prepareDirectoryStatic() must restore the process umask it temporarily zeroed, not leave it corrupted at 0 for the rest of the process');
        } finally {
            umask($originalUmask);
        }
    }

    /**
     * Real gap: currentLogger()/imageStdParams()/currentConfig()'s own
     * "Container returned an unexpected type" guards -- normally
     * unreachable through Kernel::boot()'s real public API, but directly
     * exercisable via KernelContainerOverride::withWrongTypeFor(), the
     * same test-only seam this project's own Bootstrap\*Accessor suites
     * already use for the identical pattern.
     */
    public function testThe3StaticContainerAccessorsThrowWhenTheContainerReturnsAnUnexpectedType(): void
    {
        KernelContainerOverride::withWrongTypeFor(CurrentLogger::class, static function (): void {
            $method = new ReflectionMethod(UploadService::class, 'currentLogger');
            $threw = null;
            try {
                $method->invoke(null);
            } catch (LogicException $e) {
                $threw = $e;
            }
            self::assertNotNull($threw);
            self::assertStringContainsString('Container returned an unexpected type for ' . CurrentLogger::class, $threw->getMessage());
        });

        KernelContainerOverride::withWrongTypeFor(ImageStdParams::class, static function (): void {
            $method = new ReflectionMethod(UploadService::class, 'imageStdParams');
            $threw = null;
            try {
                $method->invoke(null);
            } catch (LogicException $e) {
                $threw = $e;
            }
            self::assertNotNull($threw);
            self::assertStringContainsString('Container returned an unexpected type for ' . ImageStdParams::class, $threw->getMessage());
        });

        KernelContainerOverride::withWrongTypeFor(CurrentConfig::class, static function (): void {
            $method = new ReflectionMethod(UploadService::class, 'currentConfig');
            $threw = null;
            try {
                $method->invoke(null);
            } catch (LogicException $e) {
                $threw = $e;
            }
            self::assertNotNull($threw);
            self::assertStringContainsString('Container returned an unexpected type for ' . CurrentConfig::class, $threw->getMessage());
        });
    }
}
