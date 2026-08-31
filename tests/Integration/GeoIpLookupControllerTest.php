<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Api\GeoIpLookupController;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\GeoIp\GeoIpLookupService;
use Piwigo\Http\AdminGuard;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * `GET /api/v1/geoip` -- the real replacement for jquery.geoip.js's own
 * client-side call to the long-dead freegeoip.net JSONP endpoint
 * (docs/PLAN.md P49-B group 1's own finding). Fixture users: id=1 is
 * `fixture_admin`/webmaster, id=2 is `guest`, id=3 is `regular_user`/
 * normal -- same ids CommentListControllerTest.php's own AdminGuard
 * coverage uses.
 *
 * $paths->data points at a fixture-database temp dir this test controls
 * directly (GeoIpLookupServiceTest.php's own MaxMind test-data fixture),
 * not the real, potentially-absent DB-IP file -- correctness of the
 * lookup itself is that file's job; this one only has to prove the
 * controller wires AdminGuard/GeoIpLookupService/query-param validation
 * together correctly.
 */
final class GeoIpLookupControllerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private string $geoIpRoot;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = $this->resolve(CurrentConfig::class);
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        Kernel::boot();

        $this->geoIpRoot = sys_get_temp_dir() . '/piwigo-geoip-controller-test-' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->geoIpRoot . 'geoip', 0o777, true);
        copy(dirname(__DIR__) . '/Fixtures/GeoIp/GeoIP2-City-Test.mmdb', $this->geoIpRoot . 'geoip/dbip-city-lite.mmdb');
    }

    #[Override]
    protected function tearDown(): void
    {
        Kernel::reset();
        parent::tearDown();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function resolve(string $class): object
    {
        $instance = Kernel::container()->get($class);
        if (! $instance instanceof $class) {
            throw new LogicException('Container returned an unexpected type for ' . $class);
        }

        return $instance;
    }

    private function seedUser(UserStatus $status, int $id): void
    {
        $this->resolve(CurrentUser::class)->set(new User(
            id: UserId::from($id),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: $status,
            enabledHigh: false,
        ));
    }

    private function seedAdmin(): void
    {
        $this->seedUser(UserStatus::Webmaster, 1);
    }

    private function testPaths(): Paths
    {
        $root = $this->geoIpRoot;

        return new Paths(
            root: $root,
            plugins: $root . 'plugins/',
            themes: $root . 'themes/',
            local: $root . 'local/',
            siteLocal: $root . 'local/',
            data: $root,
            derivatives: $root . 'i/',
            logs: $root . 'logs/',
            upload: $root . 'upload/',
            config: $root . 'config/',
            vendor: $root . 'vendor/',
        );
    }

    private function buildController(): GeoIpLookupController
    {
        return new GeoIpLookupController(
            new AdminGuard(new AccessControl(
                $this->resolve(HtmlRenderingInterface::class),
                $this->resolve(RedirectServiceInterface::class),
                $this->resolve(AccessLevelChecker::class),
            )),
            new GeoIpLookupService($this->testPaths()),
        );
    }

    public function testInvokeReturns401WhenNoSessionIsSignedIn(): void
    {
        $this->seedUser(UserStatus::Guest, 2);

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/geoip?ip=81.2.69.142'));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testInvokeReturns403WhenSignedInButNotAnAdmin(): void
    {
        $this->seedUser(UserStatus::Normal, 3);

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/geoip?ip=81.2.69.142'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testInvokeReturns422WhenIpIsMissing(): void
    {
        $this->seedAdmin();

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/geoip'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testInvokeReturns422ForAMalformedIp(): void
    {
        $this->seedAdmin();

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/geoip?ip=not-an-ip'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testInvokeReturnsTheMatchForAKnownIp(): void
    {
        $this->seedAdmin();

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/geoip?ip=81.2.69.142'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertTrue($body['available'] ?? null);
        self::assertSame('London, England, United Kingdom', $body['fullName'] ?? null);
        self::assertSame(51.5142, $body['latitude'] ?? null);
        self::assertSame(-0.0931, $body['longitude'] ?? null);
    }

    public function testInvokeReturnsAvailableFalseForAnUnmatchedIp(): void
    {
        $this->seedAdmin();

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/geoip?ip=203.0.113.1'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertFalse($body['available'] ?? null);
    }
}
