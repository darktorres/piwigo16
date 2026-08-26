<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Http\Middleware\LanguageMiddleware;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `Http\Middleware\LanguageMiddleware` is the real, direct successor of
 * the first half of `Bootstrap\RequestBootstrap::finalize()` (workstream
 * C3 Phase 1) -- language loading through the api_key-expiration
 * notification, stopping before the still-legacy `// template instance`
 * remainder that stays in `finalize()` itself. Replaces the equivalent
 * case from the now-removed `RequestBootstrapFinalizeTest::
 * testFinalizeAddsAStaleAuthKeyErrorMessage()`, ported onto the new
 * middleware boundary rather than mechanically moved (Plan 3's own "Test
 * portability correction").
 */
final class LanguageMiddlewareTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $configService = Kernel::container()->get(ConfigService::class);
        self::assertInstanceOf(ConfigService::class, $configService);
        $configService->loadConfFromDb();
        // Same real precondition PluginBootstrapMiddlewareTest establishes
        // -- LanguageMiddleware constructs a real UserService, which reads
        // CurrentConfigService transitively through its own dependency
        // chain the same way LoungeMaintenance does there.
        CurrentConfigServiceTestFactory::get()->set($configService);

        unset($_REQUEST['method']);
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_REQUEST['method']);

        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testProcessAddsAStaleAuthKeyErrorMessage(): void
    {
        PageStateTestFactory::get()->markAuthKeyInvalid();

        $middleware = Kernel::container()->get(LanguageMiddleware::class);
        self::assertInstanceOf(LanguageMiddleware::class, $middleware);

        $middleware->process(new ServerRequest('GET', '/'), $this->passthroughHandler());

        self::assertSame(
            [LangTestFactory::get()->t('Your authentication key is no longer valid.') . sprintf(
                ' <a href="%s">%s</a>',
                UrlServiceTestFactory::build()->getRootUrl() . 'identification.php',
                LangTestFactory::get()->t('Login')
            )],
            PageStateTestFactory::get()->errors
        );
    }

    public function testProcessRunsItsFullBodyWithoutErrorAndCallsTheNextHandler(): void
    {
        // A real smoke test: language loading (common.lang, the LoadingLang
        // event, site-local overrides), the guest-username localization
        // branch (the default fixture user is a guest), and the api_key-
        // expiration check all run for real against the real DB/container
        // without throwing.
        $middleware = Kernel::container()->get(LanguageMiddleware::class);
        self::assertInstanceOf(LanguageMiddleware::class, $middleware);

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class() implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
