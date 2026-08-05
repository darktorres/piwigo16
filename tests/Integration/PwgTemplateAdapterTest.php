<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Config\CurrentConfig;
use Piwigo\Image\ImageStdParams;
use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Template\PwgTemplateAdapter;

/**
 * Piwigo\Template\PwgTemplateAdapter -- the Smarty-registered object behind
 * `l10n`/`l10n_dec`/`sprintf`/`derivative`/`derivative_url` template-modifier
 * calls; had zero dedicated coverage (see /home/torres/.claude/plans/
 * piped-enchanting-spark.md, Wave 1). Every real method is `#[\Deprecated]`
 * (phpunit.xml's own `failOnDeprecation="true"` would otherwise fail any
 * direct call), and derivative()/derivative_url() need the same real
 * DB-backed ImageStdParams/DerivativeImage::setUrlService() wiring already
 * established this session (see NotificationByMailSenderTest/
 * MailServiceTest's own docblocks) -- placed in Integration, not Unit, for
 * that reason, even though 3 of the 5 methods are otherwise pure.
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
        $configService = new ConfigService($repo, new EventDispatcher(), CurrentConfig::current());
        CurrentConfigService::current()->set($configService);
        $configService->loadConfFromDb();
        ImageStdParams::current()->load_from_db();

        $this->adapter = new PwgTemplateAdapter(Lang::current(), Translator::get(), CurrentConfig::current());
    }

    #[Override]
    protected function tearDown(): void
    {
        Kernel::reset();
        parent::tearDown();
    }

    /**
     * These 3 methods carry real, deliberate #[\Deprecated] attributes --
     * a plain @ does NOT stop PHPUnit's ErrorHandler from surfacing the
     * resulting notice regardless (confirmed: @ only affects
     * error_reporting(), not whether the handler chain runs), so a real
     * no-op error handler for the duration of the one expected-to-warn
     * call is the only reliable way to swallow it, matching ImageGdTest's
     * own established pattern.
     */
    private function suppressDeprecation(callable $fn): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function test_l10n_returns_the_key_untranslated_when_no_translation_exists(): void
    {
        self::assertSame('Hello', $this->suppressDeprecation(fn () => $this->adapter->l10n('Hello')));
    }

    public function test_l10n_dec_selects_the_singular_or_plural_form(): void
    {
        self::assertSame('1 item', $this->suppressDeprecation(fn () => $this->adapter->l10n_dec('%d item', '%d items', 1)));
        self::assertSame('3 items', $this->suppressDeprecation(fn () => $this->adapter->l10n_dec('%d item', '%d items', 3)));
    }

    public function test_sprintf_delegates_to_the_real_sprintf(): void
    {
        self::assertSame('x-5', $this->suppressDeprecation(fn () => $this->adapter->sprintf('%s-%d', 'x', 5)));
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

        self::assertInstanceOf(DerivativeImage::class, $derivative);
    }

    public function test_derivative_url_returns_a_real_url_string(): void
    {
        $url = $this->adapter->derivative_url('thumb', $this->fakeImageInfos());

        self::assertStringContainsString('x-th.jpg', $url);
    }
}
