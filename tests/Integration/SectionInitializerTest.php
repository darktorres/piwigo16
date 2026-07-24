<?php

declare(strict_types=1);

// parse_section_url() (the former free-function bridge) was retired
// (Legacy Coupling Retirement Phase 4c) -- SectionInitializer now calls
// Piwigo\Url\UrlService::parseSectionUrl() directly via a constructor-
// injected UrlServiceInterface, same as every other real caller.

namespace Piwigo\Tests\Integration {

use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Section\SectionInitializer;
use Piwigo\Section\SectionRepository;
use Piwigo\Url\UrlService;

/**
 * Forces the $_SERVER['PATH_INFO'] branch (question_mark_in_urls=false)
 * throughout -- the alternative $_GET-key branch's escaping now goes
 * through SectionRepository (a real DBAL Connection, built here same as
 * every other repository-backed Integration test), so it no longer needs
 * the legacy `global $mysqli` avoidance this test used to require. Still
 * never exercises the "invalid/missing picture identifier" branches
 * (bad_request() is exit-triggering), same "don't stub/exercise what would
 * kill the test" reasoning used throughout this suite.
 */
final class SectionInitializerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SectionRepository $repo;

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

        CurrentConfig::setQuestionMarkInUrls(false);

        unset($_SERVER['SCRIPT_NAME'], $_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);

        $this->repo = new SectionRepository(DbConnection::build());
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

        $context = new SectionInitializer(new HtmlService(), $this->repo, new RedirectService(), new UrlService(new HtmlService()))
            ->parse();

        self::assertSame('../../', $context->rootPath);
        self::assertSame('/category/1', $context->sectionUrl);
        self::assertSame(['category', '1'], $context->tokens);
    }

    public function test_parse_computes_a_deeper_root_path_for_a_deeper_url(): void
    {
        $_SERVER['PATH_INFO'] = '/category/1/start-20';

        $context = new SectionInitializer(new HtmlService(), $this->repo, new RedirectService(), new UrlService(new HtmlService()))
            ->parse();

        self::assertSame('../../../', $context->rootPath);
    }

    public function test_parse_never_sets_image_id_for_a_non_picture_request(): void
    {
        $_SERVER['PATH_INFO'] = '/category/1';

        $context = new SectionInitializer(new HtmlService(), $this->repo, new RedirectService(), new UrlService(new HtmlService()))
            ->parse();

        self::assertNull($context->imageId);
        self::assertNull($context->imageFile);
    }

    public function test_parse_extracts_a_purely_numeric_picture_id(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        $_SERVER['PATH_INFO'] = '/42';

        $context = new SectionInitializer(new HtmlService(), $this->repo, new RedirectService(), new UrlService(new HtmlService()))
            ->parse();

        self::assertSame('42', $context->imageId);
        self::assertNull($context->imageFile);
        self::assertSame(1, $context->nextToken);
    }

    public function test_parse_extracts_a_picture_id_and_file_slug(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        $_SERVER['PATH_INFO'] = '/42-my-photo';

        $context = new SectionInitializer(new HtmlService(), $this->repo, new RedirectService(), new UrlService(new HtmlService()))
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

        $context = new SectionInitializer(new HtmlService(), $this->repo, new RedirectService(), new UrlService(new HtmlService()))
            ->parse();

        self::assertSame('most_visited', $context->parsed['section'] ?? null);
    }
}
}
