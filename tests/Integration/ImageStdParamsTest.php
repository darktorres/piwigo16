<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Image\WatermarkParams;

/**
 * Piwigo\Image\ImageStdParams had zero dedicated test file. Every other
 * Integration suite that calls load_from_db() (CalendarMonthlyTest,
 * CategoryDefaultRendererTest, NotificationByMailSenderTest,
 * PwgTemplateAdapterTest) deliberately keeps the fixture's own real
 * `derivatives`/`disabled_derivatives` config rows valid so load_from_db()
 * never needs a live CurrentConfigService -- which is exactly why its own
 * "missing/invalid config" fallback branches, and get_custom()'s real
 * DB-writing save() path, stayed uncovered. This file needs real DB/DI
 * (ConfigService/ConfigRepository), hence Integration rather than Unit.
 *
 * ImageStdParams's own private statics ($type_map/$all_type_map/
 * $disabled_type_map/$undefined_type_map/$watermark) persist for this
 * whole (Pest, one-process) test run once any test file populates them --
 * reflection resets them to a clean slate in setUp()/tearDown() so this
 * file's own assertions don't depend on suite run order, same convention
 * as FilesystemHelperTest's reflection-seeded static setter.
 *
 * The real `derivatives`/`disabled_derivatives` config rows are shared,
 * mutable fixture state every one of those other suites also reads --
 * this file snapshots their raw DB value in setUp() and restores it
 * verbatim in tearDown(), the same restore-in-teardown convention
 * LoungeMaintenanceTest uses for its own shared-row mutation
 * (`date_available`).
 */
final class ImageStdParamsTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private ?string $originalDerivativesValue;

    private ?string $originalDisabledDerivativesValue;

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

        $this->conn = DbConnection::build();

        $derivatives = $this->conn->fetchOne('SELECT value FROM ' . Tables::config() . " WHERE param = 'derivatives'");
        $this->originalDerivativesValue = is_string($derivatives) ? $derivatives : null;
        $disabled = $this->conn->fetchOne('SELECT value FROM ' . Tables::config() . " WHERE param = 'disabled_derivatives'");
        $this->originalDisabledDerivativesValue = is_string($disabled) ? $disabled : null;

        CurrentConfig::reset();
        $this->resetImageStdParamsStatics();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . Tables::config() . ' SET value = ? WHERE param = ?',
            [$this->originalDerivativesValue, 'derivatives']
        );
        $this->conn->executeStatement(
            'UPDATE ' . Tables::config() . ' SET value = ? WHERE param = ?',
            [$this->originalDisabledDerivativesValue, 'disabled_derivatives']
        );
        \Piwigo\Cache\CachePools::config()->clear();
        $this->resetImageStdParamsStatics();
        parent::tearDown();
    }

    private function resetImageStdParamsStatics(): void
    {
        new \ReflectionProperty(ImageStdParams::class, 'type_map')->setValue(null, []);
        new \ReflectionProperty(ImageStdParams::class, 'all_type_map')->setValue(null, []);
        new \ReflectionProperty(ImageStdParams::class, 'disabled_type_map')->setValue(null, []);
        new \ReflectionProperty(ImageStdParams::class, 'undefined_type_map')->setValue(null, []);
        new \ReflectionProperty(ImageStdParams::class, 'watermark')->setValue(null, null);
        ImageStdParams::$custom = [];
        ImageStdParams::$quality = 95;
    }

    public function test_load_from_db_falls_back_to_enabled_defaults_and_a_fresh_watermark_when_no_derivatives_row_is_readable(): void
    {
        CurrentConfig::setDerivatives(null);
        CurrentConfig::setDisabledDerivatives([]);
        CurrentConfigService::set(new ConfigService($this->buildConfigRepository()));

        ImageStdParams::load_from_db();

        self::assertSame('', ImageStdParams::get_watermark()->file);

        $enabledKeys = array_keys(ImageStdParams::get_enabled_default_sizes());
        self::assertSame($enabledKeys, array_keys(ImageStdParams::get_defined_type_map()));

        $disabledKeys = array_keys(ImageStdParams::get_disabled_default_sizes());
        self::assertSame(['3xlarge', '4xlarge'], $disabledKeys);
        $disabledTypeMap = ImageStdParams::get_disabled_type_map();
        self::assertIsArray($disabledTypeMap);
        self::assertSame($disabledKeys, array_keys($disabledTypeMap));

        // build_maps() maps each of the 2 disabled-by-default types to the
        // nearest smaller *defined* type -- both 3xlarge and 4xlarge fall
        // back to 'xxlarge' (the largest enabled-by-default size).
        self::assertSame(['3xlarge' => 'xxlarge', '4xlarge' => 'xxlarge'], ImageStdParams::get_undefined_type_map());
        $allTypeMap = ImageStdParams::get_all_type_map();
        self::assertCount(11, $allTypeMap);
        self::assertSame($allTypeMap['xxlarge'], $allTypeMap['3xlarge']);
        self::assertSame($allTypeMap['xxlarge'], $allTypeMap['4xlarge']);

        $savedDerivatives = $this->conn->fetchOne('SELECT value FROM ' . Tables::config() . " WHERE param = 'derivatives'");
        self::assertIsString($savedDerivatives);
        self::assertNotSame('', $savedDerivatives);
        $savedDisabled = $this->conn->fetchOne('SELECT value FROM ' . Tables::config() . " WHERE param = 'disabled_derivatives'");
        self::assertIsString($savedDisabled);
        self::assertNotSame('', $savedDisabled);
    }

    public function test_load_from_db_defaults_a_fresh_watermark_and_filters_custom_key_entries_from_a_real_blob(): void
    {
        $thumb = new DerivativeParams(SizingParams::classic(100, 100));

        $blob = serialize([
            'd' => ['thumb' => $thumb],
            'q' => 90,
            // Deliberately no 'w' key -- exercises the ternary's default
            // branch (a fresh WatermarkParams()).
            'c' => [
                'my_custom_key' => 1_700_000_000,
                // Invalid entries the parse loop must filter out: an
                // int key (is_string() fails) and a non-numeric value
                // (is_numeric() fails).
                0 => 5,
                'not_numeric' => 'nope',
            ],
        ]);
        CurrentConfig::setDerivatives($blob);
        // Non-empty so load_from_db()'s own "disabled map is empty ->
        // rebuild defaults" branch (covered by the previous test) isn't
        // exercised again here -- this test stays focused on the 'd'/'w'/
        // 'c' parsing above, and never touches CurrentConfigService since
        // neither save() path is taken.
        CurrentConfig::setDisabledDerivatives(serialize([
            '3xlarge' => new DerivativeParams(SizingParams::classic(2232, 1674)),
        ]));

        ImageStdParams::load_from_db();

        self::assertSame('', ImageStdParams::get_watermark()->file);
        self::assertSame(['my_custom_key' => 1_700_000_000], ImageStdParams::$custom);
        self::assertSame(90, ImageStdParams::$quality);

        $defined = ImageStdParams::get_defined_type_map();
        self::assertSame(['thumb'], array_keys($defined));
        self::assertSame([100, 100], $defined['thumb']->sizing->ideal_size);
        self::assertSame('thumb', $defined['thumb']->type);

        $disabledTypeMap = ImageStdParams::get_disabled_type_map();
        self::assertIsArray($disabledTypeMap);
        self::assertSame(['3xlarge'], array_keys($disabledTypeMap));
    }

    public function test_get_custom_returns_a_derivative_params_matching_the_given_size_and_records_a_fresh_custom_key_only_once_per_size(): void
    {
        CurrentConfigService::set(new ConfigService($this->buildConfigRepository()));
        // Seeded non-empty so get_custom()'s own save() -> save_disabled()
        // upserts the disabled_derivatives row instead of deleting it
        // (save_disabled() deletes the row when the in-memory map is
        // empty) -- this test's own tearDown() restores the real fixture
        // row regardless, but an upsert (rather than a delete followed by
        // a same-test re-read) keeps this test's own assertions simple.
        new \ReflectionProperty(ImageStdParams::class, 'disabled_type_map')
            ->setValue(null, ImageStdParams::get_disabled_default_sizes());

        $watermark = new WatermarkParams();
        $watermark->file = 'watermark.png';
        $watermark->min_size = [80, 80];
        ImageStdParams::set_watermark($watermark);

        $large = ImageStdParams::get_custom(600, 400, 0.3, 200, 150);

        self::assertSame([600, 400], $large->sizing->ideal_size);
        self::assertSame(0.3, $large->sizing->max_crop);
        self::assertSame([200, 150], $large->sizing->min_size);
        self::assertSame(ImageStdParams::CUSTOM, $large->type);
        // apply_global() compares the watermark's min_size against this
        // type's own ideal_size (600x400): 80<=600, so watermarking applies.
        self::assertTrue($large->use_watermark);
        self::assertCount(1, ImageStdParams::$custom);
        $firstTimestamps = ImageStdParams::$custom;
        foreach ($firstTimestamps as $timestamp) {
            self::assertGreaterThanOrEqual(time() - 5, $timestamp);
            self::assertLessThanOrEqual(time() + 5, $timestamp);
        }

        $small = ImageStdParams::get_custom(50, 40);

        self::assertSame([50, 40], $small->sizing->ideal_size);
        self::assertSame(0, $small->sizing->max_crop);
        self::assertNull($small->sizing->min_size);
        // 80<=50 and 80<=40 are both false -- no watermarking for this
        // much smaller size, distinct from $large's own true above.
        self::assertFalse($small->use_watermark);
        self::assertCount(2, ImageStdParams::$custom);

        // The first size's own recorded key/timestamp is untouched by the
        // second get_custom() call -- confirms the per-key freshness
        // check, not just "a" write happening.
        foreach ($firstTimestamps as $key => $timestamp) {
            self::assertSame($timestamp, ImageStdParams::$custom[$key]);
        }

        // 'derivatives'/'disabled_derivatives' are ConfigService's own
        // OBJECT_SERIALIZED_PARAMS -- stored via plain serialize(), not
        // json_encode() like every other config value (see
        // ConfigService::encode()'s own special case).
        $savedRaw = $this->conn->fetchOne('SELECT value FROM ' . Tables::config() . " WHERE param = 'derivatives'");
        self::assertIsString($savedRaw);
        $decoded = unserialize($savedRaw);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['c']);
        self::assertCount(2, $decoded['c']);
    }
}
