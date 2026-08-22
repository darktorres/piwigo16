<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Image\SrcImage;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\SessionServiceTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * The Unit sibling (tests/Unit/Picture/PictureMetadataRendererTest.php)
 * only exercises the show_exif=false/show_iptc=false guard -- every other
 * branch needs a real image file on disk (getExifData()/getIptcData() are
 * filesystem-bound, not DB-bound), hence Integration level here. Reuses
 * the same hand-rolled JPEG APP1(EXIF)/APP13(IPTC) marker-segment
 * construction technique as MetadataServiceTest.php in this same
 * directory (see that file's own docblocks for why: neither ImageMagick's
 * `-set`/`-define` nor a synthetic `xc:` canvas actually persists either
 * profile).
 */
final class PictureMetadataRendererTest extends IntegrationTestCase
{
    private string $scratchDir;

    private PictureMetadataRenderer $renderer;

    private CurrentLogger $currentLogger;

    private EntityManagerInterface $entityManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), $currentConfig));
        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build());

        $this->scratchDir = dirname(__DIR__, 2) . '/_data/picture-metadata-renderer-test-scratch';
        @mkdir($this->scratchDir, 0o777, true);

        $this->renderer = new PictureMetadataRenderer();
        $this->currentLogger = new CurrentLogger();
        $this->currentLogger->set(new Logger([
            'severity' => Logger::OFF,
        ]));
        $entityManager = Kernel::container()->get(EntityManagerInterface::class);
        if (! $entityManager instanceof EntityManagerInterface) {
            throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
        }
        $this->entityManager = $entityManager;
    }

    #[Override]
    protected function tearDown(): void
    {
        $files = glob($this->scratchDir . '/*');
        foreach ($files !== false ? $files : [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratchDir);

        CurrentTemplateTestFactory::get()->reset();
        LangTestFactory::get()->restore(null);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $tags  tag name => ASCII value, e.g. ['Artist' => 'Jane']
     */
    private function buildApp1ExifSegment(array $tags): string
    {
        $tagIds = [
            'ImageDescription' => 0x010E,
            'Artist' => 0x013B,
        ];
        $numEntries = count($tags);
        $ifdStart = 8;
        $ifdSize = 2 + $numEntries * 12 + 4;
        $dataAreaStart = $ifdStart + $ifdSize;

        $entries = '';
        $dataArea = '';
        $currentDataOffset = $dataAreaStart;
        foreach ($tags as $name => $value) {
            $tagId = $tagIds[$name];
            $bytes = $value . "\x00";
            $count = strlen($bytes);
            if ($count <= 4) {
                $entries .= pack('v', $tagId) . pack('v', 2) . pack('V', $count) . str_pad($bytes, 4, "\x00");
            } else {
                $entries .= pack('v', $tagId) . pack('v', 2) . pack('V', $count) . pack('V', $currentDataOffset);
                $dataArea .= $bytes;
                $currentDataOffset += $count;
            }
        }

        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', $numEntries)
            . $entries
            . pack('V', 0)
            . $dataArea;

        $exifHeader = "Exif\x00\x00" . $tiff;

        return "\xFF\xE1" . pack('n', strlen($exifHeader) + 2) . $exifHeader;
    }

    /**
     * @param  list<array{0: int<0, 255>, 1: string}>  $records  [datasetNumber, value] pairs, record 2 (Application Record)
     */
    private function buildApp13IptcSegment(array $records): string
    {
        $iptcData = '';
        foreach ($records as [$dataset, $value]) {
            $iptcData .= "\x1c" . chr(2) . chr($dataset) . pack('n', strlen($value)) . $value;
        }
        $blockData = $iptcData;
        if (strlen($blockData) % 2 !== 0) {
            $blockData .= "\x00";
        }
        $block = '8BIM' . pack('n', 0x0404) . chr(0) . "\x00" . pack('N', strlen($iptcData)) . $blockData;
        $psHeader = "Photoshop 3.0\x00" . $block;

        return "\xFF\xED" . pack('n', strlen($psHeader) + 2) . $psHeader;
    }

    /**
     * Splices 1+ raw marker segments (built by buildApp1ExifSegment()/
     * buildApp13IptcSegment() above) right after a real, minimal GD JPEG's
     * own SOI marker.
     */
    private function makeJpegWithSegments(string ...$segments): string
    {
        $img = imagecreatetruecolor(6, 6);
        self::assertNotFalse($img);
        ob_start();
        imagejpeg($img);
        $base = ob_get_clean();
        self::assertNotFalse($base);

        return substr($base, 0, 2) . implode('', $segments) . substr($base, 2);
    }

    /**
     * @return array<string, array{src_image: SrcImage}>
     */
    private function makePicture(string $relativePath): array
    {
        return [
            'current' => [
                'src_image' => new SrcImage(new SrcImageInfo(
                    id: 1,
                    path: $relativePath,
                    width: 6,
                    height: 6,
                )),
            ],
        ];
    }

    public function testRenderAppendsExifMetadataWithDirectAndNestedFieldTokens(): void
    {
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->showExif = true;
        $currentConfig->showIptc = false;
        // 'COMPUTED;Height' is a real nested key exif_read_data() always
        // populates, even with zero embedded tags -- exercises the ';'
        // token branch alongside the 2 direct fields.
        $currentConfig->showExifFields = ['Artist', 'ImageDescription', 'COMPUTED;Height'];
        // Only 'Artist' gets a translation -- exercises both the
        // Lang::has() true and false sub-branches in the same test.
        // Lang::loadArray() (not restore()): Lang::t() reads through the
        // separate Translator singleton, which only loadArray() seeds --
        // confirmed live, restore() alone (which only updates Lang::has()'s
        // own table) leaves Lang::t() returning the untranslated key.
        LangTestFactory::get()->loadArray([
            'exif_field_Artist' => 'Translated Artist',
        ]);

        $relativePath = '_data/picture-metadata-renderer-test-scratch/exif-fields.jpg';
        file_put_contents(
            dirname(__DIR__, 2) . '/' . $relativePath,
            $this->makeJpegWithSegments($this->buildApp1ExifSegment([
                'Artist' => 'Jane Photographer',
                'ImageDescription' => 'A test photo',
            ]))
        );

        $metadata = $this->renderer->render(LangTestFactory::get(), $this->makePicture($relativePath), $this->currentLogger, new EventDispatcher(), $currentConfig, CurrentUserTestFactory::get(), SessionServiceTestFactory::get(), Paths::fromRoot(dirname(__DIR__, 2)), $this->entityManager);

        self::assertIsArray($metadata);
        self::assertCount(1, $metadata);
        self::assertSame('EXIF Metadata', $metadata[0]->title);
        self::assertSame([
            'Translated Artist' => 'Jane Photographer',
            'ImageDescription' => 'A test photo',
            // allowHtmlInMetadata defaults to false -- getExifData()'s own
            // HTML-strip pass runs strip_tags((string) $value) on every
            // scalar result, coercing this int (from exif_read_data()'s
            // own real COMPUTED.Height key) into a string.
            'Height' => '6',
        ], $metadata[0]->lines);
    }

    public function testRenderTranslatesACompositeExifFieldWhenATranslationExistsForItsSecondToken(): void
    {
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->showExif = true;
        $currentConfig->showIptc = false;
        // 'COMPUTED;Height' is a real nested key exif_read_data() always
        // populates, even with zero embedded EXIF tags (see the sibling
        // test above). That test deliberately leaves 'exif_field_Height'
        // untranslated (only 'exif_field_Artist', a *direct* field, gets
        // one) to exercise the ';'-token arm's own Lang::has()-false
        // sub-branch -- this one loads a translation for the composite
        // field's own second token instead, reaching that same arm's
        // Lang::has()-true sub-branch (distinct from the direct-field
        // Lang::has()/Lang::t() pair a few lines above it in the source).
        $currentConfig->showExifFields = ['COMPUTED;Height'];
        LangTestFactory::get()->loadArray([
            'exif_field_Height' => 'Hauteur',
        ]);

        $relativePath = '_data/picture-metadata-renderer-test-scratch/exif-composite-translated.jpg';
        file_put_contents(
            dirname(__DIR__, 2) . '/' . $relativePath,
            $this->makeJpegWithSegments($this->buildApp1ExifSegment([]))
        );

        $metadata = $this->renderer->render(LangTestFactory::get(), $this->makePicture($relativePath), $this->currentLogger, new EventDispatcher(), $currentConfig, CurrentUserTestFactory::get(), SessionServiceTestFactory::get(), Paths::fromRoot(dirname(__DIR__, 2)), $this->entityManager);

        self::assertIsArray($metadata);
        self::assertCount(1, $metadata);
        self::assertSame('EXIF Metadata', $metadata[0]->title);
        self::assertSame([
            'Hauteur' => '6',
        ], $metadata[0]->lines);
    }

    public function testRenderAppendsNothingForExifWhenNoConfiguredFieldMatches(): void
    {
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->showExif = true;
        $currentConfig->showIptc = false;
        $currentConfig->showExifFields = ['ThisFieldDoesNotExistAnywhere'];

        $relativePath = '_data/picture-metadata-renderer-test-scratch/exif-empty.jpg';
        file_put_contents(dirname(__DIR__, 2) . '/' . $relativePath, $this->makeJpegWithSegments($this->buildApp1ExifSegment([
            'Artist' => 'Jane',
        ])));

        $metadata = $this->renderer->render(LangTestFactory::get(), $this->makePicture($relativePath), $this->currentLogger, new EventDispatcher(), $currentConfig, CurrentUserTestFactory::get(), SessionServiceTestFactory::get(), Paths::fromRoot(dirname(__DIR__, 2)), $this->entityManager);

        self::assertNull($metadata);
    }

    public function testRenderAppendsIptcMetadataTranslatingKnownFields(): void
    {
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->showExif = false;
        $currentConfig->showIptc = true;
        $currentConfig->showIptcMapping = [
            'title' => '2#005',
            'author' => '2#080',
        ];
        // getIptcData()'s own result is keyed by the *pwg* side of the
        // mapping ('title'/'author'), not the raw IPTC code -- confirmed
        // live, the renderer's own Lang::has($field)/Lang::t($field) calls
        // check against that pwg key, not the code. 'author' gets a
        // translation, 'title' does not -- both sub-branches of the
        // `if (Lang::has($field))` check in the same test. Lang::loadArray()
        // (not restore()): Lang::t() reads through the separate Translator
        // singleton, which only loadArray() seeds -- confirmed live,
        // restore() alone (which only updates Lang::has()'s own table)
        // leaves Lang::t() returning the untranslated key.
        LangTestFactory::get()->loadArray([
            'author' => 'By-line',
        ]);

        $relativePath = '_data/picture-metadata-renderer-test-scratch/iptc-fields.jpg';
        file_put_contents(
            dirname(__DIR__, 2) . '/' . $relativePath,
            $this->makeJpegWithSegments($this->buildApp13IptcSegment([[5, 'Sunset Over The Bay'], [80, 'Jane Photographer']]))
        );

        $metadata = $this->renderer->render(LangTestFactory::get(), $this->makePicture($relativePath), $this->currentLogger, new EventDispatcher(), $currentConfig, CurrentUserTestFactory::get(), SessionServiceTestFactory::get(), Paths::fromRoot(dirname(__DIR__, 2)), $this->entityManager);

        self::assertIsArray($metadata);
        self::assertCount(1, $metadata);
        self::assertSame('IPTC Metadata', $metadata[0]->title);
        self::assertSame([
            'title' => 'Sunset Over The Bay',
            'By-line' => 'Jane Photographer',
        ], $metadata[0]->lines);
    }

    public function testRenderAppendsBothExifAndIptcMetadataAs2SeparateEntries(): void
    {
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->showExif = true;
        $currentConfig->showIptc = true;
        $currentConfig->showExifFields = ['Artist'];
        $currentConfig->showIptcMapping = [
            'title' => '2#005',
        ];

        $relativePath = '_data/picture-metadata-renderer-test-scratch/both-fields.jpg';
        // Both marker segments spliced onto the same real file -- proves
        // both branches genuinely operate on the same source image.
        $combined = $this->makeJpegWithSegments(
            $this->buildApp1ExifSegment([
                'Artist' => 'Jane Photographer',
            ]),
            $this->buildApp13IptcSegment([[5, 'Sunset']])
        );
        file_put_contents(dirname(__DIR__, 2) . '/' . $relativePath, $combined);

        $metadata = $this->renderer->render(LangTestFactory::get(), $this->makePicture($relativePath), $this->currentLogger, new EventDispatcher(), $currentConfig, CurrentUserTestFactory::get(), SessionServiceTestFactory::get(), Paths::fromRoot(dirname(__DIR__, 2)), $this->entityManager);

        self::assertIsArray($metadata);
        self::assertCount(2, $metadata);
        self::assertSame('EXIF Metadata', $metadata[0]->title);
        self::assertSame('IPTC Metadata', $metadata[1]->title);
    }
}
