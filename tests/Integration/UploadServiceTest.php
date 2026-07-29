<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Url\UrlService;

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
 * '2e7ee450c4a4cffe42945205029782b9' (BatchUploadHandlerTest's own
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

        // addUploadedFile() reaches Bootstrap\CoreDomainAccessor::
        // imageService()/Bootstrap\ExtendedDomainAccessor::activityService()/
        // metadataService()/Bootstrap\InfrastructureAccessor::entityManager()
        // -- all container-resolved, same rationale as
        // CategoryAdminServiceTest's own Kernel::boot() call.
        Kernel::boot();

        $this->conn = DbConnection::build();

        // Deliberately not followed by $configService->loadConfFromDb() --
        // see this class's own docblock.
        $configService = new ConfigService($this->buildConfigRepository());
        CurrentConfigService::set($configService);
        // Needed for DerivativeImage::url()'s own ImageStdParams::
        // get_by_type() call (addUploadedFile()'s "cache a derivative"
        // tail) to resolve real sizing; falls back to sane built-in
        // defaults since the fixture's derivative_settings/derivative_size
        // rows are empty here (see this class's own docblock), matching
        // TemplateDefineDerivativeTest's own identical setup.
        ImageStdParams::load_from_db();

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

        $this->marker = sys_get_temp_dir() . '/piwigo-upload-service-integration-test-' . bin2hex(random_bytes(8));
        mkdir($this->marker, 0o777, true);
        CurrentPaths::set(Paths::fromRoot($this->marker));
        // A bare StorageRegistry::reset() is not enough: current()'s own
        // lazy rebuild resolves config/storage.php *relative to the new
        // CurrentPaths root* (CurrentPaths::get()->root . 'config/storage.php'),
        // which is now the marker directory -- no config/ subdirectory
        // exists there. Explicitly re-require the real project's
        // config/storage.php instead: its factories close over $paths =
        // CurrentPaths::get(), captured at this require, which is already
        // the marker root set just above, so every disk (uploads,
        // derivatives, ...) correctly resolves under the marker.
        StorageRegistry::set(StorageRegistry::fromConfig(dirname(__DIR__, 2) . '/config/storage.php'));

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
        $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param IN ('lounge_active', 'count_orphans')");

        StorageRegistry::reset();
        self::rrmdir($this->marker);

        Kernel::reset();
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

    public function test_addUploadedFile_inserts_a_new_photo_and_writes_it_to_real_storage(): void
    {
        $source = $this->marker . '/incoming.png';
        $this->makeImage($source, 'png', 64, 48);
        $expectedMd5 = md5_file($source);
        self::assertIsString($expectedMd5);

        $imageId = new UploadService()->addUploadedFile($source, $this->urlService, 'holiday.png');
        $id = (int) $imageId;
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

        $result = new UploadService()->addUploadedFile(
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

        self::assertSame(1, (int) $result);
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
        $service = new UploadService();

        $firstSource = $this->marker . '/first.png';
        $this->makeImage($firstSource, 'png', 40, 30);
        $firstId = $service->addUploadedFile($firstSource, $this->urlService, 'first.png');
        $id = (int) $firstId;
        $this->imageIdsToDelete[] = $id;

        $countBefore = $this->countRows('SELECT COUNT(*) FROM ' . Tables::images());

        $secondSource = $this->marker . '/second.png';
        $this->makeImage($secondSource, 'png', 20, 15);
        $expectedMd5 = md5_file($secondSource);
        self::assertIsString($expectedMd5);

        $result = $service->addUploadedFile($secondSource, $this->urlService, 'second.png', image_id: $id);

        // Same id back -- an UPDATE, not a fresh INSERT.
        self::assertSame($id, (int) $result);
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

    public function test_addUploadedFile_dispatches_the_correct_extension_for_each_raster_image_type(): void
    {
        $service = new UploadService();
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
            $id = (int) $imageId;
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

        $imageId = new UploadService()->addUploadedFile($source, $this->urlService, 'archive.zip');
        $id = (int) $imageId;
        $this->imageIdsToDelete[] = $id;

        $row = $this->fetchImageRow($id);
        self::assertIsString($row['path']);
        self::assertStringEndsWith('.zip', $row['path']);

        $absolutePath = $this->marker . '/' . $row['path'];
        self::assertFileExists($absolutePath);
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
            new UploadService()->addUploadedFile($source, $this->urlService, 'fake.png');
        } catch (ImageProcessingException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'addUploadedFile() should have thrown for a mismatched SVG MIME type');
        self::assertStringContainsString('does not match file MIME type', $threw->getMessage());

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
            new UploadService()->addUploadedFile($source, $this->urlService, 'notes.txt');
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

        $imageId = new UploadService()->addUploadedFile($source, $this->urlService, 'threshold.png');
        $this->imageIdsToDelete[] = (int) $imageId;

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

        $imageId = new UploadService()->addUploadedFile($source, $this->urlService, 'associate.png', categories: [1]);
        $id = (int) $imageId;
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

        $result = UploadService::uploadFileTiff(null, $tiff);

        self::assertIsString($result);
        $representativePath = $this->marker . '/pwg_representative/multi.' . $result;
        self::assertFileExists($representativePath);
        self::assertGreaterThan(0, filesize($representativePath));
        // The per-page sibling was renamed away, not left behind alongside
        // the recovered file.
        self::assertFileDoesNotExist($this->marker . '/pwg_representative/multi-0.' . $result);
    }
}
