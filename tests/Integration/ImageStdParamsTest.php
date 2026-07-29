<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
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
 * `derivative_settings`/`derivative_size` rows valid so load_from_db()
 * never needs to exercise its own "missing/invalid row" fallback branches.
 * This file needs real DB access (DerivativeSettingsRepository/
 * DerivativeSizeRepository), hence Integration rather than Unit.
 *
 * ImageStdParams's own private statics ($type_map/$all_type_map/
 * $disabled_type_map/$undefined_type_map/$watermark) persist for this
 * whole (Pest, one-process) test run once any test file populates them --
 * reflection resets them to a clean slate in setUp()/tearDown() so this
 * file's own assertions don't depend on suite run order, same convention
 * as FilesystemHelperTest's reflection-seeded static setter.
 *
 * The real `derivative_settings`/`derivative_size` rows are shared,
 * mutable fixture state every one of those other suites also reads --
 * this file snapshots every row from both tables in setUp() and restores
 * them verbatim in tearDown(), the same restore-in-teardown convention
 * LoungeMaintenanceTest uses for its own shared-row mutation
 * (`date_available`).
 *
 * ImageStdParams reaches its repositories via a fresh
 * EntityManagerFactory::build(DbConnection::build()) per call, not the
 * container-shared Bootstrap\InfrastructureAccessor -- so unlike
 * UploadServiceTest's own ImageStdParams usage, this file never needs
 * Kernel::boot().
 */
final class ImageStdParamsTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    /**
     * @var list<array<string, mixed>>
     */
    private array $originalSettingsRows;

    /**
     * @var list<array<string, mixed>>
     */
    private array $originalSizeRows;

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

        $this->originalSettingsRows = $this->conn->fetchAllAssociative('SELECT * FROM ' . Tables::derivativeSettings());
        $this->originalSizeRows = $this->conn->fetchAllAssociative('SELECT * FROM ' . Tables::derivativeSize());

        $this->resetImageStdParamsStatics();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSettings());
        foreach ($this->originalSettingsRows as $row) {
            $this->conn->insert(Tables::derivativeSettings(), $row);
        }

        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSize());
        foreach ($this->originalSizeRows as $row) {
            $this->conn->insert(Tables::derivativeSize(), $row);
        }

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

    public function test_load_from_db_falls_back_to_enabled_defaults_and_a_fresh_watermark_when_no_rows_exist(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSettings());
        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSize());

        ImageStdParams::load_from_db();

        self::assertSame('', ImageStdParams::get_watermark()->file);

        $enabledKeys = array_keys(ImageStdParams::get_enabled_default_sizes());
        self::assertSame($enabledKeys, array_keys(ImageStdParams::get_defined_type_map()));

        $disabledKeys = array_keys(ImageStdParams::get_disabled_default_sizes());
        self::assertSame(['3xlarge', '4xlarge'], $disabledKeys);
        $disabledTypeMap = ImageStdParams::get_disabled_type_map();
        self::assertSame($disabledKeys, array_keys($disabledTypeMap));

        // build_maps() maps each of the 2 disabled-by-default types to the
        // nearest smaller *defined* type -- both 3xlarge and 4xlarge fall
        // back to 'xxlarge' (the largest enabled-by-default size).
        self::assertSame(['3xlarge' => 'xxlarge', '4xlarge' => 'xxlarge'], ImageStdParams::get_undefined_type_map());
        $allTypeMap = ImageStdParams::get_all_type_map();
        self::assertCount(11, $allTypeMap);
        self::assertSame($allTypeMap['xxlarge'], $allTypeMap['3xlarge']);
        self::assertSame($allTypeMap['xxlarge'], $allTypeMap['4xlarge']);

        $settingsRowCount = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::derivativeSettings());
        self::assertSame(1, is_numeric($settingsRowCount) ? (int) $settingsRowCount : -1);
        $sizeRowCount = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::derivativeSize());
        self::assertSame(11, is_numeric($sizeRowCount) ? (int) $sizeRowCount : -1);
    }

    public function test_load_from_db_reads_a_real_settings_row_and_filters_malformed_custom_json_entries(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSettings());
        $this->conn->insert(Tables::derivativeSettings(), [
            'id' => 1,
            'default_quality' => 90,
            // Deliberately no 'file' key inside watermark_json -- exercises
            // watermarkFromJson()'s own default-value fallback (a fresh
            // WatermarkParams()'s file stays '').
            'watermark_json' => '{}',
            // Invalid entries the parse loop must filter out: a
            // numeric-string-looking key that PHP coerces to an int array
            // key ("0" -> is_string() fails after json_decode()) and a
            // non-numeric value (is_numeric() fails).
            'custom_json' => json_encode([
                'my_custom_key' => 1_700_000_000,
                '0' => 5,
                'not_numeric' => 'nope',
            ]),
        ]);

        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSize());
        $thumb = new DerivativeParams(SizingParams::classic(100, 100));
        $this->conn->insert(Tables::derivativeSize(), [
            'name' => 'thumb',
            'enabled' => 1,
            'max_width' => 100,
            'max_height' => 100,
            'max_crop' => '0.0000',
            'min_width' => null,
            'min_height' => null,
            'sharpen' => '0.0000',
            'last_mod_time' => $thumb->last_mod_time,
        ]);
        // Non-empty so load_from_db()'s own "disabled map is empty ->
        // rebuild defaults" branch (covered by the previous test) isn't
        // exercised again here -- this test stays focused on settings/size
        // row parsing above.
        $this->conn->insert(Tables::derivativeSize(), [
            'name' => '3xlarge',
            'enabled' => 0,
            'max_width' => 2232,
            'max_height' => 1674,
            'max_crop' => '0.0000',
            'min_width' => null,
            'min_height' => null,
            'sharpen' => '0.0000',
            'last_mod_time' => 0,
        ]);

        ImageStdParams::load_from_db();

        self::assertSame('', ImageStdParams::get_watermark()->file);
        self::assertSame(['my_custom_key' => 1_700_000_000], ImageStdParams::$custom);
        self::assertSame(90, ImageStdParams::$quality);

        $defined = ImageStdParams::get_defined_type_map();
        self::assertSame(['thumb'], array_keys($defined));
        self::assertSame([100, 100], $defined['thumb']->sizing->ideal_size);
        self::assertSame('thumb', $defined['thumb']->type);

        $disabledTypeMap = ImageStdParams::get_disabled_type_map();
        self::assertSame(['3xlarge'], array_keys($disabledTypeMap));
    }

    public function test_get_custom_returns_a_derivative_params_matching_the_given_size_and_records_a_fresh_custom_key_only_once_per_size(): void
    {
        // Seeded non-empty so get_custom()'s own save() -> syncDisabled()
        // upserts the derivative_size disabled rows instead of deleting
        // them all (syncDisabled([]) deletes every disabled row when the
        // in-memory map is empty) -- this test's own tearDown() restores
        // the real fixture rows regardless, but an upsert (rather than a
        // delete followed by a same-test re-read) keeps this test's own
        // assertions simple.
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

        $savedCustomJson = $this->conn->fetchOne(
            'SELECT custom_json FROM ' . Tables::derivativeSettings() . ' WHERE id = 1'
        );
        self::assertIsString($savedCustomJson);
        $decoded = json_decode($savedCustomJson, true);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
    }

    public function test_save_and_load_from_db_round_trip_every_field_of_a_size_and_the_watermark(): void
    {
        $watermark = new WatermarkParams();
        $watermark->file = 'my-watermark.png';
        $watermark->min_size = [300, 250];
        $watermark->xpos = 10;
        $watermark->ypos = 90;
        $watermark->xrepeat = 2;
        $watermark->yrepeat = 3;
        $watermark->opacity = 50;
        ImageStdParams::set_watermark($watermark);
        ImageStdParams::$quality = 82;

        $params = new DerivativeParams(new SizingParams([500, 400], 0.5, [200, 150]));
        $params->sharpen = 0.25;
        $params->last_mod_time = 1_800_000_000;

        ImageStdParams::set_and_save(['medium' => $params]);
        ImageStdParams::set_and_save_disabled([]);

        // Checked here, immediately after set_and_save_disabled([]) and
        // before the reload below -- load_from_db()'s own "disabled map
        // came back empty -> reseed the 2 defaults" fallback (preserved
        // faithfully from the original blob-based behavior) would
        // otherwise mask a real deletion bug by refilling the table right
        // back up before this test could observe the empty state.
        $sizeRowCountAfterClear = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::derivativeSize() . ' WHERE enabled = 0');
        self::assertSame(0, is_numeric($sizeRowCountAfterClear) ? (int) $sizeRowCountAfterClear : -1, 'set_and_save_disabled([]) must delete every disabled row, not leave stale ones behind.');

        $this->resetImageStdParamsStatics();
        ImageStdParams::load_from_db();

        self::assertSame('my-watermark.png', ImageStdParams::get_watermark()->file);
        self::assertSame([300, 250], ImageStdParams::get_watermark()->min_size);
        self::assertSame(10, ImageStdParams::get_watermark()->xpos);
        self::assertSame(90, ImageStdParams::get_watermark()->ypos);
        self::assertSame(2, ImageStdParams::get_watermark()->xrepeat);
        self::assertSame(3, ImageStdParams::get_watermark()->yrepeat);
        self::assertSame(50, ImageStdParams::get_watermark()->opacity);
        self::assertSame(82, ImageStdParams::$quality);

        $reloaded = ImageStdParams::get_defined_type_map()['medium'];
        self::assertSame([500, 400], $reloaded->sizing->ideal_size);
        self::assertSame(0.5, $reloaded->sizing->max_crop);
        self::assertSame([200, 150], $reloaded->sizing->min_size);
        self::assertSame(0.25, $reloaded->sharpen);
        self::assertSame(1_800_000_000, $reloaded->last_mod_time);

        // load_from_db()'s own reseed-on-empty fallback (see the earlier
        // assertion's own comment) means the disabled set is back to the
        // 2 defaults after this reload, not empty -- that fallback is
        // pre-existing, faithfully preserved behavior, not something this
        // round-trip test is about.
        self::assertSame(['3xlarge', '4xlarge'], array_keys(ImageStdParams::get_disabled_type_map()));
    }

    public function test_enabling_a_previously_disabled_size_moves_it_in_place_rather_than_duplicating_the_row(): void
    {
        $params = new DerivativeParams(SizingParams::classic(2232, 1674));
        ImageStdParams::set_and_save_disabled(['3xlarge' => $params]);

        self::assertSame(['3xlarge'], array_keys(ImageStdParams::get_disabled_type_map()));

        ImageStdParams::set_and_save(['3xlarge' => $params]);
        ImageStdParams::set_and_save_disabled([]);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT enabled FROM ' . Tables::derivativeSize() . " WHERE name = '3xlarge'"
        );
        self::assertCount(1, $rows, '3xlarge must have exactly one row after moving from disabled to enabled, not two.');
        $enabledValue = $rows[0]['enabled'];
        self::assertSame(1, is_numeric($enabledValue) ? (int) $enabledValue : -1);
    }
}
