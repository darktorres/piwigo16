<?php

declare(strict_types=1);

// getSyncMetadata() reads Piwigo\Core\CurrentPaths directly (same
// convention as every real bootstrap entry point) -- IntegrationTestCase's
// own setUp() already seeds it against this repo's real root, matching
// this file's own '_data/...'-relative fixture paths below.

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Imagick;
    use LogicException;
    use Override;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\CurrentLogger;
    use Piwigo\Core\Kernel;
    use Piwigo\Core\Logger;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Metadata\Event\CleanIptcValue;
    use Piwigo\Metadata\ExifTool\ExifToolFfi;
    use Piwigo\Metadata\MetadataRepository;
    use Piwigo\Metadata\MetadataService;
    use Piwigo\Metadata\Projection\SvgDimensions;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\DbTransactionTestOverride;
    use Piwigo\Tests\Support\EventDispatcherTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use ReflectionMethod;
    use RuntimeException;
    use Symfony\Component\Process\Process;

    final class MetadataServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private MetadataService $service;

        private Connection $conn;

        private string $scratchDir;

        private ExifToolFfi $exifTool;

        #[Override]
        protected function setUp(): void
        {
            parent::setUp();
            $this->setUpConnectionFromEnv();

            if (! self::$fixtureReady) {
                $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
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
            $currentLogger = new CurrentLogger();
            $currentLogger->set(new Logger([
                'severity' => Logger::OFF,
            ]));
            $this->service = new MetadataService(LangTestFactory::get(), new MetadataRepository(EntityManagerFactory::build($this->conn)), $currentLogger, EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), CurrentUserTestFactory::get(), CurrentPathsTestFactory::get());
            $this->exifTool = new ExifToolFfi();

            CurrentConfigTestFactory::get()->useIptc = false;
            CurrentConfigTestFactory::get()->useExif = true;
            CurrentConfigTestFactory::get()->allowHtmlInMetadata = false;
            CurrentConfigTestFactory::get()->metadataKeywordSeparatorRegex = '/[.,;]/';
            CurrentConfigTestFactory::get()->useExifMapping = [
                'author' => 'Artist',
                'name' => 'ImageDescription',
            ];
            CurrentConfigTestFactory::get()->useIptcMapping = [];

            // Self-contained scratch dir under this project's own _data/ (never
            // a real upload path) -- created here, torn down below.
            $this->scratchDir = dirname(__DIR__, 2) . '/_data/metadata-service-test-scratch';
            @mkdir($this->scratchDir, 0o777, true);
        }

        #[Override]
        protected function tearDown(): void
        {
            $this->exifTool->close();

            $files = glob($this->scratchDir . '/*');
            foreach ($files !== false ? $files : [] as $file) {
                @unlink($file);
            }

            @rmdir($this->scratchDir);

            DbTransactionTestOverride::rollback();
            parent::tearDown();
        }

        private function permissionService(): PermissionService
        {
            $permissionService = Kernel::container()->get(PermissionService::class);
            if (! $permissionService instanceof PermissionService) {
                throw new LogicException('Container returned an unexpected type for ' . PermissionService::class);
            }

            return $permissionService;
        }

        /**
         * Builds a real, minimal JPEG (via GD) with a hand-built Photoshop-IRB
         * APP13 segment (the real "8BIM"/0x0404 IPTC-NAA resource block format)
         * spliced in right after the SOI marker -- same "getimagesize()/
         * iptcparse() need genuinely valid marker-segment bytes" reasoning as
         * ImageBackendTest's own EXIF-orientation helper (Admin\Image domain)
         * -- neither ImageMagick's `-set`/`-define` nor a
         * synthetic `xc:` canvas actually persists an IPTC profile either.
         * Confirmed live that real ExifTool parses this exact hand-rolled
         * block identically to PHP's own iptcparse() (both decode dataset 5
         * as ObjectName/title, 80 as By-line/author, repeated 25 as an
         * array of Keywords) -- this fixture-building technique doesn't
         * need to change now that extraction goes through ExifTool.
         *
         * @param  list<array{0: int<0, 255>, 1: string}>  $records  [datasetNumber, value] pairs, all under IPTC record 2 (Application Record)
         */
        private function makeJpegWithApp13Iptc(array $records): string
        {
            $iptcData = '';
            foreach ($records as [$dataset, $value]) {
                $iptcData .= "\x1c" . chr(2) . chr($dataset) . pack('n', strlen($value)) . $value;
            }
            // Empty Pascal-string resource name, 1-byte length prefix already
            // even-length (0+1=1... padded to 2 below per the IRB spec).
            $nameField = chr(0);
            $nameField .= "\x00";
            $blockData = $iptcData;
            if (strlen($blockData) % 2 !== 0) {
                $blockData .= "\x00";
            }
            $block = '8BIM' . pack('n', 0x0404) . $nameField . pack('N', strlen($iptcData)) . $blockData;
            $psHeader = "Photoshop 3.0\x00" . $block;
            $app13 = "\xFF\xED" . pack('n', strlen($psHeader) + 2) . $psHeader;

            $img = imagecreatetruecolor(6, 6);
            if ($img === false) {
                throw new RuntimeException('imagecreatetruecolor failed');
            }
            ob_start();
            imagejpeg($img);
            // ob_get_clean() can only return false when there's no active output
            // buffer -- ob_start() immediately above guarantees one here.
            $base = ob_get_clean();
            assert(is_string($base));

            return substr($base, 0, 2) . $app13 . substr($base, 2);
        }

        /**
         * A real, minimal JPEG (via GD) with no special markers.
         */
        private function makePlainJpeg(): string
        {
            $img = imagecreatetruecolor(6, 6);
            if ($img === false) {
                throw new RuntimeException('imagecreatetruecolor failed');
            }
            ob_start();
            imagejpeg($img);
            $base = ob_get_clean();
            if ($base === false) {
                throw new RuntimeException('ob_get_clean failed');
            }

            return $base;
        }

        /**
         * Writes real tags into an existing file via the actual `exiftool`
         * binary -- every getExifData()/getSyncExifData() test below needs
         * genuine embedded tags now that extraction goes through a real
         * ExifToolFfi, not a `format_exif_data` plugin-injected fake
         * $exif array (that event no longer exists -- getExifData() has no
         * "PHP found nothing, ask a plugin" moment in the same shape once
         * exif_read_data() itself is gone).
         *
         * @param  array<string, string>  $tags  tag name => value, written as `-Tag=value`
         */
        private function tagFileWithExifTool(string $path, array $tags): void
        {
            $command = ['exiftool', '-overwrite_original'];
            foreach ($tags as $tag => $value) {
                $command[] = '-' . $tag . '=' . $value;
            }
            $command[] = $path;

            $process = new Process($command);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('exiftool tagging failed: ' . $process->getErrorOutput());
            }
        }

        public function testCleanIptcValueStripsLeadingNullBytes(): void
        {
            self::assertSame('abc', $this->service->cleanIptcValue(chr(0x00) . chr(0x00) . 'abc'));
        }

        public function testCleanIptcValueReplacesEmbeddedNullBytes(): void
        {
            self::assertSame('a b', $this->service->cleanIptcValue('a' . chr(0x00) . 'b'));
        }

        public function testMetadataNormalizeKeywordsStringConvertsSeparatorsToCommas(): void
        {
            $result = $this->service->metadataNormalizeKeywordsString('nature.travel;family');

            self::assertSame('nature,travel,family', $result);
        }

        public function testMetadataNormalizeKeywordsStringDeduplicatesAndTrims(): void
        {
            $result = $this->service->metadataNormalizeKeywordsString('nature,nature,,travel,');

            self::assertSame('nature,travel', $result);
        }

        public function testGetSyncMetadataAttributesIncludesExifFieldsWhenEnabled(): void
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

        public function testGetSyncMetadataAttributesOmitsExifFieldsWhenDisabled(): void
        {
            CurrentConfigTestFactory::get()->useExif = false;

            $attributes = $this->service->getSyncMetadataAttributes();

            self::assertNotContains('author', $attributes);
            self::assertNotContains('latitude', $attributes);
        }

        public function testGetSyncMetadataReturnsFalseForAMissingFile(): void
        {
            self::assertFalse($this->service->getSyncMetadata([
                'path' => 'no/such/file.jpg',
            ], $this->exifTool));
        }

        public function testGetSyncMetadataReadsFilesizeFromARealFile(): void
        {
            $image = imagecreatetruecolor(1, 1);
            self::assertNotFalse($image);
            ob_start();
            imagejpeg($image);
            $jpegBytes = ob_get_clean();
            assert(is_string($jpegBytes));
            $relativePath = '_data/metadata-service-test-scratch/sample.jpg';
            file_put_contents(dirname(__DIR__, 2) . '/' . $relativePath, str_pad($jpegBytes, 2048, "\0"));

            $result = $this->service->getSyncMetadata([
                'path' => $relativePath,
            ], $this->exifTool);

            self::assertIsArray($result);
            self::assertSame(2.0, $result['filesize']);
        }

        // getSyncMetadata()'s own `if ($fs === false) { return false; }` guard
        // right after `filesize($file)` is not chased here: it's only
        // reachable if filesize() fails on a path is_readable() (the guard
        // directly above it) just confirmed true -- a
        // custom stream wrapper reporting a readable regular file via
        // url_stat() (the ImageServiceTestFailedOpenStreamWrapper-style
        // technique that isolates countPdfPages()'s analogous
        // is_file()-then-file_get_contents() branch) can't isolate this one:
        // PHP's own stream stat cache means is_readable()'s url_stat() call
        // and filesize()'s subsequent one on the exact same path resolve from
        // the *same* cached stat result (forcing url_stat() to
        // fail on a 2nd call never fires a 2nd call at all without an explicit
        // clearstatcache() between them, which getSyncMetadata() itself never
        // does) -- so a path that is_readable() accepts always has its
        // filesize() succeed too, both here and on a real filesystem. A
        // genuine TOCTOU race (the file vanishing between the two calls) is
        // the only real-world trigger, not deterministically reproducible.

        /**
         * [SEC-20] A malicious SVG with an internal DTD subset declaring a
         * SYSTEM entity that reads a local file must never leak that file's
         * content into the parsed result, and must never hang/crash trying to
         * resolve it -- proven against a real temp file, not just a code
         * inspection. Also proves the real exiftool-rs engine this file is now
         * also handed to (getExifData()'s own unconditional GPS-tag request,
         * regardless of file type) doesn't reintroduce the same class of leak
         * -- confirmed separately, live, that ExifTool's own XML parsing
         * returns the literal unresolved `&xxe;` entity text rather than the
         * referenced file's content.
         */
        public function testGetSyncMetadataDoesNotResolveXxeEntitiesInSvg(): void
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

            $result = $this->service->getSyncMetadata([
                'path' => $relativePath,
            ], $this->exifTool);

            self::assertIsArray($result);
            $encoded = (string) json_encode($result);
            self::assertStringNotContainsString($secretContent, $encoded);
            self::assertStringNotContainsString('TOP-SECRET-CONTENT', $encoded);
        }

        public function testGetSyncMetadataParsesDimensionsFromABenignSvg(): void
        {
            $relativePath = '_data/metadata-service-test-scratch/plain.svg';
            $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;
            file_put_contents(
                $absolutePath,
                '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="123" height="456"></svg>'
            );

            $result = $this->service->getSyncMetadata([
                'path' => $relativePath,
            ], $this->exifTool);

            self::assertIsArray($result);
            self::assertSame(123, $result['width']);
            self::assertSame(456, $result['height']);
        }

        public function testStripHtmlInMetadataRemovesTagMarkupInPlace(): void
        {
            $value = '<b>bold</b> text';

            $this->service->stripHtmlInMetadata($value, 'comment');

            self::assertSame('bold text', $value);
        }

        public function testStripHtmlInMetadataCoercesANonScalarValueToAnEmptyString(): void
        {
            $value = ['not', 'a', 'scalar'];

            $this->service->stripHtmlInMetadata($value, 'comment');

            self::assertSame('', $value);
        }

        // ------------------------------------------------------------ getIptcData()

        public function testGetIptcDataReturnsEmptyWhenTheFileDoesNotExist(): void
        {
            $result = $this->service->getIptcData($this->scratchDir . '/no-such-file.jpg', [
                'title' => '2#005',
            ], $this->exifTool);

            self::assertSame([], $result);
        }

        public function testGetIptcDataParsesRealIptcFieldsIncludingTheKeywordArrayJoin(): void
        {
            $bytes = $this->makeJpegWithApp13Iptc([
                [5, 'Sunset Over The Bay'],
                [80, 'Jane Photographer'],
                [25, 'nature'],
                [25, 'travel'],
            ]);
            $path = $this->scratchDir . '/iptc-fields.jpg';
            file_put_contents($path, $bytes);

            $result = $this->service->getIptcData($path, [
                'title' => '2#005',
                'author' => '2#080',
                'keywords' => '2#025',
            ], $this->exifTool, '|');

            self::assertSame([
                'title' => 'Sunset Over The Bay',
                'author' => 'Jane Photographer',
                'keywords' => 'nature|travel',
            ], $result);
        }

        public function testGetIptcDataSkipsARequestedMapFieldThatThePhotoHasNoIptcRecordFor(): void
        {
            // $map requests 'caption' (2#120), but the embedded IPTC data
            // below only has a title (2#005) record.
            $bytes = $this->makeJpegWithApp13Iptc([[5, 'Sunset Over The Bay']]);
            $path = $this->scratchDir . '/iptc-missing-field.jpg';
            file_put_contents($path, $bytes);

            $result = $this->service->getIptcData($path, [
                'title' => '2#005',
                'caption' => '2#120',
            ], $this->exifTool);

            self::assertSame([
                'title' => 'Sunset Over The Bay',
            ], $result);
        }

        public function testGetIptcDataStripsHtmlWhenAllowHtmlInMetadataIsDisabled(): void
        {
            CurrentConfigTestFactory::get()->allowHtmlInMetadata = false;
            $bytes = $this->makeJpegWithApp13Iptc([[5, '<b>Bold</b> Title']]);
            $path = $this->scratchDir . '/iptc-html-stripped.jpg';
            file_put_contents($path, $bytes);

            $result = $this->service->getIptcData($path, [
                'title' => '2#005',
            ], $this->exifTool);

            self::assertSame([
                'title' => 'Bold Title',
            ], $result);
        }

        public function testGetIptcDataKeepsHtmlWhenAllowHtmlInMetadataIsEnabled(): void
        {
            CurrentConfigTestFactory::get()->allowHtmlInMetadata = true;
            $bytes = $this->makeJpegWithApp13Iptc([[5, '<b>Bold</b> Title']]);
            $path = $this->scratchDir . '/iptc-html-kept.jpg';
            file_put_contents($path, $bytes);

            $result = $this->service->getIptcData($path, [
                'title' => '2#005',
            ], $this->exifTool);

            self::assertSame([
                'title' => '<b>Bold</b> Title',
            ], $result);
        }

        // ----------------------------------------------------------- cleanIptcValue()

        public function testCleanIptcValueLetsAPluginHandlerOverrideTheValue(): void
        {
            $handler = static function (CleanIptcValue $event): void {
                $event->value = 'plugin-override';
            };
            EventDispatcherTestFactory::get()->addTypedHandler(CleanIptcValue::class, $handler);

            try {
                $result = $this->service->cleanIptcValue("raw \x92 value");

                self::assertSame('plugin-override', $result);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(CleanIptcValue::class, $handler);
            }
        }

        public function testCleanIptcValuePassesThroughAValueThatIsAlreadyValidUtf8(): void
        {
            // 'é' as a real 2-byte UTF-8 sequence (0xC3 0xA9) -- qualifyUtf8()
            // classifies this as valid UTF-8 (not iso-8859-1/windows-1252), so
            // convertCharset('utf-8', 'utf-8') short-circuits to the same bytes.
            $value = "Caf\xc3\xa9";

            self::assertSame("Caf\xc3\xa9", $this->service->cleanIptcValue($value));
        }

        public function testCleanIptcValueConvertsWindows1252BytesToUtf8(): void
        {
            // A lone 0x92 byte (windows-1252's right single quotation mark) is
            // not valid UTF-8 on its own -- qualifyUtf8() returns -1, routing
            // through the windows-1252 (not plain iso-8859-1) fallback since
            // iconv()/mb_convert_encoding() are both available here.
            $value = "It\x92s a test";

            self::assertSame("It\u{2019}s a test", $this->service->cleanIptcValue($value));
        }

        // ------------------------------------------------------------ getExifData()

        public function testGetExifDataReadsANestedFieldToken(): void
        {
            // 'COMPUTED;Height' translates to ExifTool's own 'ImageHeight'
            // (MetadataService::COMPUTED_TAG_TRANSLATION) -- a real tag any
            // raster image has regardless of embedded EXIF, no tagging
            // needed for this one.
            $path = $this->scratchDir . '/nested-field.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $result = $this->service->getExifData($path, [
                'nested_field' => 'COMPUTED;Height',
            ], $this->exifTool);

            self::assertSame([
                'nested_field' => '6',
            ], $result);
        }

        public function testGetExifDataComputesGpsCoordinatesFromAValidComposite(): void
        {
            // allowHtmlInMetadata=true here specifically to bypass the
            // unconditional strip_tags((string) $value) pass on every scalar
            // result value (tested on its own below) -- keeps this test
            // focused on the GPS numeric extraction/wiring alone.
            CurrentConfigTestFactory::get()->allowHtmlInMetadata = true;
            $path = $this->scratchDir . '/gps-valid.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            $this->tagFileWithExifTool($path, [
                'GPS:GPSLatitude' => '41.9027',
                'GPS:GPSLatitudeRef' => 'N',
                'GPS:GPSLongitude' => '12.5',
                'GPS:GPSLongitudeRef' => 'E',
            ]);

            $result = $this->service->getExifData($path, [], $this->exifTool);

            self::assertEqualsWithDelta(41.9027, $result['latitude'], 0.001);
            self::assertEqualsWithDelta(12.5, $result['longitude'], 0.001);
        }

        public function testGetExifDataNegatesGpsCoordinatesForSouthAndWest(): void
        {
            // Sibling of the test above for the negative-sign half of the
            // real ExifTool `#` (numeric) suffix this now relies on instead
            // of the original's own hand-rolled DMS-to-decimal math
            // (parseExifGpsData(), removed along with exif_read_data()) --
            // confirmed live this comes back already correctly signed, no
            // extra Ref-based negation needed in getExifData() itself.
            CurrentConfigTestFactory::get()->allowHtmlInMetadata = true;
            $path = $this->scratchDir . '/gps-negative.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            $this->tagFileWithExifTool($path, [
                'GPS:GPSLatitude' => '33.8688',
                'GPS:GPSLatitudeRef' => 'S',
                'GPS:GPSLongitude' => '151.2093',
                'GPS:GPSLongitudeRef' => 'W',
            ]);

            $result = $this->service->getExifData($path, [], $this->exifTool);

            self::assertEqualsWithDelta(-33.8688, $result['latitude'], 0.001);
            self::assertEqualsWithDelta(-151.2093, $result['longitude'], 0.001);
        }

        public function testGetExifDataSkipsOutOfRangeGpsCoordinates(): void
        {
            CurrentConfigTestFactory::get()->allowHtmlInMetadata = true;
            $path = $this->scratchDir . '/gps-invalid.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            // 200 degrees is out of the valid [-90, 90] latitude range --
            // ExifTool's own writer doesn't validate this (confirmed live),
            // so a real file with genuinely invalid embedded GPS data is
            // constructible, reaching the `else` logging branch instead of
            // assigning latitude/longitude.
            $this->tagFileWithExifTool($path, [
                'GPS:GPSLatitude' => '200',
                'GPS:GPSLatitudeRef' => 'N',
                'GPS:GPSLongitude' => '12',
                'GPS:GPSLongitudeRef' => 'E',
            ]);

            $result = $this->service->getExifData($path, [], $this->exifTool);

            self::assertArrayNotHasKey('latitude', $result);
            self::assertArrayNotHasKey('longitude', $result);
        }

        public function testGetExifDataStripsHtmlRecursivelyFromAnArrayValuedField(): void
        {
            // XMP:Subject is a real, naturally multi-valued tag (an XMP
            // "Bag") -- ExifToolFfi's own `-a` flag returns it as an
            // array when it has multiple entries, exercising the same
            // array_walk_recursive() HTML-strip path the original's
            // fabricated 'MultiField' plugin injection did.
            $path = $this->scratchDir . '/array-field.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            $this->tagFileWithExifTool($path, [
                'XMP-dc:Subject' => '<b>one</b>',
            ]);
            $this->tagFileWithExifTool($path, [
                'XMP-dc:Subject+' => '<i>two</i>',
            ]);

            $result = $this->service->getExifData($path, [
                'multi' => 'Subject',
            ], $this->exifTool);

            self::assertSame([
                'multi' => ['one', 'two'],
            ], $result);
        }

        public function testGetExifDataStripsHtmlFromAScalarField(): void
        {
            $path = $this->scratchDir . '/scalar-field.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            $this->tagFileWithExifTool($path, [
                'Artist' => '<script>alert(1)</script>Jane',
            ]);

            $result = $this->service->getExifData($path, [
                'author' => 'Artist',
            ], $this->exifTool);

            self::assertSame([
                'author' => 'alert(1)Jane',
            ], $result);
        }

        public function testGetExifDataReturnsAnEmptyArrayForAFileWithNoMatchingTags(): void
        {
            // A non-JPEG byte stream with a real .jpg extension -- confirmed
            // live that real ExifTool doesn't error on this, it just finds
            // no tags at all (a bare {"SourceFile": "..."} row), same net
            // "nothing requested is present" result as any other file with
            // no matching tags.
            $path = $this->scratchDir . '/malformed.jpg';
            file_put_contents($path, str_repeat('not a real jpeg', 10));

            $result = $this->service->getExifData($path, [
                'author' => 'Artist',
            ], $this->exifTool);

            self::assertSame([], $result);
        }

        // -------------------------------------------------------- getSyncIptcData()

        public function testGetSyncIptcDataFormatsAValidDateField(): void
        {
            CurrentConfigTestFactory::get()->useIptcMapping = [
                'date_creation' => '2#055',
            ];
            $bytes = $this->makeJpegWithApp13Iptc([[55, '20240315']]);
            $path = $this->scratchDir . '/iptc-date-valid.jpg';
            file_put_contents($path, $bytes);

            $result = $this->service->getSyncIptcData($path, $this->exifTool);

            self::assertSame('2024-3-15', $result['date_creation']);
        }

        public function testGetSyncIptcDataFallsBackToMonthAndDayOneForAnInvalidCalendarDate(): void
        {
            CurrentConfigTestFactory::get()->useIptcMapping = [
                'date_creation' => '2#055',
            ];
            // 2023-02-30 does not exist -- checkdate() fails, and the method
            // "supposes the year is correct", resetting month/day to 1/1.
            $bytes = $this->makeJpegWithApp13Iptc([[55, '20230230']]);
            $path = $this->scratchDir . '/iptc-date-invalid.jpg';
            file_put_contents($path, $bytes);

            $result = $this->service->getSyncIptcData($path, $this->exifTool);

            self::assertSame('2023-1-1', $result['date_creation']);
        }

        public function testGetSyncIptcDataNormalizesKeywordsAndLeavesQuotesUnescaped(): void
        {
            CurrentConfigTestFactory::get()->useIptcMapping = [
                'keywords' => '2#025',
                'title' => '2#005',
            ];
            $titleValue = 'Bob\'s "Best" Shot';
            $bytes = $this->makeJpegWithApp13Iptc([
                [25, 'nature'],
                [25, 'nature'],
                [25, 'travel'],
                [5, $titleValue],
            ]);
            $path = $this->scratchDir . '/iptc-keywords.jpg';
            file_put_contents($path, $bytes);

            $result = $this->service->getSyncIptcData($path, $this->exifTool);

            self::assertSame('nature,travel', $result['keywords']);
            // SEC-10 regression guard: getSyncIptcData() used to run its
            // final pass through addslashes() before returning, which wrote
            // backslash-escaped quotes straight into the images table via
            // a parameterized (already-safe) query -- pure corruption, no
            // compensating unescape on write. The value must now round-trip
            // byte-for-byte.
            self::assertSame($titleValue, $result['title']);
        }

        // -------------------------------------------------------- getSyncExifData()

        public function testGetSyncExifDataFormatsAFullDatetimeField(): void
        {
            CurrentConfigTestFactory::get()->useExifMapping = [
                'date_creation' => 'DateTimeOriginal',
            ];
            $path = $this->scratchDir . '/exif-datetime-full.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            $this->tagFileWithExifTool($path, [
                'DateTimeOriginal' => '2024:03:15 10:20:30',
            ]);

            $result = $this->service->getSyncExifData($path, $this->exifTool);

            self::assertSame('2024-03-15 10:20:30', $result['date_creation']);
        }

        public function testGetSyncExifDataFormatsADateOnlyField(): void
        {
            // GPSDateStamp is a real, standard EXIF tag whose value is
            // genuinely date-only ("YYYY:MM:DD", no time component) --
            // unlike DateTimeOriginal (always a full datetime), so the
            // full-datetime regex genuinely fails to match here, falling
            // through to the date-only regex branch on a real
            // ExifTool-sourced value, not a fabricated one.
            CurrentConfigTestFactory::get()->useExifMapping = [
                'date_creation' => 'GPSDateStamp',
            ];
            $path = $this->scratchDir . '/exif-date-only.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            $this->tagFileWithExifTool($path, [
                'GPSDateStamp' => '2024:03:15',
            ]);

            $result = $this->service->getSyncExifData($path, $this->exifTool);

            self::assertSame('2024-03-15', $result['date_creation']);
        }

        public function testGetSyncExifDataSkipsADateFieldThatMatchesNeitherDatetimePattern(): void
        {
            CurrentConfigTestFactory::get()->useExifMapping = [
                'date_creation' => 'UserComment',
            ];
            $path = $this->scratchDir . '/exif-date-malformed.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            // Matches neither the full-datetime nor the date-only regex --
            // the else `continue` branch. UserComment (not DateTimeOriginal
            // itself) is used here since it isn't validated/reformatted by
            // ExifTool's own writer, letting an arbitrary non-date string
            // through unchanged.
            $this->tagFileWithExifTool($path, [
                'UserComment' => 'not-a-real-date',
            ]);

            $result = $this->service->getSyncExifData($path, $this->exifTool);

            self::assertArrayNotHasKey('date_creation', $result);
        }

        public function testGetSyncExifDataTreatsTheZeroDatetimeAsEmptyAndSkipsIt(): void
        {
            CurrentConfigTestFactory::get()->useExifMapping = [
                'date_creation' => 'UserComment',
            ];
            $path = $this->scratchDir . '/exif-date-zero.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            // The zero-date sentinel matches the full-datetime regex (it's
            // shaped like a real datetime), gets normalized, then hits the
            // later $isEmpty check and is skipped. UserComment again stands
            // in for a genuine date tag -- confirmed live that ExifTool's
            // own writer rejects this exact string for DateTimeOriginal
            // itself ("Month '00' out of range"), so a real date-typed tag
            // can't hold it at all; a free-text tag can, and
            // getSyncExifData()'s own regex/normalization logic doesn't
            // care which tag the string came from.
            $this->tagFileWithExifTool($path, [
                'UserComment' => '0000:00:00 00:00:00',
            ]);

            $result = $this->service->getSyncExifData($path, $this->exifTool);

            self::assertArrayNotHasKey('date_creation', $result);
        }

        public function testGetSyncExifDataNormalizesKeywords(): void
        {
            CurrentConfigTestFactory::get()->useExifMapping = [
                'keywords' => 'UserComment',
            ];
            $path = $this->scratchDir . '/exif-keywords.jpg';
            file_put_contents($path, $this->makePlainJpeg());
            $this->tagFileWithExifTool($path, [
                'UserComment' => 'nature.travel;family',
            ]);

            $result = $this->service->getSyncExifData($path, $this->exifTool);

            self::assertSame('nature,travel,family', $result['keywords']);
        }

        // ----------------------------------------------- getSyncMetadataAttributes()

        public function testGetSyncMetadataAttributesIncludesIptcFieldsWhenEnabled(): void
        {
            CurrentConfigTestFactory::get()->useIptc = true;
            CurrentConfigTestFactory::get()->useIptcMapping = [
                'title' => '2#005',
            ];

            $attributes = $this->service->getSyncMetadataAttributes();

            self::assertContains('title', $attributes);
        }

        // ------------------------------------------------------------ getSyncMetadata()

        public function testGetSyncMetadataDetectsATiffOriginalAndReadsExifFromItWhileUsingTheRepresentativeForDimensions(): void
        {
            CurrentConfigTestFactory::get()->useExifMapping = [
                'author' => 'Artist',
            ];
            $originalRelative = '_data/metadata-service-test-scratch/tiff-original.tiff';
            $originalAbsolute = dirname(__DIR__, 2) . '/' . $originalRelative;

            $tiff = new Imagick();
            $tiff->newImage(8, 6, 'white');
            $tiff->setImageFormat('tiff');
            $tiff->writeImage($originalAbsolute);
            $tiff->clear();
            // Tags only the TIFF original, never the JPEG representative
            // below -- proves which file getSyncMetadata() actually read
            // EXIF from (a real embedded tag findable only in one of the
            // two files stands in for the removed FormatExifData event's
            // own $event->filename spy).
            $this->tagFileWithExifTool($originalAbsolute, [
                'Artist' => 'Real TIFF Author',
            ]);

            $representativeDir = $this->scratchDir . '/pwg_representative';
            mkdir($representativeDir, 0o777, true);
            // Deliberately distinct dimensions (30x20) from the TIFF original's
            // own 8x6 -- proves which file getSyncMetadata() actually read for
            // width/height.
            $representativeImg = imagecreatetruecolor(30, 20);
            self::assertNotFalse($representativeImg);
            imagejpeg($representativeImg, $representativeDir . '/tiff-original.jpg');

            try {
                $result = $this->service->getSyncMetadata([
                    'path' => $originalRelative,
                    'representative_ext' => 'jpg',
                ], $this->exifTool);
            } finally {
                @unlink($representativeDir . '/tiff-original.jpg');
                @rmdir($representativeDir);
            }

            self::assertIsArray($result);
            self::assertSame(30, $result['width']);
            self::assertSame(20, $result['height']);
            // Proves EXIF really was read from the TIFF original, not the
            // (untagged) JPEG representative used for width/height above.
            self::assertSame('Real TIFF Author', $result['author']);
        }

        public function testGetSyncMetadataStripsNewlinesFromNameAndAuthor(): void
        {
            CurrentConfigTestFactory::get()->useExif = false;
            CurrentConfigTestFactory::get()->useIptc = false;
            $relativePath = '_data/metadata-service-test-scratch/newline-fields.jpg';
            file_put_contents($this->scratchDir . '/newline-fields.jpg', $this->makePlainJpeg());

            $result = $this->service->getSyncMetadata([
                'path' => $relativePath,
                'name' => "Multi\r\nLine Name",
                'author' => "Author\nWith\rBreaks",
            ], $this->exifTool);

            self::assertIsArray($result);
            self::assertSame('Multi Line Name', $result['name']);
            self::assertSame('Author With Breaks', $result['author']);
        }

        // getSyncMetadata()'s `if ($fs === false) { return false; }` guard
        // (right after a successful is_readable() check moments earlier) is
        // not exercised here -- there is no reliable, non-racy way to make
        // filesize() fail on a path that just passed is_readable() without a
        // TOCTOU race or a blocking special file (a named FIFO hangs
        // filesize() entirely rather than returning false), neither of which
        // is a safe, deterministic test.

        // ----------------------------------------------------------- parseSvgDimensions()

        public function testParseSvgDimensionsFallsBackToTheViewboxWhenWidthAndHeightAreAbsent(): void
        {
            $method = new ReflectionMethod(MetadataService::class, 'parseSvgDimensions');
            $path = $this->scratchDir . '/viewbox-only.svg';
            file_put_contents(
                $path,
                '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 150"></svg>'
            );

            $result = $method->invoke($this->service, $path);

            self::assertEquals(new SvgDimensions(200, 150), $result);
        }

        public function testParseSvgDimensionsDefaultsToZeroForAMalformedViewbox(): void
        {
            $method = new ReflectionMethod(MetadataService::class, 'parseSvgDimensions');
            $path = $this->scratchDir . '/malformed-viewbox.svg';
            file_put_contents(
                $path,
                '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="not-numbers"></svg>'
            );

            $result = $method->invoke($this->service, $path);

            self::assertEquals(new SvgDimensions(0, 0), $result);
        }

        public function testParseSvgDimensionsReturnsNullForAnUnreadableFile(): void
        {
            $method = new ReflectionMethod(MetadataService::class, 'parseSvgDimensions');

            // file_get_contents() on a missing file emits a real E_WARNING --
            // swallowed for this one call, same established pattern as
            // ImageBackendTest's fopen()/getimagesize() failure cases.
            set_error_handler(static fn (): bool => true);
            try {
                $result = $method->invoke($this->service, $this->scratchDir . '/does-not-exist.svg');
            } finally {
                restore_error_handler();
            }

            self::assertNull($result);
        }

        public function testParseSvgDimensionsReturnsNullWhenPregReplaceHitsTheBacktrackLimit(): void
        {
            $method = new ReflectionMethod(MetadataService::class, 'parseSvgDimensions');
            $path = $this->scratchDir . '/doctype.svg';
            file_put_contents(
                $path,
                '<?xml version="1.0"?><!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>'
            );

            // Forces preg_replace()'s own DOCTYPE-strip to fail with
            // PREG_BACKTRACK_LIMIT_ERROR -- a backtrack_limit of
            // 0 fails even this simple, non-catastrophic-backtracking pattern,
            // returning null exactly like a real PCRE resource-limit failure
            // would in production.
            $originalLimit = ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', '0');
            try {
                $result = $method->invoke($this->service, $path);
            } finally {
                ini_set('pcre.backtrack_limit', $originalLimit === false ? '1000000' : $originalLimit);
            }

            self::assertNull($result);
        }

        public function testParseSvgDimensionsConsumesABracketedInternalSubsetDoctypeCorrectly(): void
        {
            $method = new ReflectionMethod(MetadataService::class, 'parseSvgDimensions');
            $path = $this->scratchDir . '/bracketed-doctype.svg';
            file_put_contents(
                $path,
                '<?xml version="1.0"?><!DOCTYPE svg [<!ATTLIST svg x CDATA #IMPLIED>]>'
                . '<svg xmlns="http://www.w3.org/2000/svg" width="42" height="24"></svg>'
            );

            $result = $method->invoke($this->service, $path);

            // Before the P44-I regex fix, `[^>]*` stopped at the `>` inside
            // the brackets, leaving a mangled `]>` remnant that failed to
            // parse -- silently returning null instead of the real
            // dimensions below.
            self::assertEquals(new SvgDimensions(42, 24), $result);
        }

        // parseSvgDimensions()'s `$attributes === null` guard is not exercised
        // here -- SimpleXMLElement::attributes() only returns null for a
        // handful of internal-error conditions that don't occur once
        // simplexml_load_string() has already succeeded (even
        // a real root element with zero attributes returns an empty
        // SimpleXMLElement, never null). Same "verified unreachable through
        // realistic input" shape as this file's other documented guards.

        // ------------------------------------------------------------- syncMetadata()

        // syncMetadata()'s own `! is_int($id) && ! is_string($id)` `continue`
        // guard (right after `$id = $data['id'] ?? null;`) is not chased here:
        // $data comes from getSyncMetadata($row->toArray()), which returns its
        // $infos argument verbatim (mutated in place, never rekeyed) -- and
        // every real row reaching it is a MetadataImage::toArray(), whose own
        // `id` is declared `public int $id` (fromRow() defaults even a
        // malformed row to int 0, never null/non-scalar). $data['id'] is
        // therefore always a genuine PHP int, never anything is_int()/
        // is_string() would reject.

        public function testSyncMetadataAssignsTagsFromAKeywordsCsvField(): void
        {
            CurrentConfigTestFactory::get()->useExif = false;
            CurrentConfigTestFactory::get()->useIptc = true;
            CurrentConfigTestFactory::get()->useIptcMapping = [
                'keywords' => '2#025',
            ];

            $relativePath = '_data/metadata-service-test-scratch/sync-tags.jpg';
            $bytes = $this->makeJpegWithApp13Iptc([[25, 'sync-nature'], [25, 'sync-travel']]);
            file_put_contents($this->scratchDir . '/sync-tags.jpg', $bytes);

            $this->conn->executeStatement(
                'INSERT INTO images (path) VALUES (?)',
                [$relativePath]
            );
            $imageId = (int) $this->conn->lastInsertId();

            try {
                // syncMetadata() opens its own internal ExifToolFfi for
                // the whole batch -- unlike getSyncMetadata()/getExifData()/
                // getIptcData(), its own public signature is unchanged.
                $this->service->syncMetadata([$imageId], $this->permissionService(), EntityManagerFactory::build($this->conn));

                $tagNames = $this->conn->fetchFirstColumn(
                    'SELECT t.name FROM tags' . ' t
                 INNER JOIN image_tag' . ' it ON it.tag_id = t.id
                 WHERE it.image_id = ?
                 ORDER BY t.name',
                    [$imageId]
                );
                self::assertSame(['sync-nature', 'sync-travel'], $tagNames);

                $updatedDate = $this->conn->fetchOne(
                    'SELECT date_metadata_update FROM images WHERE id = ?',
                    [$imageId]
                );
                self::assertNotNull($updatedDate);
            } finally {
                $this->conn->executeStatement('DELETE FROM image_tag WHERE image_id = ?', [$imageId]);
                $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
                $this->conn->executeStatement("DELETE FROM tags WHERE name IN ('sync-nature', 'sync-travel')");
            }
        }

        public function testSyncMetadataSkipsARowWhoseFileIsUnreadable(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO images (path) VALUES ('no/such/file-for-sync.jpg')"
            );
            $imageId = (int) $this->conn->lastInsertId();

            try {
                // Must not throw/fatal -- getSyncMetadata() returns false for
                // this row (is_readable() fails), hitting the `continue` guard;
                // the row is never added to $datas/$tagsOf or written back.
                $this->service->syncMetadata([$imageId], $this->permissionService(), EntityManagerFactory::build($this->conn));

                $updatedDate = $this->conn->fetchOne(
                    'SELECT date_metadata_update FROM images WHERE id = ?',
                    [$imageId]
                );
                self::assertNull($updatedDate);
            } finally {
                $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
            }
        }

        // syncMetadata()'s `! is_int($id) && ! is_string($id)` guard is not
        // exercised here -- $data['id'] always comes from
        // Projection\MetadataImage::toArray()['id'], typed `int` unconditionally
        // (see that class), and syncMetadata() only ever builds $data from rows
        // MetadataRepository::findImagesByIds() itself produced. There is no
        // path through this method's public contract that hands it a
        // non-int/string id, same "verified unreachable through the real API"
        // shape as UserRepositoryTest's findAdminIds() note.
    }
}
