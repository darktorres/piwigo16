<?php

declare(strict_types=1);

// parse_section_url() (the former free-function bridge) was retired
// (Legacy Coupling Retirement Phase 4c) -- SectionInitializer now calls
// Piwigo\Url\UrlService::parseSectionUrl() directly via a constructor-
// injected UrlServiceInterface, same as every other real caller.

namespace Piwigo\Tests\Integration {

use Override;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Users\UserService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Core\CurrentPaths;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Db\DbConnection;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Section\SectionInitializer;
use Piwigo\Section\SectionRepository;
use Piwigo\Template\CurrentTemplate;

/**
 * Forces the $_SERVER['PATH_INFO'] branch (question_mark_in_urls=false)
 * throughout -- the alternative $_GET-key branch's escaping now goes
 * through SectionRepository (a real DBAL Connection, built here same as
 * every other repository-backed Integration test), so it no longer needs
 * the legacy `global $mysqli` avoidance this test used to require.
 *
 * The "invalid/missing picture identifier" branches call
 * HtmlRenderingInterface::badRequest(), which itself just delegates to
 * RedirectServiceInterface::redirectHtml() -- Workstream C3 converted that
 * from a real header()+echo+exit() sequence to throwing
 * Piwigo\Http\ResponseReadyException (see RedirectService's own
 * docblock), so despite this file's own former docblock claim, they are
 * NOT exit-triggering any more and ARE safely testable, given the same
 * real Template/Lang/Kernel preconditions RedirectServiceTest.php's
 * "already-initialised" scenario needs.
 */
final class SectionInitializerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SectionRepository $repo;

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

        $currentConfig->setQuestionMarkInUrls(false);

        unset($_SERVER['SCRIPT_NAME'], $_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);

        $this->repo = new SectionRepository(EntityManagerFactory::build(DbConnection::build()));
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['PATH_INFO'], $_SERVER['SCRIPT_NAME']);
        UniqueExecLock::reset();
        CurrentTemplate::current()->reset();
        Lang::current()->reset();
        Kernel::reset();
        parent::tearDown();
    }

    /**
     * Shared preconditions for the 2 badRequest()-reaching tests below --
     * a real booted Kernel/ConfigService/Template/Lang, same shape as
     * RedirectServiceTest.php's own "already-initialised template" setup.
     * Lang::current()->setLangInfo() must run BEFORE constructing Template -- its
     * constructor snapshots Lang::langInfo() once into Smarty's own
     * 'lang_info' var (confirmed live: a real "Undefined array key"
     * warning under this suite's failOnWarning=true otherwise).
     */
    private function userService(): UserService
    {
        // IntegrationTestCase::setUp() already booted Kernel -- resolve the
        // same container-shared instance a real request would get, matching
        // RedirectService's own real production callers (singleton/
        // service-locator elimination campaign, Phase 6).
        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        return $userService;
    }

    private function bootRedirectPreconditions(): void
    {
        Kernel::boot();
        CurrentConfigService::current()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfig::current()));
        Lang::current()->setLangInfo(['code' => 'en_UK', 'direction' => 'ltr']);
        CurrentTemplate::current()->set(TemplateTestFactory::build(CurrentPaths::get()->root . 'themes', 'default'));
        CurrentConfig::current()->setSendPiwigoInfos(false);
    }

    public function test_parse_computes_root_path_from_path_info_depth(): void
    {
        $_SERVER['PATH_INFO'] = '/category/1';

        $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(Lang::current(), $this->userService()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfig::current())
            ->parse();

        self::assertSame('../../', $context->rootPath);
        self::assertSame('/category/1', $context->sectionUrl);
        self::assertSame(['category', '1'], $context->tokens);
    }

    public function test_parse_computes_a_deeper_root_path_for_a_deeper_url(): void
    {
        $_SERVER['PATH_INFO'] = '/category/1/start-20';

        $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(Lang::current(), $this->userService()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfig::current())
            ->parse();

        self::assertSame('../../../', $context->rootPath);
    }

    public function test_parse_never_sets_image_id_for_a_non_picture_request(): void
    {
        $_SERVER['PATH_INFO'] = '/category/1';

        $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(Lang::current(), $this->userService()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfig::current())
            ->parse();

        self::assertNull($context->imageId);
        self::assertNull($context->imageFile);
    }

    public function test_parse_extracts_a_purely_numeric_picture_id(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        $_SERVER['PATH_INFO'] = '/42';

        $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(Lang::current(), $this->userService()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfig::current())
            ->parse();

        self::assertSame('42', $context->imageId);
        self::assertNull($context->imageFile);
        self::assertSame(1, $context->nextToken);
    }

    public function test_parse_extracts_a_picture_id_and_file_slug(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        $_SERVER['PATH_INFO'] = '/42-my-photo';

        $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(Lang::current(), $this->userService()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfig::current())
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

        $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(Lang::current(), $this->userService()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfig::current())
            ->parse();

        self::assertSame('most_visited', $context->parsed['section'] ?? null);
    }

    public function test_parse_calls_bad_request_for_a_purely_zero_picture_id(): void
    {
        $this->bootRedirectPreconditions();
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        $_SERVER['PATH_INFO'] = '/0';
        $execId = UniqueExecLock::begins('check_for_updates');
        self::assertTrue($execId);

        $body = null;
        $status = null;
        try {
            new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(Lang::current(), $this->userService()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfig::current())
                ->parse();
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        } finally {
            UniqueExecLock::ends('check_for_updates');
        }

        self::assertSame(400, $status);
        self::assertIsString($body);
        self::assertStringContainsString('invalid picture identifier', $body);
    }

    public function test_parse_calls_bad_request_for_a_missing_picture_id(): void
    {
        $this->bootRedirectPreconditions();
        $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
        // A bare '/' -> ltrim/explode yields a single empty-string token,
        // which is neither purely numeric (the id===0 branch above) nor
        // matches the "<digits>-<slug>" shape -- the genuinely-missing
        // identifier case.
        $_SERVER['PATH_INFO'] = '/';
        $execId = UniqueExecLock::begins('check_for_updates');
        self::assertTrue($execId);

        $body = null;
        $status = null;
        try {
            new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(Lang::current(), $this->userService()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfig::current())
                ->parse();
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        } finally {
            UniqueExecLock::ends('check_for_updates');
        }

        self::assertSame(400, $status);
        self::assertIsString($body);
        self::assertStringContainsString('picture identifier is missing', $body);
    }
}
}
