<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Image\DerivativeImage;
use Piwigo\Template\TemplateAdapter;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;

/**
 * Piwigo\Template\TemplateAdapter -- the `pwg`-assigned object behind
 * `derivative`/`derivativeUrl` template calls; had zero dedicated coverage.
 * Both need the same real DB-backed ImageStdParams/DerivativeImage::
 * setUrlService() wiring (see
 * NotificationByMailSenderTest/MailServiceTest's own docblocks) -- placed in
 * Integration, not Unit, for that reason.
 */
final class TemplateAdapterTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private TemplateAdapter $adapter;

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
        Kernel::boot();

        $conn = DbConnection::build();
        $repo = TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class), ConfigRepository::class);
        $configService = new ConfigService($repo, CurrentConfigTestFactory::get());
        CurrentConfigServiceTestFactory::get()->set($configService);
        $configService->loadConfFromDb();
        ImageStdParamsTestFactory::get()->loadFromDb();

        $this->adapter = new TemplateAdapter(CurrentConfigTestFactory::get());
    }

    #[Override]
    protected function tearDown(): void
    {
        Kernel::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeImageInfos(): array
    {
        return [
            'id' => 1,
            'path' => 'upload/2026/08/01/x.jpg',
            'file' => 'x.jpg',
            'representative_ext' => null,
        ];
    }

    public function testDerivativeBuildsARealDerivativeImage(): void
    {
        $derivative = $this->adapter->derivative('thumb', $this->fakeImageInfos());

        // derivative()'s own return type already guarantees the class --
        // what's actually under test is that building one from real image
        // info doesn't throw.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(DerivativeImage::class, $derivative);
    }

    public function testDerivativeUrlReturnsARealUrlString(): void
    {
        $url = $this->adapter->derivativeUrl('thumb', $this->fakeImageInfos());

        self::assertStringContainsString('x-th.jpg', $url);
    }
}
