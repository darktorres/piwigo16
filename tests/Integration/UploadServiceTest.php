<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Event\Picture\UploadFile;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;

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
    #[\Override]
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
 * Two real, previously-shipped production bugs were found and fixed
 * while writing the "update an existing photo" test below (item 3):
 *
 * [BUG 1] addUploadedFile()'s $image_id-not-null ("update") branch set
 * $file_path directly from the DB's `path` column, which is stored
 * root-relative (see the "new photo" branch's own preg_replace() against
 * CurrentPaths::get()->root, and addFormat()'s own identical "images.path
 * ... is relative, not yet an absolute path" handling elsewhere in this
 * same class) -- but every downstream use of $file_path in this method
 * (StorageRegistry::stripRoot(), chmod(), get_rotation_angle()/
 * pwgImageInfos()'s own getimagesize()/filesize() calls) requires an
 * absolute path, exactly like the "new photo" branch's $file_path always
 * was. This exact mismatch was already confirmed live and documented in
 * tests/Contract/WsImagesUploadGapsTest.php's own docblock ("confirmed
 * live, twice, that this exact code path 500s ... getimagesize(upload/
 * 2026/08/01/....jpg): Failed to open stream") as a then-out-of-scope
 * pre-existing bug for a Ws-domain test-writing pass; it lives squarely
 * inside this class's own addUploadedFile(), so it's fixed here.
 *
 * [BUG 2] The "cache a derivative" SELECT that builds $image_infos for
 * `new SrcImage($image_infos)` never selected the `file` column, even
 * though SrcImage::__construct()'s own docblock states id/path/file are
 * all trusted NOT-NULL DB columns (unlike representative_ext, read via
 * `?? null` as genuinely optional two lines below the same read) --
 * every real addUploadedFile() call (new photo or update) hit a real
 * "Undefined array key 'file'" PHP warning right there, which
 * phpunit.xml.dist's failOnWarning="true" turns into a hard failure the
 * moment anything exercises this method in-process (as opposed to over
 * real HTTP, where a warning is merely logged) -- the other half of what
 * WsImagesUploadGapsTest's docblock documented ("Undefined array key
 * 'file' in SrcImage.php"). Fixed by adding `file` to the SELECT.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql), same as
 * MetadataRepositoryTest/BatchUploadHandlerTest: images 1-5 exist before
 * any test runs here; image 1's md5sum is the well-known
 * 'fc6fccf1f8d70f6d7c6d627871f2ea6f' (BatchUploadHandlerTest's own
 * duplicate-detection fixture value, reused here for the same reason);
 * category 1 "Sample Album" and category 2 "Nested Sub Album" both exist,
 * with image 1 linked only to category 1 (not 2) in the stock fixture.
 * piwigo_config's own `lounge_active` row is 'true' in the fixture, but
 * this suite deliberately never calls ConfigService::loadConfFromDb() --
 * ImageStdParams::load_from_db() (needed for DerivativeImage::url(),
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

    private string $marker;

    /** @var list<int> */
    private array $imageIdsToDelete = [];

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

        $this->marker = sys_get_temp_dir() . '/piwigo-upload-service-integration-test-' . bin2hex(random_bytes(8));
        mkdir($this->marker, 0o777, true);

        // addUploadedFile() reaches Bootstrap\CoreDomainAccessor::
        // imageService()/Bootstrap\ExtendedDomainAccessor::activityService()/
        // metadataService()/Bootstrap\InfrastructureAccessor::entityManager()
        // -- all container-resolved, same rationale as
        // CategoryAdminServiceTest's own Kernel::boot() call. Re-boot Kernel
        // (parent::setUp() already booted it against the real repo root)
        // against this test's own throwaway marker root instead: the
        // StorageRegistry resolution below is a container factory that
        // reads Paths::class at first resolution, and CurrentPaths
        // (singleton/service-locator elimination campaign, Phase 3) is a
        // pure shim with no state of its own left to rebind after the fact.
        Kernel::reset();
        Kernel::boot(Paths::fromRoot($this->marker));

        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new \LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }
        $currentLogger->set(new Logger(['severity' => Logger::OFF]));
        $this->currentLogger = $currentLogger;

        // Kernel::reset() above also discards the container-shared
        // CurrentUser instance parent::setUp()'s own attachGlobals() seed
        // populated (singleton/service-locator elimination campaign, Phase
        // 5) -- without reseeding here, addUploadedFile()'s own
        // AccessControl-reaching call chain throws "not initialised"
        // against this fresh, unseeded container.
        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new \LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        $currentUser->attachGlobals();

        $this->conn = DbConnection::build();

        // Deliberately not followed by $configService->loadConfFromDb() --
        // see this class's own docblock.
        $configService = new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher());
        CurrentConfigService::current()->set($configService);
        $this->configService = $configService;
        // Needed for DerivativeImage::url()'s own ImageStdParams::
        // get_by_type() call (addUploadedFile()'s "cache a derivative"
        // tail) to resolve real sizing; falls back to sane built-in
        // defaults since the fixture's derivative_settings/derivative_size
        // rows are empty here (see this class's own docblock), matching
        // TemplateDefineDerivativeTest's own identical setup.
        ImageStdParams::current()->load_from_db();

        // Deliberate baseline, independent of the fixture's own DB-stored
        // 'lounge_active' row (see this class's own docblock) -- every test
        // that cares sets these explicitly; the high threshold here just
        // stops the fixture's pre-existing 5 images from tripping
        // addUploadedFileAddToCategories()'s own count check by accident in
        // every other test.
        CurrentConfig::setLoungeActive(false);
        CurrentConfig::setLoungeActivateThreshold(1_000_000);

        $htmlService = new HtmlService();
        $this->urlService = new UrlService($htmlService);
        DerivativeImage::setUrlService($this->urlService);
        // SrcImage::get_url()'s own mimetype-icon branch (same
        // finfo-fallback test as the ThemeConfProvider below) needs this
        // too -- a distinct static setter from DerivativeImage's own,
        // normally set once by Bootstrap\RequestBootstrap in a real
        // request.
        SrcImage::setUrlService($this->urlService);
        // See UploadServiceTestThemeConfProvider's own docblock -- harmless
        // for every test but the finfo-fallback one, which only reaches
        // SrcImage's real theme lookup when the stored extension isn't a
        // picture extension and has no representative_ext.
        SrcImage::setThemeConfProvider(new UploadServiceTestThemeConfProvider());

        // StorageRegistry is a container factory binding (singleton/
        // service-locator elimination campaign, Phase 2) that reads
        // CurrentPaths::get() at first resolution -- resolved here, against
        // the marker-rooted container booted above, so every disk (uploads,
        // derivatives, ...) correctly resolves under the marker root rather
        // than the real project root. Nothing else resolves
        // StorageRegistry::class from this container before this point.
        $storageRegistry = Kernel::container()->get(StorageRegistry::class);
        if (! $storageRegistry instanceof StorageRegistry) {
            throw new \LogicException('Container returned an unexpected type for ' . StorageRegistry::class);
        }
        $this->storageRegistry = $storageRegistry;

        $this->imageIdsToDelete = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->imageIdsToDelete !== []) {
            $ids = implode(',', array_map(static fn (int $id): string => (string) $id, $this->imageIdsToDelete));
            // ON DELETE CASCADE on both piwigo_image_category.image_id and
            // piwigo_lounge.image_id (see the schema) takes care of any
            // association rows a test created for these ids too.
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . " WHERE id IN ({$ids})");
        }
        // Cleans up the one fixture-image association a test adds (image 1
        // -> category 2, not present in the stock fixture) without
        // disturbing image 1 itself, which every other test in this class
        // still relies on for duplicate-detection.
        $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = 1 AND category_id = 2');
        // Same belt-and-suspenders reasoning as the image_category cleanup
        // above -- addFormat()'s own tests write real piwigo_image_format
        // rows against the shared fixture image 1, not a throwaway id this
        // class deletes wholesale.
        $this->conn->executeStatement('DELETE FROM ' . Tables::imageFormat() . ' WHERE image_id = 1');
        $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param IN ('lounge_active', 'count_orphans')");

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
            throw new \RuntimeException('imagecreatetruecolor failed');
        }
        $color = imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        if ($color === false) {
            throw new \RuntimeException('imagecolorallocate failed');
        }
        imagefill($img, 0, 0, $color);

        $ok = match ($format) {
            'png' => imagepng($img, $path),
            'jpeg' => imagejpeg($img, $path),
            'gif' => imagegif($img, $path),
            'webp' => imagewebp($img, $path),
            default => throw new \InvalidArgumentException("unsupported format {$format}"),
        };

        if (! $ok) {
            throw new \RuntimeException("failed to write a {$format} image to {$path}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchImageRow(int $imageId): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM ' . Tables::images() . ' WHERE id = ' . $imageId);
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
        return $handler(new UploadFile($representativeExt, $filePath))->representativeExt;
    }

    public function test_addUploadedFile_inserts_a_new_photo_and_writes_it_to_real_storage(): void
    {
        $source = $this->marker . '/incoming.png';
        $this->makeImage($source, 'png', 64, 48);
        $expectedMd5 = md5_file($source);
        self::assertIsString($expectedMd5);

        $imageId = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($source, $this->urlService, 'holiday.png');
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

    public function test_addUploadedFile_short_circuits_on_duplicate_md5sum_and_associates_categories(): void
    {
        $source = $this->marker . '/dup-source.jpg';
        file_put_contents($source, 'duplicate-upload-bytes');

        $countBefore = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());

        $result = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile(
            $source,
            $this->urlService,
            'dup.jpg',
            categories: [2],
            // Matches tests/Fixtures/piwigo-17.0.sql's own image #1
            // (fixture-photo-1.jpg) exactly, same fixture value
            // BatchUploadHandlerTest.php's own duplicate-detection test
            // already relies on.
            original_md5sum: 'fc6fccf1f8d70f6d7c6d627871f2ea6f',
        );

        self::assertSame(1, $result);
        self::assertFileDoesNotExist($source);

        // No second row was inserted -- the short-circuit returned the
        // existing image_id instead.
        $countAfter = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());
        self::assertSame($countBefore, $countAfter);

        // Image 1 is only linked to category 1 in the stock fixture --
        // addUploadedFileAddToCategories() still associates it to the
        // $categories given here, even on the short-circuit path.
        $linked = $this->countRows('SELECT COUNT(*) FROM ' . Tables::imageCategory() . ' WHERE image_id = 1 AND category_id = 2');
        self::assertSame(1, $linked);
    }

    /**
     * [BUG 1]/[BUG 2] regression test -- see this class's own docblock.
     * Before both fixes, this exact call threw ("Undefined array key
     * 'file'" turned into a PHPUnit warning-failure by the SrcImage read,
     * or a getimagesize() "Failed to open stream" from the un-prefixed
     * relative $file_path, depending on which line executes first).
     */
    public function test_addUploadedFile_updates_an_existing_photo_in_place(): void
    {
        $service = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService);

        $firstSource = $this->marker . '/first.png';
        $this->makeImage($firstSource, 'png', 40, 30);
        $firstId = $service->addUploadedFile($firstSource, $this->urlService, 'first.png');
        $id = $firstId;
        $this->imageIdsToDelete[] = $id;

        $countBefore = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());

        $secondSource = $this->marker . '/second.png';
        $this->makeImage($secondSource, 'png', 20, 15);
        $expectedMd5 = md5_file($secondSource);
        self::assertIsString($expectedMd5);

        $result = $service->addUploadedFile($secondSource, $this->urlService, 'second.png', image_id: $id);

        // Same id back -- an UPDATE, not a fresh INSERT.
        self::assertSame($id, $result);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());
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

    public function test_addUploadedFile_throws_when_the_given_image_id_does_not_exist(): void
    {
        $source = $this->marker . '/orphan-update.png';
        $this->makeImage($source, 'png', 10, 8);

        $countBefore = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());

        $threw = null;
        try {
            new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($source, $this->urlService, 'orphan.png', image_id: 999_999);
        } catch (ImageProcessingException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'addUploadedFile() should have thrown for a nonexistent image_id');
        self::assertStringContainsString('this photo does not exist in the database', $threw->getMessage());

        // Nothing was inserted or deleted -- the throw happens before any
        // write, unlike the SVG-mismatch/forbidden-type rejection tests
        // below, which unlink() the source first.
        self::assertFileExists($source);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());
        self::assertSame($countBefore, $countAfter);
    }

    public function test_addUploadedFile_updates_the_level_when_given_on_the_update_branch(): void
    {
        $service = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService);

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

    public function test_addUploadedFile_dispatches_the_correct_extension_for_each_raster_image_type(): void
    {
        $service = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService);
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

    public function test_addUploadedFile_resolves_a_non_standard_extension_via_the_finfo_fallback_when_all_types_allowed(): void
    {
        CurrentConfig::setUploadFormAllTypes(true);

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

        $imageId = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($source, $this->urlService, 'archive.zip');
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
    public function test_addUploadedFile_resizes_the_original_when_it_exceeds_the_configured_max_dimensions(): void
    {
        if (PwgImage::get_library() === 'gd') {
            self::markTestSkipped('No non-GD image library (ext_imagick/imagick) available in this environment -- addUploadedFile() never reaches its own originalResize()/needResize()/pwg_resize() block when PwgImage::get_library() is gd.');
        }

        CurrentConfig::setOriginalResize(true);
        CurrentConfig::setOriginalResizeMaxwidth(50);
        CurrentConfig::setOriginalResizeMaxheight(50);
        CurrentConfig::setOriginalResizeQuality(90);

        $source = $this->marker . '/big.jpg';
        $this->makeImage($source, 'jpeg', 200, 150);

        $imageId = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($source, $this->urlService, 'big.jpg');
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
     * EventDispatcher::get()->addEventHandler()/EventDispatcher::reset()
     * pair.
     */
    public function test_addUploadedFile_stores_the_representative_ext_when_an_upload_file_handler_matches(): void
    {
        CurrentConfig::setUploadFormAllTypes(true);

        EventDispatcher::get()->addTypedHandler(UploadFile::class, UploadService::uploadFilePdf(...));

        try {
            $png = $this->marker . '/pdf-source.png';
            $this->makeImage($png, 'png', 40, 40);
            $pdf = $this->marker . '/document.pdf';
            $cmd = 'convert ' . escapeshellarg($png) . ' ' . escapeshellarg($pdf) . ' 2>&1';
            exec($cmd, $out, $status);
            if ($status !== 0) {
                self::markTestSkipped('ImageMagick convert failed to build a PDF fixture: ' . implode("\n", $out));
            }

            $imageId = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($pdf, $this->urlService, 'document.pdf');
            $id = $imageId;
            $this->imageIdsToDelete[] = $id;

            $row = $this->fetchImageRow($id);
            self::assertSame('jpg', $row['representative_ext']);
        } finally {
            EventDispatcher::get()->reset();
        }
    }

    public function test_addUploadedFile_rejects_a_mismatched_svg_mime_type_and_deletes_the_source_file(): void
    {
        CurrentConfig::setUploadFormAllTypes(true);

        // Genuine SVG content (finfo sniffs 'image/svg+xml'), but the
        // original filename claims '.png' -- the exact mismatch
        // sanitizeSvgIfNeeded()'s own Unit tests build SVG fixtures for,
        // reused here to reach addUploadedFile()'s own MIME/extension
        // mismatch guard instead of the sanitizer directly.
        $source = $this->marker . '/fake.png';
        file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');

        $countBefore = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());

        $threw = null;
        try {
            new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($source, $this->urlService, 'fake.png');
        } catch (ImageProcessingException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'addUploadedFile() should have thrown for a mismatched SVG MIME type');
        self::assertStringContainsString('does not match file MIME type', $threw->getMessage());

        self::assertFileDoesNotExist($source);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());
        self::assertSame($countBefore, $countAfter);
    }

    public function test_addUploadedFile_rejects_an_extension_absent_from_fileExtensions_even_when_all_types_are_allowed(): void
    {
        CurrentConfig::setUploadFormAllTypes(true);

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

        $countBefore = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());

        $threw = null;
        try {
            new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($source, $this->urlService, 'payload.exe');
        } catch (ImageProcessingException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'addUploadedFile() should have thrown for an extension absent from fileExtensions()');
        self::assertSame('unexpected file type', $threw->getMessage());

        self::assertFileDoesNotExist($source);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());
        self::assertSame($countBefore, $countAfter);
    }

    public function test_addUploadedFile_rejects_a_forbidden_file_type_and_deletes_the_source_file(): void
    {
        CurrentConfig::setUploadFormAllTypes(false);

        $source = $this->marker . '/notes.txt';
        file_put_contents($source, 'just some plain text, not an image at all');

        $countBefore = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());

        $threw = null;
        try {
            new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($source, $this->urlService, 'notes.txt');
        } catch (ImageProcessingException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'addUploadedFile() should have thrown for a forbidden file type');
        self::assertSame('forbidden file type', $threw->getMessage());

        self::assertFileDoesNotExist($source);
        $countAfter = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());
        self::assertSame($countBefore, $countAfter);
    }

    public function test_add_uploaded_file_add_to_categories_flips_lounge_active_once_the_photo_count_reaches_the_threshold(): void
    {
        // The fixture's own piwigo_config row already has 'lounge_active' =
        // 'true' (see this class's own docblock) -- force it to 'false' here
        // so the assertion below actually proves confUpdateParam() wrote a
        // real change, not just a coincidental match with pre-existing data.
        $this->conn->executeStatement("UPDATE " . Tables::config() . " SET value = 'false' WHERE param = 'lounge_active'");

        $countBefore = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());
        CurrentConfig::setLoungeActivateThreshold($countBefore + 1);
        self::assertFalse(CurrentConfig::loungeActive());

        $source = $this->marker . '/threshold.png';
        $this->makeImage($source, 'png', 10, 8);

        $imageId = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($source, $this->urlService, 'threshold.png');
        $this->imageIdsToDelete[] = $imageId;

        // addUploadedFileAddToCategories()'s own COUNT(*) check (running
        // right after the insert, so it already sees the new row) now
        // meets the threshold set above.
        self::assertTrue(CurrentConfig::loungeActive());

        $dbValue = $this->conn->fetchOne("SELECT value FROM " . Tables::config() . " WHERE param = 'lounge_active'");
        self::assertSame('true', $dbValue);
    }

    public function test_add_uploaded_file_add_to_categories_associates_a_new_photo_to_the_given_categories_when_lounge_is_inactive(): void
    {
        $source = $this->marker . '/associate.png';
        $this->makeImage($source, 'png', 10, 8);

        $imageId = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService)->addUploadedFile($source, $this->urlService, 'associate.png', categories: [1]);
        $id = $imageId;
        $this->imageIdsToDelete[] = $id;

        // setUp()'s own high default threshold keeps the lounge inactive
        // here, so this exercises associateImagesToCategories(), not
        // fillLounge().
        self::assertFalse(CurrentConfig::loungeActive());

        $linked = $this->countRows('SELECT COUNT(*) FROM ' . Tables::imageCategory() . ' WHERE image_id = ' . $id . ' AND category_id = 1');
        self::assertSame(1, $linked);

        $inLounge = $this->countRows('SELECT COUNT(*) FROM ' . Tables::lounge() . ' WHERE image_id = ' . $id);
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
    public function test_upload_file_tiff_falls_back_to_the_split_file_rename_when_imagemagick_produces_per_page_output(): void
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

    public function test_addFormat_throws_when_the_format_of_image_does_not_exist(): void
    {
        CurrentConfig::setIsFormatsEnabled(true);
        CurrentConfig::setFormatExtensions(['tif']);

        try {
            $service = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService);
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
            CurrentConfig::setIsFormatsEnabled(false);
            CurrentConfig::setFormatExtensions(['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd']);
        }
    }

    /**
     * addFormat()'s own getFormatIdByImageAndExt()-then-branch: a first
     * call for a given (image, ext) pair inserts a new
     * piwigo_image_format row ('add'); a second call for the exact same
     * pair updates that same row's filesize in place instead of inserting
     * a duplicate ('update') -- neither status was exercised by any
     * existing test before this one (only the two disabled-formats/
     * unauthorized-extension guard tests existed in
     * tests/Unit/Admin/Upload/UploadServiceTest.php).
     */
    public function test_addFormat_inserts_then_updates_the_same_format_row_on_a_second_call(): void
    {
        CurrentConfig::setIsFormatsEnabled(true);
        CurrentConfig::setFormatExtensions(['tif']);

        try {
            $service = new UploadService($this->currentLogger, $this->storageRegistry, \Piwigo\PluginConfig\EventDispatcher::get(), $this->configService);

            $sourceV1 = $this->marker . '/format-v1.tif';
            file_put_contents($sourceV1, str_repeat('a', 128));
            $result1 = $service->addFormat($sourceV1, 'tif', 1);
            self::assertSame('add', $result1);

            $sourceV2 = $this->marker . '/format-v2.tif';
            file_put_contents($sourceV2, str_repeat('b', 512));
            $result2 = $service->addFormat($sourceV2, 'tif', 1);
            self::assertSame('update', $result2);

            $count = $this->countRows('SELECT COUNT(*) FROM ' . Tables::imageFormat() . " WHERE image_id = 1 AND ext = 'tif'");
            self::assertSame(1, $count, 'a second addFormat() call for the same image/ext should UPDATE, not duplicate, the row');
        } finally {
            CurrentConfig::setIsFormatsEnabled(false);
            CurrentConfig::setFormatExtensions(['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd']);
        }
    }

    /**
     * uploadFileHeic() calls getOptimalDimensionsForRepresentative()
     * (private, static) right before its own real `convert` invocation --
     * that helper's own per-type loop is dead in
     * tests/Unit/Admin/Upload/UploadServiceTest.php's own "returns a
     * positive width/height pair" test (that suite never calls
     * ImageStdParams::load_from_db(), so every type's own $params stays
     * null there), unlike here, where this class's own setUp() already
     * does -- genuinely exercising it. Whether or not this environment's
     * ImageMagick build carries a libheif delegate (unconfirmed, unlike
     * the ext_imagick binary itself -- see this file's own uploadFileTiff/
     * Pdf/Psd/Eps tests), uploadFileHeic() always reaches its own tail
     * `return $representative_ext;`: null if the real `convert`
     * invocation failed to produce a file, 'jpg' if a genuinely available
     * delegate succeeded.
     */
    public function test_upload_file_heic_reaches_its_tail_return_via_getOptimalDimensionsForRepresentative(): void
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
    public function test_upload_file_video_passes_through_an_already_set_representative_ext(): void
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
    public function test_upload_file_video_generates_a_poster_from_a_real_video_and_logs_ffmpegs_stderr_output(): void
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
    public function test_upload_file_video_returns_null_and_falls_back_through_avconv_when_ffmpeg_cannot_decode_the_file(): void
    {
        $fake = $this->marker . '/broken.mp4';
        file_put_contents($fake, "this is not a video file, just plain text with a video-like extension\n");

        $result = $this->uploadFileExt(UploadService::uploadFileVideo(...), null, $fake);

        self::assertNull($result);
        self::assertFileDoesNotExist($this->marker . '/pwg_representative/broken.jpg');
    }
}
