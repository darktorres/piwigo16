<?php

declare(strict_types=1);

// parse_section_url()'s own stub was removed (P23 batch 8c) -- always
// available now via composer autoload.files (src/Piwigo/Url/functions.php),
// same real Piwigo\Url\UrlService::parseSectionUrl() delegate this stub
// used to hand-duplicate.

namespace Piwigo\Tests\Integration {

use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Html\HtmlService;
use Piwigo\Section\SectionInitializer;

/**
 * Forces the $_SERVER['PATH_INFO'] branch (question_mark_in_urls=false)
 * throughout -- the alternative $_GET-key branch calls
 * \Piwigo\Db\MysqliDb::realEscapeString(), which needs a legacy `global $mysqli`
 * connection this isolated test process never bootstraps. Never exercises
 * the "invalid/missing picture identifier" branches (bad_request() is
 * exit-triggering), same "don't stub/exercise what would kill the test"
 * reasoning used throughout this suite.
 */
final class SectionInitializerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $GLOBALS['conf'] = is_array($GLOBALS['conf'] ?? null) ? $GLOBALS['conf'] : [];
        $GLOBALS['conf']['question_mark_in_urls'] = false;

        unset($_SERVER['SCRIPT_NAME'], $_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset($_SERVER['PATH_INFO'], $_SERVER['SCRIPT_NAME']);
        parent::tearDown();
    }

    public function test_parse_computes_root_path_from_path_info_depth(): void
    {
        $_SERVER['PATH_INFO'] = '/category/1';

        $context = new SectionInitializer(new HtmlService())
            ->parse();

        self::assertSame('../../', $context->rootPath);
        self::assertSame('/category/1', $context->sectionUrl);
        self::assertSame(['category', '1'], $context->tokens);
    }

    public function test_parse_computes_a_deeper_root_path_for_a_deeper_url(): void
    {
        $_SERVER['PATH_INFO'] = '/category/1/start-20';

        $context = new SectionInitializer(new HtmlService())
            ->parse();

        self::assertSame('../../../', $context->rootPath);
    }

    public function test_parse_never_sets_image_id_for_a_non_picture_request(): void
    {
        $_SERVER['PATH_INFO'] = '/category/1';

        $context = new SectionInitializer(new HtmlService())
            ->parse();

        self::assertNull($context->imageId);
        self::assertNull($context->imageFile);
    }

    public function test_parse_extracts_a_purely_numeric_picture_id(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        $_SERVER['PATH_INFO'] = '/42';

        $context = new SectionInitializer(new HtmlService())
            ->parse();

        self::assertSame('42', $context->imageId);
        self::assertNull($context->imageFile);
        self::assertSame(1, $context->nextToken);
    }

    public function test_parse_extracts_a_picture_id_and_file_slug(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        $_SERVER['PATH_INFO'] = '/42-my-photo';

        $context = new SectionInitializer(new HtmlService())
            ->parse();

        self::assertSame('42', $context->imageId);
        self::assertSame('my-photo', $context->imageFile);
    }

    public function test_parse_delegates_section_parsing_to_url_service(): void
    {
        // 'most_visited' is a plain recognized token (no further DB-backed
        // resolution needed, unlike e.g. 'tags' -> find_tags()) -- confirms
        // delegation to the real UrlService::parseSectionUrl() genuinely
        // happened rather than this being a hardcoded default.
        $_SERVER['PATH_INFO'] = '/most_visited';

        $context = new SectionInitializer(new HtmlService())
            ->parse();

        self::assertSame('most_visited', $context->parsed['section'] ?? null);
    }
}
}
