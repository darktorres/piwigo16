<?php

declare(strict_types=1);

// getSyncMetadata() reads Piwigo\Core\CurrentPaths directly (same
// convention as every real bootstrap entry point) -- IntegrationTestCase's
// own setUp() already seeds it against this repo's real root, matching
// this file's own '_data/...'-relative fixture paths below.
//
// trigger_change() calls go directly through the real
// Piwigo\PluginConfig\EventDispatcher::get() singleton now, a pure
// passthrough with no handlers registered, so no local stub is needed.
namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Metadata\MetadataRepository;
    use Piwigo\Metadata\MetadataService;

final class MetadataServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private MetadataService $service;

    private Connection $conn;

    private string $scratchDir;

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
        $this->service = new MetadataService(new MetadataRepository(\Piwigo\Db\EntityManagerFactory::build($this->conn)));

        CurrentConfig::setUseIptc(false);
        CurrentConfig::setUseExif(true);
        CurrentConfig::setAllowHtmlInMetadata(false);
        CurrentConfig::setMetadataKeywordSeparatorRegex('/[.,;]/');
        CurrentConfig::setUseExifMapping(['author' => 'Artist', 'name' => 'ImageDescription']);
        CurrentConfig::setUseIptcMapping([]);

        // Self-contained scratch dir under this project's own _data/ (never
        // a real upload path) -- created here, torn down below.
        $this->scratchDir = dirname(__DIR__, 2) . '/_data/metadata-service-test-scratch';
        @mkdir($this->scratchDir, 0o777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->scratchDir . '/*');
        foreach ($files !== false ? $files : [] as $file) {
            @unlink($file);
        }

        @rmdir($this->scratchDir);

        parent::tearDown();
    }

    public function test_clean_iptc_value_strips_leading_null_bytes(): void
    {
        $value = chr(0) . chr(0) . 'Hello';

        self::assertSame('Hello', $this->service->cleanIptcValue($value));
    }

    public function test_clean_iptc_value_replaces_embedded_null_bytes(): void
    {
        self::assertSame('a b', $this->service->cleanIptcValue('a' . chr(0x00) . 'b'));
    }

    public function test_parse_exif_gps_data_converts_degrees_minutes_seconds(): void
    {
        // 41 deg, 54 min, 9.686 sec (~41.9027 decimal degrees), matching
        // the format documented on the original function.
        $latitude = $this->service->parseExifGpsData(['41/1', '54/1', '9686/1000'], 'N');

        self::assertEqualsWithDelta(41.9027, $latitude, 0.001);
    }

    public function test_parse_exif_gps_data_negates_for_south_and_west(): void
    {
        $latitude = $this->service->parseExifGpsData(['41/1', '54/1', '0/1'], 'S');

        self::assertLessThan(0, $latitude);
    }

    public function test_parse_exif_gps_data_handles_a_zero_denominator(): void
    {
        // must not emit a division-by-zero warning/error.
        $result = $this->service->parseExifGpsData(['1/0', '0/1', '0/1'], 'N');

        self::assertSame(0.0, $result);
    }

    public function test_metadata_normalize_keywords_string_converts_separators_to_commas(): void
    {
        $result = $this->service->metadataNormalizeKeywordsString('nature.travel;family');

        self::assertSame('nature,travel,family', $result);
    }

    public function test_metadata_normalize_keywords_string_deduplicates_and_trims(): void
    {
        $result = $this->service->metadataNormalizeKeywordsString(',,nature,nature,travel,,');

        self::assertSame('nature,travel', $result);
    }

    public function test_get_sync_metadata_attributes_includes_exif_fields_when_enabled(): void
    {
        $attributes = $this->service->getSyncMetadataAttributes();

        self::assertContains('filesize', $attributes);
        self::assertContains('width', $attributes);
        self::assertContains('height', $attributes);
        self::assertContains('author', $attributes);
        self::assertContains('name', $attributes);
        self::assertContains('latitude', $attributes);
        self::assertContains('longitude', $attributes);
    }

    public function test_get_sync_metadata_attributes_omits_exif_fields_when_disabled(): void
    {
        CurrentConfig::setUseExif(false);

        $attributes = $this->service->getSyncMetadataAttributes();

        self::assertNotContains('author', $attributes);
        self::assertNotContains('latitude', $attributes);
    }

    public function test_get_sync_metadata_returns_false_for_a_missing_file(): void
    {
        self::assertFalse($this->service->getSyncMetadata(['path' => 'no/such/file.jpg']));
    }

    public function test_get_sync_metadata_reads_filesize_from_a_real_file(): void
    {
        // A real (if minimal) JPEG, not arbitrary bytes -- exif_read_data()
        // treats truly non-JPEG content as an unsupported-format warning,
        // even file_get_contents()-@-suppressed. Padded with trailing NUL
        // bytes (ignored by every JPEG reader, which only look between
        // markers) to reach exactly 2048 bytes for the filesize assertion
        // below.
        $image = imagecreatetruecolor(1, 1);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image);
        $jpegBytes = ob_get_clean();
        $relativePath = '_data/metadata-service-test-scratch/sample.jpg';
        file_put_contents(dirname(__DIR__, 2) . '/' . $relativePath, str_pad($jpegBytes, 2048, "\0"));

        $result = $this->service->getSyncMetadata(['path' => $relativePath]);

        self::assertIsArray($result);
        self::assertSame(2.0, $result['filesize']);
    }

    /**
     * [SEC-20] A malicious SVG with an internal DTD subset declaring a
     * SYSTEM entity that reads a local file must never leak that file's
     * content into the parsed result, and must never hang/crash trying to
     * resolve it -- proven against a real temp file, not just a code
     * inspection.
     */
    public function test_get_sync_metadata_does_not_resolve_xxe_entities_in_svg(): void
    {
        $secretPath = $this->scratchDir . '/secret.txt';
        file_put_contents($secretPath, 'TOP-SECRET-CONTENT-' . uniqid());
        $secretContent = (string) file_get_contents($secretPath);

        $relativePath = '_data/metadata-service-test-scratch/malicious.svg';
        $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;
        file_put_contents(
            $absolutePath,
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file://' . $secretPath . '">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="&xxe;" height="100"></svg>'
        );

        $result = $this->service->getSyncMetadata(['path' => $relativePath]);

        self::assertIsArray($result);
        $encoded = (string) json_encode($result);
        self::assertStringNotContainsString($secretContent, $encoded);
        self::assertStringNotContainsString('TOP-SECRET-CONTENT', $encoded);
    }

    public function test_get_sync_metadata_parses_dimensions_from_a_benign_svg(): void
    {
        $relativePath = '_data/metadata-service-test-scratch/plain.svg';
        $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;
        file_put_contents(
            $absolutePath,
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="123" height="456"></svg>'
        );

        $result = $this->service->getSyncMetadata(['path' => $relativePath]);

        self::assertIsArray($result);
        self::assertSame(123, $result['width']);
        self::assertSame(456, $result['height']);
    }

    public function test_strip_html_in_metadata_removes_tag_markup_in_place(): void
    {
        $value = '<b>bold</b> text';

        $this->service->stripHtmlInMetadata($value, 'comment');

        self::assertSame('bold text', $value);
    }

    public function test_strip_html_in_metadata_coerces_a_non_scalar_value_to_an_empty_string(): void
    {
        $value = ['not', 'a', 'scalar'];

        $this->service->stripHtmlInMetadata($value, 'comment');

        self::assertSame('', $value);
    }
}
}
