<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Http\Middleware\ConfigBootstrapMiddleware;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `Http\Middleware\ConfigBootstrapMiddleware` is the real, direct
 * successor of `Bootstrap\RequestBootstrap::connect()`'s DB-unreachable
 * branch (workstream C3 Phase 1) -- this class replaces the equivalent
 * case in the now-deleted `RequestBootstrapConnectTest::
 * testConnectShowsAFatalErrorPageWhenTheDatabaseIsUnreachable()`, ported
 * onto the new middleware boundary rather than mechanically moved (see
 * Plan 3's own "Test portability correction" -- connect() tested this as
 * one whole-method unit, this middleware is the real, narrower home for
 * just this branch now).
 *
 * The rest of connect()'s former branches (fresh-install version stamp,
 * autoupdate re-stamp, order-by-custom override, lounge-emptying,
 * LoadedPlugins repopulation) belong to `Http\Middleware\
 * PluginBootstrapMiddleware`/`Admin\LoadedPluginsMiddleware` instead --
 * see `PluginBootstrapMiddlewareTest`/`LoadedPluginsMiddlewareTest`.
 */
final class ConfigBootstrapMiddlewareTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    /**
     * @var array<string, string> original PIWIGO_DB_* env values, restored
     *   in tearDown() -- DbCredentials::seed() mutates real process env
     *   vars via putenv(), which would otherwise leak a bad/throwaway
     *   credential into every later test in this shared process (same
     *   reasoning as the deleted RequestBootstrapConnectTest's own
     *   $originalDbEnv).
     */
    private array $originalDbEnv = [];

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

        foreach (['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_DRIVER', 'PIWIGO_DB_PORT'] as $key) {
            $value = getenv($key);
            $this->originalDbEnv[$key] = $value === false ? '' : $value;
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        DbCredentialsTestFactory::get()->seed($this->originalDbEnv);

        // ConfigBootstrapMiddleware's own first statement is
        // ErrorCollector::installIfConfigured() -- every test below reaches
        // it regardless of what happens afterwards, so every test leaves a
        // real set_error_handler() active unless undone here (same
        // "restore immediately" discipline the deleted
        // RequestBootstrapConnectTest's own docblock documented).
        $errorCollector = Kernel::container()->get(ErrorCollector::class);
        if ($errorCollector instanceof ErrorCollector) {
            if ($errorCollector->isActive()) {
                restore_error_handler();
            }
            $errorCollector->reset();
        }

        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testProcessShowsAFatalErrorPageWhenTheDatabaseIsUnreachable(): void
    {
        // A wrong password fails fast (a real driver auth-failure reply)
        // instead of blocking on a real ~60s connect-timeout the way an
        // unreachable host/IP would -- same reasoning as
        // InstallServiceTest::test_installDbConnect_returns_null_and_records_an_error_for_a_wrong_password.
        // The middleware calls DbConnection::build() itself and needs a
        // genuinely fresh per-call connection attempt against these bad
        // credentials, not the wrapper's one shared, already-open
        // connection from setUp() -- see tests/Pest.php's own docblock for
        // this exact documented exception.
        DbTransactionTestOverride::rollback();
        DbCredentialsTestFactory::get()->seed([
            'PIWIGO_DB_HOST' => $this->dbHost,
            'PIWIGO_DB_USER' => $this->dbUser,
            'PIWIGO_DB_PASSWORD' => $this->dbPass . '-definitely-wrong',
            'PIWIGO_DB_BASE' => $this->dbName,
        ]);

        $middleware = Kernel::container()->get(ConfigBootstrapMiddleware::class);
        self::assertInstanceOf(ConfigBootstrapMiddleware::class, $middleware);

        try {
            $middleware->process(new ServerRequest('GET', '/'), $this->unreachedHandler());
            self::fail('process() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            self::assertSame(500, $response->getStatusCode());
            // Specific content, not just "some non-empty string" -- proves
            // the real driver exception message made it through
            // Lang::t($e->getMessage()) into the fatalError() page body.
            // Real wording differs per driver -- MySQL's mysqli says
            // "Access denied", Postgres says "password authentication
            // failed" (confirmed live against the real server).
            self::assertStringContainsString(
                $this->dbDriver === 'pgsql' ? 'password authentication failed' : 'Access denied',
                (string) $response->getBody()
            );
        }
    }

    /**
     * `HtmlService::fatalError()` is `: never` -- it always throws before
     * `process()` reaches its own trailing `return $handler->handle($request);`,
     * so this handler is never actually invoked; it exists only to satisfy
     * `process()`'s own parameter type.
     */
    private function unreachedHandler(): RequestHandlerInterface
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
