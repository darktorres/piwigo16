<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\DerivativeParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Piwigo\Template\Template::funcDefineDerivative() -- the single
 * largest red-line cluster in this class (roughly a third of its own
 * total gap). Genuinely needs a real DB: ImageStdParams::getCustom()'s
 * own first-use-in-24h path calls ConfigService::confUpdateParam()
 * (confirmed live -- every custom w/h/crop combination this test uses is
 * new, so save() always fires), unlike every other Template instance
 * method covered by TemplateInstanceTest.php.
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
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get()));
        ImageStdParamsTestFactory::get()->loadFromDb();
        CurrentUserTestFactory::get()->attachGlobals();

        $this->template = TemplateTestFactory::build();
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentUserTestFactory::get()->reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function callExpectingFatal(array $params, string $expectedMessage): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $this->template->funcDefineDerivative($params, $this->template->smarty);
            self::fail('funcDefineDerivative() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            self::assertStringContainsString($expectedMessage, (string) $e->response()->getBody());
        } finally {
            restore_error_handler();
        }
    }

    public function testMissingNameIsFatal(): void
    {
        $this->callExpectingFatal([], 'define_derivative missing name');
    }

    public function testNonStringNameIsFatal(): void
    {
        $this->callExpectingFatal([
            'name' => 123,
        ], 'define_derivative missing name');
    }

    public function testTypeParamAssignsARealKnownDerivative(): void
    {
        $this->template->funcDefineDerivative([
            'name' => 'd',
            'type' => 'thumb',
        ], $this->template->smarty);

        $derivative = $this->template->getTemplateVars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame('thumb', $derivative->type);
    }

    public function testNonStringTypeIsFatal(): void
    {
        $this->callExpectingFatal([
            'name' => 'd',
            'type' => 123,
        ], 'define_derivative type must be a string');
    }

    public function testMissingWidthWithoutATypeIsFatal(): void
    {
        $this->callExpectingFatal([
            'name' => 'd',
        ], 'define_derivative missing width');
    }

    public function testMissingHeightIsFatal(): void
    {
        $this->callExpectingFatal([
            'name' => 'd',
            'width' => 100,
        ], 'define_derivative missing height');
    }

    public function testNonScalarWidthIsFatal(): void
    {
        $this->callExpectingFatal([
            'name' => 'd',
            'width' => [],
            'height' => 100,
        ], 'define_derivative missing width');
    }

    public function testNonScalarHeightIsFatal(): void
    {
        $this->callExpectingFatal([
            'name' => 'd',
            'width' => 100,
            'height' => [],
        ], 'define_derivative missing height');
    }

    public function testABasicWidthAndHeightBuildsACustomDerivative(): void
    {
        $this->template->funcDefineDerivative([
            'name' => 'd',
            'width' => 100,
            'height' => 80,
        ], $this->template->smarty);

        $derivative = $this->template->getTemplateVars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame([100, 80], $derivative->sizing->ideal_size);
        self::assertSame(0, $derivative->sizing->max_crop);
        self::assertNull($derivative->sizing->min_size);
    }

    public function testCropAsATrueBooleanDefaultsMinSizeToTheFullWidthAndHeight(): void
    {
        $this->template->funcDefineDerivative([
            'name' => 'd',
            'width' => 100,
            'height' => 80,
            'crop' => true,
        ], $this->template->smarty);

        $derivative = $this->template->getTemplateVars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame(1, $derivative->sizing->max_crop);
        self::assertSame([100, 80], $derivative->sizing->min_size);
    }

    public function testCropAsAFalseBooleanLeavesCropDisabled(): void
    {
        $this->template->funcDefineDerivative([
            'name' => 'd',
            'width' => 100,
            'height' => 80,
            'crop' => false,
        ], $this->template->smarty);

        $derivative = $this->template->getTemplateVars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame(0, $derivative->sizing->max_crop);
        self::assertNull($derivative->sizing->min_size);
    }

    public function testCropAsANumericPercentageIsDividedBy100(): void
    {
        $this->template->funcDefineDerivative([
            'name' => 'd',
            'width' => 100,
            'height' => 80,
            'crop' => 50,
        ], $this->template->smarty);

        $derivative = $this->template->getTemplateVars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame(0.5, $derivative->sizing->max_crop);
    }

    public function testNonNumericCropIsFatal(): void
    {
        $this->callExpectingFatal(
            [
                'name' => 'd',
                'width' => 100,
                'height' => 80,
                'crop' => 'abc',
            ],
            'define_derivative crop must be numeric'
        );
    }

    public function testCropWithAnExplicitMinWidthIsUsedVerbatim(): void
    {
        $this->template->funcDefineDerivative(
            [
                'name' => 'd',
                'width' => 100,
                'height' => 80,
                'crop' => 50,
                'min_width' => 50,
            ],
            $this->template->smarty
        );

        $derivative = $this->template->getTemplateVars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame([50, 80], $derivative->sizing->min_size);
    }

    public function testNonScalarMinWidthIsFatal(): void
    {
        // An empty array specifically is one of the "absent" sentinel
        // values (in_array(..., true) against [null, false, 0, '0', '', []])
        // -- confirmed live, that short-circuits before the scalar check
        // ever runs. A non-empty array is what actually reaches it.
        $this->callExpectingFatal(
            [
                'name' => 'd',
                'width' => 100,
                'height' => 80,
                'crop' => 50,
                'min_width' => [1, 2],
            ],
            'define_derivative min_width must be scalar'
        );
    }

    public function testMinWidthGreaterThanWidthIsFatal(): void
    {
        $this->callExpectingFatal(
            [
                'name' => 'd',
                'width' => 100,
                'height' => 80,
                'crop' => 50,
                'min_width' => 200,
            ],
            'define_derivative invalid min_width'
        );
    }

    public function testCropWithAnExplicitMinHeightIsUsedVerbatim(): void
    {
        $this->template->funcDefineDerivative(
            [
                'name' => 'd',
                'width' => 100,
                'height' => 80,
                'crop' => 50,
                'min_height' => 50,
            ],
            $this->template->smarty
        );

        $derivative = $this->template->getTemplateVars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame([100, 50], $derivative->sizing->min_size);
    }

    public function testNonScalarMinHeightIsFatal(): void
    {
        // See test_non_scalar_min_width_is_fatal()'s own comment: an empty
        // array is an "absent" sentinel, not a non-scalar value that
        // reaches the scalar check.
        $this->callExpectingFatal(
            [
                'name' => 'd',
                'width' => 100,
                'height' => 80,
                'crop' => 50,
                'min_height' => [1, 2],
            ],
            'define_derivative min_height must be scalar'
        );
    }

    public function testMinHeightGreaterThanHeightIsFatal(): void
    {
        $this->callExpectingFatal(
            [
                'name' => 'd',
                'width' => 100,
                'height' => 80,
                'crop' => 50,
                'min_height' => 200,
            ],
            'define_derivative invalid min_height'
        );
    }
}
