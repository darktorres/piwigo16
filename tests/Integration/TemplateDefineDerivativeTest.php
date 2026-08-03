<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;

/**
 * Piwigo\Template\Template::func_define_derivative() -- the single
 * largest red-line cluster in this class (roughly a third of its own
 * total gap). Genuinely needs a real DB: ImageStdParams::get_custom()'s
 * own first-use-in-24h path calls ConfigService::confUpdateParam()
 * (confirmed live -- every custom w/h/crop combination this test uses is
 * new, so save() always fires), unlike every other Template instance
 * method covered by TemplateInstanceTest.php.
 */
final class TemplateDefineDerivativeTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Template $template;

    #[\Override]
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
        CurrentConfigService::set(new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher()));
        ImageStdParams::current()->load_from_db();
        CurrentUser::current()->attachGlobals();

        $this->template = new Template();
    }

    #[\Override]
    protected function tearDown(): void
    {
        CurrentUser::current()->reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function callExpectingFatal(array $params, string $expectedMessage): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $this->template->func_define_derivative($params, $this->template->smarty);
            self::fail('func_define_derivative() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            self::assertStringContainsString($expectedMessage, (string) $e->response()->getBody());
        } finally {
            restore_error_handler();
        }
    }

    public function test_missing_name_is_fatal(): void
    {
        $this->callExpectingFatal([], 'define_derivative missing name');
    }

    public function test_non_string_name_is_fatal(): void
    {
        $this->callExpectingFatal(['name' => 123], 'define_derivative missing name');
    }

    public function test_type_param_assigns_a_real_known_derivative(): void
    {
        $this->template->func_define_derivative(['name' => 'd', 'type' => 'thumb'], $this->template->smarty);

        $derivative = $this->template->get_template_vars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame('thumb', $derivative->type);
    }

    public function test_non_string_type_is_fatal(): void
    {
        $this->callExpectingFatal(['name' => 'd', 'type' => 123], 'define_derivative type must be a string');
    }

    public function test_missing_width_without_a_type_is_fatal(): void
    {
        $this->callExpectingFatal(['name' => 'd'], 'define_derivative missing width');
    }

    public function test_missing_height_is_fatal(): void
    {
        $this->callExpectingFatal(['name' => 'd', 'width' => 100], 'define_derivative missing height');
    }

    public function test_non_scalar_width_is_fatal(): void
    {
        $this->callExpectingFatal(['name' => 'd', 'width' => [], 'height' => 100], 'define_derivative missing width');
    }

    public function test_non_scalar_height_is_fatal(): void
    {
        $this->callExpectingFatal(['name' => 'd', 'width' => 100, 'height' => []], 'define_derivative missing height');
    }

    public function test_a_basic_width_and_height_builds_a_custom_derivative(): void
    {
        $this->template->func_define_derivative(['name' => 'd', 'width' => 100, 'height' => 80], $this->template->smarty);

        $derivative = $this->template->get_template_vars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame([100, 80], $derivative->sizing->ideal_size);
        self::assertSame(0, $derivative->sizing->max_crop);
        self::assertNull($derivative->sizing->min_size);
    }

    public function test_crop_as_a_true_boolean_defaults_min_size_to_the_full_width_and_height(): void
    {
        $this->template->func_define_derivative(['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => true], $this->template->smarty);

        $derivative = $this->template->get_template_vars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame(1, $derivative->sizing->max_crop);
        self::assertSame([100, 80], $derivative->sizing->min_size);
    }

    public function test_crop_as_a_false_boolean_leaves_crop_disabled(): void
    {
        $this->template->func_define_derivative(['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => false], $this->template->smarty);

        $derivative = $this->template->get_template_vars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame(0, $derivative->sizing->max_crop);
        self::assertNull($derivative->sizing->min_size);
    }

    public function test_crop_as_a_numeric_percentage_is_divided_by_100(): void
    {
        $this->template->func_define_derivative(['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => 50], $this->template->smarty);

        $derivative = $this->template->get_template_vars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame(0.5, $derivative->sizing->max_crop);
    }

    public function test_non_numeric_crop_is_fatal(): void
    {
        $this->callExpectingFatal(
            ['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => 'abc'],
            'define_derivative crop must be numeric'
        );
    }

    public function test_crop_with_an_explicit_min_width_is_used_verbatim(): void
    {
        $this->template->func_define_derivative(
            ['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => 50, 'min_width' => 50],
            $this->template->smarty
        );

        $derivative = $this->template->get_template_vars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame([50, 80], $derivative->sizing->min_size);
    }

    public function test_non_scalar_min_width_is_fatal(): void
    {
        // An empty array specifically is one of the "absent" sentinel
        // values (in_array(..., true) against [null, false, 0, '0', '', []])
        // -- confirmed live, that short-circuits before the scalar check
        // ever runs. A non-empty array is what actually reaches it.
        $this->callExpectingFatal(
            ['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => 50, 'min_width' => [1, 2]],
            'define_derivative min_width must be scalar'
        );
    }

    public function test_min_width_greater_than_width_is_fatal(): void
    {
        $this->callExpectingFatal(
            ['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => 50, 'min_width' => 200],
            'define_derivative invalid min_width'
        );
    }

    public function test_crop_with_an_explicit_min_height_is_used_verbatim(): void
    {
        $this->template->func_define_derivative(
            ['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => 50, 'min_height' => 50],
            $this->template->smarty
        );

        $derivative = $this->template->get_template_vars('d');
        self::assertInstanceOf(DerivativeParams::class, $derivative);
        self::assertSame([100, 50], $derivative->sizing->min_size);
    }

    public function test_non_scalar_min_height_is_fatal(): void
    {
        // See test_non_scalar_min_width_is_fatal()'s own comment: an empty
        // array is an "absent" sentinel, not a non-scalar value that
        // reaches the scalar check.
        $this->callExpectingFatal(
            ['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => 50, 'min_height' => [1, 2]],
            'define_derivative min_height must be scalar'
        );
    }

    public function test_min_height_greater_than_height_is_fatal(): void
    {
        $this->callExpectingFatal(
            ['name' => 'd', 'width' => 100, 'height' => 80, 'crop' => 50, 'min_height' => 200],
            'define_derivative invalid min_height'
        );
    }
}
