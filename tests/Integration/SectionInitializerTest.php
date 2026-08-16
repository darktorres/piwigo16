<?php

declare(strict_types=1);

// SectionInitializer calls Piwigo\Url\UrlService::parseSectionUrl()
// directly via a constructor-injected UrlServiceInterface, same as every
// other real caller.

namespace Piwigo\Tests\Integration {

    use LogicException;
    use Override;
    use Piwigo\Bootstrap\RedirectService;
    use Piwigo\Common\Enum\Section;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\ConfigService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\Kernel;
    use Piwigo\Core\Logger;
    use Piwigo\Core\RequestMountDepth;
    use Piwigo\Core\UniqueExecLock;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Http\ResponseReadyException;
    use Piwigo\Section\SectionInitializer;
    use Piwigo\Section\SectionRepository;
    use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Tests\Support\CurrentTemplateTestFactory;
    use Piwigo\Tests\Support\EventDispatcherTestFactory;
    use Piwigo\Tests\Support\HtmlServiceTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\PageStateTestFactory;
    use Piwigo\Tests\Support\TemplateTestFactory;
    use Piwigo\Tests\Support\UrlServiceTestFactory;
    use Piwigo\Users\UserService;

    /**
     * Forces the $_SERVER['PATH_INFO'] branch (question_mark_in_urls=false)
     * throughout -- the alternative $_GET-key branch's escaping goes through
     * SectionRepository (a real DBAL Connection, built here same as every
     * other repository-backed Integration test).
     *
     * The "invalid/missing picture identifier" branches call
     * HtmlRenderingInterface::badRequest(), which itself just delegates to
     * RedirectServiceInterface::redirectHtml() -- this throws
     * Piwigo\Http\ResponseReadyException rather than doing a real
     * header()+echo+exit() sequence (see RedirectService's own docblock), so
     * they are safely testable, given the same real Template/Lang/Kernel
     * preconditions RedirectServiceTest.php's "already-initialised" scenario
     * needs.
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

            $currentConfig->questionMarkInUrls = false;

            unset($_SERVER['SCRIPT_NAME'], $_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);

            $this->repo = new SectionRepository(EntityManagerFactory::build(DbConnection::build()));
        }

        #[Override]
        protected function tearDown(): void
        {
            unset($_SERVER['PATH_INFO'], $_SERVER['SCRIPT_NAME']);
            UniqueExecLock::reset();
            CurrentTemplateTestFactory::get()->reset();
            LangTestFactory::get()->reset();
            Kernel::reset();
            parent::tearDown();
        }

        /**
         * Shared preconditions for the 2 badRequest()-reaching tests below --
         * a real booted Kernel/ConfigService/Template/Lang, same shape as
         * RedirectServiceTest.php's own "already-initialised template" setup.
         * LangTestFactory::get()->setLangInfo() must run BEFORE constructing Template -- its
         * constructor snapshots Lang::langInfo() once into the 'lang_info'
         * template var, otherwise triggering a real "Undefined array key"
         * warning under this suite's failOnWarning=true.
         */
        private function userService(): UserService
        {
            // IntegrationTestCase::setUp() already booted Kernel -- resolve the
            // same container-shared instance a real request would get, matching
            // RedirectService's own real production callers.
            $userService = Kernel::container()->get(UserService::class);
            if (! $userService instanceof UserService) {
                throw new LogicException('Container returned an unexpected type for ' . UserService::class);
            }

            return $userService;
        }

        private function bootRedirectPreconditions(): void
        {
            Kernel::boot();
            CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));
            LangTestFactory::get()->setLangInfo([
                'code' => 'en_UK',
                'direction' => 'ltr',
            ]);
            CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));
            CurrentConfigTestFactory::get()->sendPiwigoInfos = false;
        }

        public function testParseComputesRootPathFromPathInfoDepth(): void
        {
            $_SERVER['PATH_INFO'] = '/category/1';

            $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfigTestFactory::get())
                ->parse();

            self::assertSame('../../', $context->rootPath);
            self::assertSame('/category/1', $context->sectionUrl);
            self::assertSame(['category', '1'], $context->tokens);
        }

        public function testParseComputesADeeperRootPathForADeeperUrl(): void
        {
            $_SERVER['PATH_INFO'] = '/category/1/start-20';

            $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfigTestFactory::get())
                ->parse();

            self::assertSame('../../../', $context->rootPath);
        }

        public function testParseNeverSetsImageIdForANonPictureRequest(): void
        {
            $_SERVER['PATH_INFO'] = '/category/1';

            $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfigTestFactory::get())
                ->parse();

            self::assertNull($context->imageId);
            self::assertNull($context->imageFile);
        }

        public function testParseExtractsAPurelyNumericPictureId(): void
        {
            $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
            $_SERVER['PATH_INFO'] = '/42';

            $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfigTestFactory::get())
                ->parse();

            self::assertSame('42', $context->imageId);
            self::assertNull($context->imageFile);
            self::assertSame(1, $context->nextToken);
        }

        public function testParseExtractsAPictureIdAndFileSlug(): void
        {
            $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
            $_SERVER['PATH_INFO'] = '/42-my-photo';

            $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfigTestFactory::get())
                ->parse();

            self::assertSame('42', $context->imageId);
            self::assertSame('my-photo', $context->imageFile);
        }

        public function testParseDelegatesSectionParsingToUrlService(): void
        {
            // 'most_visited' is a plain recognized token (no further DB-backed
            // resolution needed, unlike e.g. 'tags' -> find_tags()) -- confirms
            // delegation to the real UrlService::parseSectionUrl() genuinely
            // happened rather than this being a hardcoded default.
            $_SERVER['PATH_INFO'] = '/most_visited';

            $context = new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfigTestFactory::get())
                ->parse();

            self::assertSame(Section::MostVisited, $context->parsed['section'] ?? null);
        }

        public function testParseCallsBadRequestForAPurelyZeroPictureId(): void
        {
            $this->bootRedirectPreconditions();
            $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
            $_SERVER['PATH_INFO'] = '/0';
            $execId = UniqueExecLock::begins(new Logger([
                'severity' => Logger::OFF,
            ]), 'check_for_updates');
            self::assertTrue($execId);

            $body = null;
            $status = null;
            try {
                new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfigTestFactory::get())
                    ->parse();
            } catch (ResponseReadyException $e) {
                $response = $e->response();
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();
            } finally {
                UniqueExecLock::ends(new Logger([
                    'severity' => Logger::OFF,
                ]), 'check_for_updates');
            }

            self::assertSame(400, $status);
            self::assertIsString($body);
            self::assertStringContainsString('invalid picture identifier', $body);
        }

        public function testParseCallsBadRequestForAMissingPictureId(): void
        {
            $this->bootRedirectPreconditions();
            $_SERVER['SCRIPT_NAME'] = '/piwigo17/picture.php';
            // A bare '/' -> ltrim/explode yields a single empty-string token,
            // which is neither purely numeric (the id===0 branch above) nor
            // matches the "<digits>-<slug>" shape -- the genuinely-missing
            // identifier case.
            $_SERVER['PATH_INFO'] = '/';
            $execId = UniqueExecLock::begins(new Logger([
                'severity' => Logger::OFF,
            ]), 'check_for_updates');
            self::assertTrue($execId);

            $body = null;
            $status = null;
            try {
                new SectionInitializer(HtmlServiceTestFactory::build(), $this->repo, new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new RequestMountDepth(), CurrentConfigTestFactory::get())
                    ->parse();
            } catch (ResponseReadyException $e) {
                $response = $e->response();
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();
            } finally {
                UniqueExecLock::ends(new Logger([
                    'severity' => Logger::OFF,
                ]), 'check_for_updates');
            }

            self::assertSame(400, $status);
            self::assertIsString($body);
            self::assertStringContainsString('picture identifier is missing', $body);
        }
    }
}
