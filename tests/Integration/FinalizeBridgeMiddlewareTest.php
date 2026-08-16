<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Bootstrap\FinalizeBridgeMiddleware;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\ServerTiming;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `Bootstrap\FinalizeBridgeMiddleware` -- the last step of the real
 * bootstrap-phase middleware chain (workstream C3 Phase 1). Its own body
 * (`RequestBootstrap::finalize()`, `ServerTiming::stop('boot')`, delegate
 * to the next handler) is a thin, deliberate bridge, not real logic of
 * its own -- `RequestBootstrapFinalizeTest.php` already covers
 * `finalize()`'s own branches in depth. This file's job is narrower:
 * prove the bridge itself actually calls both real things it wraps
 * (observable via `CurrentTemplate` getting initialized -- a real
 * `finalize()`-only side effect -- and via the 'boot' `ServerTiming`
 * entry gaining a real, non-zero duration) and correctly passes the
 * response through.
 */
final class FinalizeBridgeMiddlewareTest extends IntegrationTestCase
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

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));

        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from(3),
            username: Username::from('regular_user'),
            email: Email::from('regular@example.test'),
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: UserStatus::Normal,
            enabledHigh: true,
        ));
    }

    public function testProcessCallsFinalizeStopsTheBootTimerAndPassesTheResponseThrough(): void
    {
        $serverTiming = Kernel::container()->get(ServerTiming::class);
        self::assertInstanceOf(ServerTiming::class, $serverTiming);
        // configure()'s own first statement, replicated here since this
        // test calls the bridge directly rather than going through
        // bootEntryPoint() -- stop('boot') below is a no-op against a
        // timer that was never start()ed (see ServerTiming::stop()'s own
        // isset() guard).
        $serverTiming->start('boot', microtime(true) - 0.05);

        self::assertFalse(CurrentTemplateTestFactory::get()->isInitialized());

        $middleware = Kernel::container()->get(FinalizeBridgeMiddleware::class);
        self::assertInstanceOf(FinalizeBridgeMiddleware::class, $middleware);

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
        // finalize() genuinely ran: it's the only thing that initializes
        // CurrentTemplate.
        self::assertTrue(CurrentTemplateTestFactory::get()->isInitialized());
        // stop('boot') genuinely ran: a real, positive duration recorded
        // against the 50ms head start seeded above.
        $durations = $serverTiming->all();
        self::assertArrayHasKey('boot', $durations);
        self::assertGreaterThan(0.0, $durations['boot']);
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
