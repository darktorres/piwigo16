<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Template\PwgTemplateAdapter;

/**
 * Piwigo\Template\PwgTemplateAdapter -- the Smarty-registered object behind
 * `derivative`/`derivative_url` template calls; had zero dedicated coverage
 * (see /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1). Both
 * need the same real DB-backed ImageStdParams/DerivativeImage::
 * setUrlService() wiring already established this session (see
 * NotificationByMailSenderTest/MailServiceTest's own docblocks) -- placed in
 * Integration, not Unit, for that reason.
 */
final class PwgTemplateAdapterTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PwgTemplateAdapter $adapter;

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
        $repo = EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class);
        $configService = new ConfigService($repo, new EventDispatcher(), CurrentConfigTestFactory::get());
        CurrentConfigServiceTestFactory::get()->set($configService);
        $configService->loadConfFromDb();
        ImageStdParamsTestFactory::get()->load_from_db();

        $this->adapter = new PwgTemplateAdapter(CurrentConfigTestFactory::get());
    }

    #[Override]
    protected function tearDown(): void
    {
        Kernel::reset();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function fakeImageInfos(): array
    {
        return [
            'id' => 1,
            'path' => 'upload/2026/08/01/x.jpg',
            'file' => 'x.jpg',
            'representative_ext' => null,
        ];
    }

    public function test_derivative_builds_a_real_derivative_image(): void
    {
        $derivative = $this->adapter->derivative('thumb', $this->fakeImageInfos());

        // derivative()'s own return type already guarantees the class --
        // what's actually under test is that building one from real image
        // info doesn't throw.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(DerivativeImage::class, $derivative);
    }

    public function test_derivative_url_returns_a_real_url_string(): void
    {
        $url = $this->adapter->derivative_url('thumb', $this->fakeImageInfos());

        self::assertStringContainsString('x-th.jpg', $url);
    }
}
