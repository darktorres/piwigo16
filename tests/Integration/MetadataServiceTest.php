<?php

declare(strict_types=1);

// getSyncMetadata() reads Piwigo\Core\CurrentPaths directly (same
// convention as every real bootstrap entry point) -- IntegrationTestCase's
// own setUp() already seeds it against this repo's real root, matching
// this file's own '_data/...'-relative fixture paths below.
//
// trigger_change() calls go directly through the service's own
// constructor-injected $this->eventDispatcher now, a pure passthrough
// with no handlers registered, so no local stub is needed.

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
    use Piwigo\Metadata\Event\FormatExifData;
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

    final class MetadataServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private MetadataService $service;

        private Connection $conn;

        private string $scratchDir;

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
         * A real, minimal JPEG (via GD) with no special markers -- used by every
         * getExifData() test below that injects its own synthetic $exif shape
         * via the 'format_exif_data' plugin filter (always invoked for a real,
         * truthy exif_read_data() result -- see that method's own inline
         * comment) rather than hand-rolling binary EXIF tags for every field
         * combination.
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

        public function testCleanIptcValueStripsLeadingNullBytes(): void
        {
            $value = chr(0) . chr(0) . 'Hello';

            self::assertSame('Hello', $this->service->cleanIptcValue($value));
        }

        public function testCleanIptcValueReplacesEmbeddedNullBytes(): void
        {
            self::assertSame('a b', $this->service->cleanIptcValue('a' . chr(0x00) . 'b'));
        }

        public function testParseExifGpsDataConvertsDegreesMinutesSeconds(): void
        {
            // 41 deg, 54 min, 9.686 sec (~41.9027 decimal degrees), matching
            // the format documented on the original function.
            $latitude = $this->service->parseExifGpsData(['41/1', '54/1', '9686/1000'], 'N');

            self::assertEqualsWithDelta(41.9027, $latitude, 0.001);
        }

        public function testParseExifGpsDataNegatesForSouthAndWest(): void
        {
            $latitude = $this->service->parseExifGpsData(['41/1', '54/1', '0/1'], 'S');

            self::assertLessThan(0, $latitude);
        }

        public function testParseExifGpsDataHandlesAZeroDenominator(): void
        {
            // must not emit a division-by-zero warning/error.
            $result = $this->service->parseExifGpsData(['1/0', '0/1', '0/1'], 'N');

            self::assertSame(0.0, $result);
        }

        public function testMetadataNormalizeKeywordsStringConvertsSeparatorsToCommas(): void
        {
            $result = $this->service->metadataNormalizeKeywordsString('nature.travel;family');

            self::assertSame('nature,travel,family', $result);
        }

        public function testMetadataNormalizeKeywordsStringDeduplicatesAndTrims(): void
        {
            $result = $this->service->metadataNormalizeKeywordsString(',,nature,nature,travel,,');

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
            ]));
        }

        public function testGetSyncMetadataReadsFilesizeFromARealFile(): void
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
            assert(is_string($jpegBytes));
            $relativePath = '_data/metadata-service-test-scratch/sample.jpg';
            file_put_contents(dirname(__DIR__, 2) . '/' . $relativePath, str_pad($jpegBytes, 2048, "\0"));

            $result = $this->service->getSyncMetadata([
                'path' => $relativePath,
            ]);

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
         * inspection.
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
            ]);

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
            ]);

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

        public function testGetIptcDataReturnsEmptyWhenGetimagesizeFails(): void
        {
            $result = $this->service->getIptcData($this->scratchDir . '/no-such-file.jpg', [
                'title' => '2#005',
            ]);

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
            ], '|');

            self::assertSame([
                'title' => 'Sunset Over The Bay',
                'author' => 'Jane Photographer',
                'keywords' => 'nature|travel',
            ], $result);
        }

        public function testGetIptcDataSkipsARequestedMapFieldThatThePhotoHasNoIptcRecordFor(): void
        {
            // $map requests 'caption' (2#120), but the embedded IPTC data
            // below only has a title (2#005) record -- exercises the
            // "! isset($iptc[$iptcKey][0])" `continue` for the caption key,
            // as opposed to the sibling "parses real fields" test above,
            // whose $map matches the embedded records 1:1.
            $bytes = $this->makeJpegWithApp13Iptc([[5, 'Sunset Over The Bay']]);
            $path = $this->scratchDir . '/iptc-missing-field.jpg';
            file_put_contents($path, $bytes);

            $result = $this->service->getIptcData($path, [
                'title' => '2#005',
                'caption' => '2#120',
            ]);

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
            ]);

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
            ]);

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
            // 'COMPUTED;Height' is a real nested key exif_read_data() always
            // populates, even with zero embedded EXIF tags -- no synthetic
            // override needed for this one. allowHtmlInMetadata stays disabled
            // (setUp's default), so the scalar HTML-strip pass also coerces
            // this int value to a string, matching real getExifData() behavior.
            $path = $this->scratchDir . '/nested-field.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $result = $this->service->getExifData($path, [
                'nested_field' => 'COMPUTED;Height',
            ]);

            self::assertSame([
                'nested_field' => '6',
            ], $result);
        }

        public function testGetExifDataComputesGpsCoordinatesFromAValidComposite(): void
        {
            // allowHtmlInMetadata=true here specifically to bypass the
            // unconditional strip_tags((string) $value) pass on every scalar
            // result value (tested on its own below) -- keeps this test
            // focused on the GPS composite math/wiring alone.
            CurrentConfigTestFactory::get()->allowHtmlInMetadata = true;
            $path = $this->scratchDir . '/gps-valid.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $handler = static function (FormatExifData $event): void {
                $exif = $event->exif ?? [];
                $exif['GPSLatitudeRef'] = 'N';
                $exif['GPSLatitude'] = ['41/1', '54/1', '9686/1000'];
                $exif['GPSLongitudeRef'] = 'E';
                $exif['GPSLongitude'] = ['12/1', '30/1', '0/1'];

                $event->exif = $exif;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getExifData($path, []);

                self::assertEqualsWithDelta(41.9027, $result['latitude'], 0.001);
                self::assertEqualsWithDelta(12.5, $result['longitude'], 0.001);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
            }
        }

        public function testGetExifDataSkipsOutOfRangeGpsCoordinates(): void
        {
            CurrentConfigTestFactory::get()->allowHtmlInMetadata = true;
            $path = $this->scratchDir . '/gps-invalid.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $handler = static function (FormatExifData $event): void {
                $exif = $event->exif ?? [];
                // 200 degrees is out of the valid [-90, 90] latitude range --
                // reaches the `else` logging branch instead of assigning
                // latitude/longitude.
                $exif['GPSLatitudeRef'] = 'N';
                $exif['GPSLatitude'] = ['200/1', '0/1', '0/1'];
                $exif['GPSLongitudeRef'] = 'E';
                $exif['GPSLongitude'] = ['12/1', '0/1', '0/1'];

                $event->exif = $exif;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getExifData($path, []);

                self::assertArrayNotHasKey('latitude', $result);
                self::assertArrayNotHasKey('longitude', $result);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
            }
        }

        public function testGetExifDataStripsHtmlRecursivelyFromAnArrayValuedField(): void
        {
            $path = $this->scratchDir . '/array-field.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $handler = static function (FormatExifData $event): void {
                $exif = $event->exif ?? [];
                $exif['MultiField'] = ['<b>one</b>', '<i>two</i>'];

                $event->exif = $exif;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getExifData($path, [
                    'multi' => 'MultiField',
                ]);

                self::assertSame([
                    'multi' => ['one', 'two'],
                ], $result);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
            }
        }

        public function testGetExifDataStripsHtmlFromAScalarField(): void
        {
            $path = $this->scratchDir . '/scalar-field.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $handler = static function (FormatExifData $event): void {
                $exif = $event->exif ?? [];
                $exif['Artist'] = '<script>alert(1)</script>Jane';

                $event->exif = $exif;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getExifData($path, [
                    'author' => 'Artist',
                ]);

                self::assertSame([
                    'author' => 'alert(1)Jane',
                ], $result);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
            }
        }

        public function testGetExifDataReturnsAnEmptyArrayWhenExifReadDataFailsAndNoHandlerSuppliesAFallback(): void
        {
            // A non-JPEG byte stream with a real .jpg extension -- exif_read_data()
            // genuinely fails here (a real "File not supported" E_WARNING, not
            // merely an empty array), which is the only way to reach the
            // `$exif2 = dispatch(...)` branch (only computed when
            // exif_read_data() itself was falsy), as opposed to the
            // always-invoked dispatch() call on the truthy-$exif path every
            // other test above exercises. With no handler registered,
            // $exif2 stays null and $result never leaves its initial [].
            $path = $this->scratchDir . '/malformed.jpg';
            file_put_contents($path, str_repeat('not a real jpeg', 10));

            set_error_handler(static fn (): bool => true);
            try {
                $result = $this->service->getExifData($path, [
                    'author' => 'Artist',
                ]);
            } finally {
                restore_error_handler();
            }

            self::assertSame([], $result);
        }

        // getExifData()'s `if (! function_exists('exif_read_data'))` RuntimeException
        // (its very first guard) is not exercised anywhere in this file --
        // ext-exif is a real, always-loaded extension in this environment (see
        // composer.json's own "ext-exif" requirement), and function_exists()
        // can't be forced to return false for a real built-in extension
        // function from within a test process. Same "verified untestable
        // without breaking a real runtime guarantee" shape as the
        // HttpClientService-gated skip list.

        // -------------------------------------------------------- getSyncIptcData()

        public function testGetSyncIptcDataFormatsAValidDateField(): void
        {
            CurrentConfigTestFactory::get()->useIptcMapping = [
                'date_creation' => '2#055',
            ];
            $bytes = $this->makeJpegWithApp13Iptc([[55, '20240315']]);
            $path = $this->scratchDir . '/iptc-date-valid.jpg';
            file_put_contents($path, $bytes);

            $result = $this->service->getSyncIptcData($path);

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

            $result = $this->service->getSyncIptcData($path);

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

            $result = $this->service->getSyncIptcData($path);

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

            $handler = static function (FormatExifData $event): void {
                $exif = $event->exif ?? [];
                $exif['DateTimeOriginal'] = '2024:03:15 10:20:30';

                $event->exif = $exif;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getSyncExifData($path);

                self::assertSame('2024-03-15 10:20:30', $result['date_creation']);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
            }
        }

        public function testGetSyncExifDataFormatsADateOnlyField(): void
        {
            CurrentConfigTestFactory::get()->useExifMapping = [
                'date_creation' => 'DateTimeOriginal',
            ];
            $path = $this->scratchDir . '/exif-date-only.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $handler = static function (FormatExifData $event): void {
                $exif = $event->exif ?? [];
                // No time portion -- the full-datetime regex fails to match,
                // falling through to the date-only regex branch.
                $exif['DateTimeOriginal'] = '2024:03:15';

                $event->exif = $exif;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getSyncExifData($path);

                self::assertSame('2024-03-15', $result['date_creation']);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
            }
        }

        public function testGetSyncExifDataSkipsADateFieldThatMatchesNeitherDatetimePattern(): void
        {
            CurrentConfigTestFactory::get()->useExifMapping = [
                'date_creation' => 'DateTimeOriginal',
            ];
            $path = $this->scratchDir . '/exif-date-malformed.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $handler = static function (FormatExifData $event): void {
                $exif = $event->exif ?? [];
                // Matches neither the full-datetime nor the date-only regex --
                // the else `continue` branch, distinct from the "0000-00-00"
                // sibling test below (that one DOES match the full-datetime
                // regex, then gets nulled and filtered by the later
                // `$isEmpty` check instead).
                $exif['DateTimeOriginal'] = 'not-a-real-date';

                $event->exif = $exif;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getSyncExifData($path);

                self::assertArrayNotHasKey('date_creation', $result);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
            }
        }

        public function testGetSyncExifDataTreatsTheZeroDatetimeAsEmptyAndSkipsIt(): void
        {
            CurrentConfigTestFactory::get()->useExifMapping = [
                'date_creation' => 'DateTimeOriginal',
            ];
            $path = $this->scratchDir . '/exif-date-zero.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $handler = static function (FormatExifData $event): void {
                $exif = $event->exif ?? [];
                $exif['DateTimeOriginal'] = '0000:00:00 00:00:00';

                $event->exif = $exif;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getSyncExifData($path);

                self::assertArrayNotHasKey('date_creation', $result);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
            }
        }

        public function testGetSyncExifDataNormalizesKeywords(): void
        {
            CurrentConfigTestFactory::get()->useExifMapping = [
                'keywords' => 'UserComment',
            ];
            $path = $this->scratchDir . '/exif-keywords.jpg';
            file_put_contents($path, $this->makePlainJpeg());

            $handler = static function (FormatExifData $event): void {
                $exif = $event->exif ?? [];
                $exif['UserComment'] = 'nature.travel;family';

                $event->exif = $exif;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getSyncExifData($path);

                self::assertSame('nature,travel,family', $result['keywords']);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
            }
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

            $representativeDir = $this->scratchDir . '/pwg_representative';
            mkdir($representativeDir, 0o777, true);
            // Deliberately distinct dimensions (30x20) from the TIFF original's
            // own 8x6 -- proves which file getSyncMetadata() actually read for
            // width/height.
            $representativeImg = imagecreatetruecolor(30, 20);
            self::assertNotFalse($representativeImg);
            imagejpeg($representativeImg, $representativeDir . '/tiff-original.jpg');

            $exifReadFilename = null;
            $handler = static function (FormatExifData $event) use (&$exifReadFilename): void {
                $exifReadFilename = $event->filename;
            };
            EventDispatcherTestFactory::get()->addTypedHandler(FormatExifData::class, $handler);

            try {
                $result = $this->service->getSyncMetadata([
                    'path' => $originalRelative,
                    'representative_ext' => 'jpg',
                ]);
            } finally {
                EventDispatcherTestFactory::get()->removeTypedHandler(FormatExifData::class, $handler);
                @unlink($representativeDir . '/tiff-original.jpg');
                @rmdir($representativeDir);
            }

            self::assertIsArray($result);
            self::assertSame(30, $result['width']);
            self::assertSame(20, $result['height']);
            // Proves the isTiff branch really did reset $file back to the
            // original TIFF for EXIF reading, despite the representative
            // being used for width/height just above.
            self::assertSame($originalAbsolute, $exifReadFilename);
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
            ]);

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
