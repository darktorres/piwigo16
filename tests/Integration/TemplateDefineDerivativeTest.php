<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\Dimensions;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Piwigo\Template\Template::defineDerivative() needs a real DB:
 * ImageStdParams::getCustom()'s first-use-in-24h path calls
 * ConfigService::confUpdateParam() whenever a custom w/h/crop
 * combination is new, unlike every other Template instance method
 * covered by TemplateInstanceTest.php.
 *
 * defineDerivative() takes natively-typed parameters (?string $type,
 * ?int $width, ?int $height, bool|float|int $crop, ?int $minWidth,
 * ?int $minHeight), so invalid-type input is rejected by PHP's type
 * system before the method ever runs -- there is nothing to test for
 * non-string/non-scalar arguments.
 */
final class TemplateDefineDerivativeTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Template $template;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));
        ImageStdParamsTestFactory::get()->loadFromDb();
        CurrentUserTestFactory::get()->attachGlobals();

        $this->template = TemplateTestFactory::build();
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentUserTestFactory::get()->reset();
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    private function callExpectingFatal(callable $call, string $expectedMessage): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $call();
            self::fail('defineDerivative() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            self::assertStringContainsString($expectedMessage, (string) $e->response()->getBody());
        } finally {
            restore_error_handler();
        }
    }

    public function testTypeParamReturnsARealKnownDerivative(): void
    {
        $derivative = $this->template->defineDerivative(type: 'thumb');

        self::assertSame('thumb', $derivative->type);
    }

    public function testMissingHeightWithoutATypeIsFatal(): void
    {
        $this->callExpectingFatal(
            fn (): DerivativeParams => $this->template->defineDerivative(width: 100),
            'defineDerivative missing width or height'
        );
    }

    public function testMissingWidthWithoutATypeIsFatal(): void
    {
        $this->callExpectingFatal(
            fn (): DerivativeParams => $this->template->defineDerivative(height: 80),
            'defineDerivative missing width or height'
        );
    }

    public function testABasicWidthAndHeightBuildsACustomDerivative(): void
    {
        $derivative = $this->template->defineDerivative(width: 100, height: 80);

        self::assertEquals(new Dimensions(100, 80), $derivative->sizing->ideal_size);
        // Omitted $crop defaults to int 0 (a non-bool), so defineDerivative()
        // takes its round()-based numeric branch, not the is_bool() one --
        // that always produces a float, unlike an explicit crop:false (see
        // "crop as a false boolean" below, which does hit the bool branch
        // and gets a real int 0).
        self::assertSame(0.0, $derivative->sizing->max_crop);
        self::assertNull($derivative->sizing->min_size);
    }

    public function testCropAsATrueBooleanDefaultsMinSizeToTheFullWidthAndHeight(): void
    {
        $derivative = $this->template->defineDerivative(width: 100, height: 80, crop: true);

        self::assertSame(1, $derivative->sizing->max_crop);
        self::assertEquals(new Dimensions(100, 80), $derivative->sizing->min_size);
    }

    public function testCropAsAFalseBooleanLeavesCropDisabled(): void
    {
        $derivative = $this->template->defineDerivative(width: 100, height: 80, crop: false);

        self::assertSame(0, $derivative->sizing->max_crop);
        self::assertNull($derivative->sizing->min_size);
    }

    public function testCropAsANumericPercentageIsDividedBy100(): void
    {
        $derivative = $this->template->defineDerivative(width: 100, height: 80, crop: 50);

        self::assertSame(0.5, $derivative->sizing->max_crop);
    }

    public function testCropWithAnExplicitMinWidthIsUsedVerbatim(): void
    {
        $derivative = $this->template->defineDerivative(width: 100, height: 80, crop: 50, minWidth: 50);

        self::assertEquals(new Dimensions(50, 80), $derivative->sizing->min_size);
    }

    public function testMinWidthGreaterThanWidthIsFatal(): void
    {
        $this->callExpectingFatal(
            fn (): DerivativeParams => $this->template->defineDerivative(width: 100, height: 80, crop: 50, minWidth: 200),
            'defineDerivative invalid min_width'
        );
    }

    public function testCropWithAnExplicitMinHeightIsUsedVerbatim(): void
    {
        $derivative = $this->template->defineDerivative(width: 100, height: 80, crop: 50, minHeight: 50);

        self::assertEquals(new Dimensions(100, 50), $derivative->sizing->min_size);
    }

    public function testMinHeightGreaterThanHeightIsFatal(): void
    {
        $this->callExpectingFatal(
            fn (): DerivativeParams => $this->template->defineDerivative(width: 100, height: 80, crop: 50, minHeight: 200),
            'defineDerivative invalid min_height'
        );
    }
}
