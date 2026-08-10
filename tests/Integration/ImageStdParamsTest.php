<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use ReflectionProperty;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
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

    private ImageStdParams $imageStdParams;

    /**
     * @var list<array<string, mixed>>
     */
    private array $originalSettingsRows;

    /**
     * @var list<array<string, mixed>>
     */
    private array $originalSizeRows;

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

        $this->conn = DbConnection::build();

        $this->originalSettingsRows = $this->conn->fetchAllAssociative('SELECT * FROM ' . 'derivative_settings');
        $this->originalSizeRows = $this->conn->fetchAllAssociative('SELECT * FROM ' . 'derivative_size');

        $this->imageStdParams = new ImageStdParams();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_settings');
        foreach ($this->originalSettingsRows as $row) {
            $this->conn->insert('derivative_settings', $row);
        }

        $this->conn->executeStatement('DELETE FROM ' . 'derivative_size');
        foreach ($this->originalSizeRows as $row) {
            $this->conn->insert('derivative_size', $row);
        }

        parent::tearDown();
    }

    public function test_load_from_db_falls_back_to_enabled_defaults_and_a_fresh_watermark_when_no_rows_exist(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_settings');
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_size');

        $this->imageStdParams->load_from_db();

        self::assertSame('', $this->imageStdParams->get_watermark()->file);

        $enabledKeys = array_keys(ImageStdParams::get_enabled_default_sizes());
        self::assertSame($enabledKeys, array_keys($this->imageStdParams->get_defined_type_map()));

        $disabledKeys = array_keys(ImageStdParams::get_disabled_default_sizes());
        self::assertSame(['3xlarge', '4xlarge'], $disabledKeys);
        $disabledTypeMap = $this->imageStdParams->get_disabled_type_map();
        self::assertSame($disabledKeys, array_keys($disabledTypeMap));

        // build_maps() maps each of the 2 disabled-by-default types to the
        // nearest smaller *defined* type -- both 3xlarge and 4xlarge fall
        // back to 'xxlarge' (the largest enabled-by-default size).
        self::assertSame(['3xlarge' => 'xxlarge', '4xlarge' => 'xxlarge'], $this->imageStdParams->get_undefined_type_map());
        $allTypeMap = $this->imageStdParams->get_all_type_map();
        self::assertCount(11, $allTypeMap);
        self::assertSame($allTypeMap['xxlarge'], $allTypeMap['3xlarge']);
        self::assertSame($allTypeMap['xxlarge'], $allTypeMap['4xlarge']);

        $settingsRowCount = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . 'derivative_settings');
        self::assertSame(1, is_numeric($settingsRowCount) ? (int) $settingsRowCount : -1);
        $sizeRowCount = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . 'derivative_size');
        self::assertSame(11, is_numeric($sizeRowCount) ? (int) $sizeRowCount : -1);
    }

    public function test_load_from_db_reads_a_real_settings_row_and_filters_malformed_custom_json_entries(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_settings');
        $this->conn->insert('derivative_settings', [
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

        $this->conn->executeStatement('DELETE FROM ' . 'derivative_size');
        $thumb = new DerivativeParams(SizingParams::classic(100, 100));
        $this->conn->insert('derivative_size', [
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
        $this->conn->insert('derivative_size', [
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

        $this->imageStdParams->load_from_db();

        self::assertSame('', $this->imageStdParams->get_watermark()->file);
        self::assertSame(['my_custom_key' => 1_700_000_000], $this->imageStdParams->get_custom_timestamps());
        self::assertSame(90, $this->imageStdParams->get_quality());

        $defined = $this->imageStdParams->get_defined_type_map();
        self::assertSame(['thumb'], array_keys($defined));
        self::assertSame([100, 100], $defined['thumb']->sizing->ideal_size);
        self::assertSame('thumb', $defined['thumb']->type);

        $disabledTypeMap = $this->imageStdParams->get_disabled_type_map();
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
        new ReflectionProperty(ImageStdParams::class, 'disabled_type_map')
            ->setValue($this->imageStdParams, ImageStdParams::get_disabled_default_sizes());

        $watermark = new WatermarkParams();
        $watermark->file = 'watermark.png';
        $watermark->min_size = [80, 80];
        $this->imageStdParams->set_watermark($watermark);

        $large = $this->imageStdParams->get_custom(600, 400, 0.3, 200, 150);

        self::assertSame([600, 400], $large->sizing->ideal_size);
        self::assertSame(0.3, $large->sizing->max_crop);
        self::assertSame([200, 150], $large->sizing->min_size);
        self::assertSame(ImageStdParams::CUSTOM, $large->type);
        // apply_global() compares the watermark's min_size against this
        // type's own ideal_size (600x400): 80<=600, so watermarking applies.
        self::assertTrue($large->use_watermark);
        self::assertCount(1, $this->imageStdParams->get_custom_timestamps());
        $firstTimestamps = $this->imageStdParams->get_custom_timestamps();
        foreach ($firstTimestamps as $timestamp) {
            self::assertGreaterThanOrEqual(time() - 5, $timestamp);
            self::assertLessThanOrEqual(time() + 5, $timestamp);
        }

        $small = $this->imageStdParams->get_custom(50, 40);

        self::assertSame([50, 40], $small->sizing->ideal_size);
        self::assertSame(0, $small->sizing->max_crop);
        self::assertNull($small->sizing->min_size);
        // 80<=50 and 80<=40 are both false -- no watermarking for this
        // much smaller size, distinct from $large's own true above.
        self::assertFalse($small->use_watermark);
        self::assertCount(2, $this->imageStdParams->get_custom_timestamps());

        // The first size's own recorded key/timestamp is untouched by the
        // second get_custom() call -- confirms the per-key freshness
        // check, not just "a" write happening.
        $customTimestamps = $this->imageStdParams->get_custom_timestamps();
        foreach ($firstTimestamps as $key => $timestamp) {
            self::assertSame($timestamp, $customTimestamps[$key]);
        }

        $savedCustomJson = $this->conn->fetchOne(
            'SELECT custom_json FROM ' . 'derivative_settings' . ' WHERE id = 1'
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
        $this->imageStdParams->set_watermark($watermark);
        $this->imageStdParams->set_quality(82);

        $params = new DerivativeParams(new SizingParams([500, 400], 0.5, [200, 150]));
        $params->sharpen = 0.25;
        $params->last_mod_time = 1_800_000_000;

        $this->imageStdParams->set_and_save(['medium' => $params]);
        $this->imageStdParams->set_and_save_disabled([]);

        // Checked here, immediately after set_and_save_disabled([]) and
        // before the reload below -- load_from_db()'s own "disabled map
        // came back empty -> reseed the 2 defaults" fallback (preserved
        // faithfully from the original blob-based behavior) would
        // otherwise mask a real deletion bug by refilling the table right
        // back up before this test could observe the empty state.
        $sizeRowCountAfterClear = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . 'derivative_size' . ' WHERE enabled = 0');
        self::assertSame(0, is_numeric($sizeRowCountAfterClear) ? (int) $sizeRowCountAfterClear : -1, 'set_and_save_disabled([]) must delete every disabled row, not leave stale ones behind.');

        // A fresh instance -- reload() must derive everything from the DB
        // rows just saved above, not from any in-memory state left behind
        // by the writes above.
        $this->imageStdParams = new ImageStdParams();
        $this->imageStdParams->load_from_db();

        self::assertSame('my-watermark.png', $this->imageStdParams->get_watermark()->file);
        self::assertSame([300, 250], $this->imageStdParams->get_watermark()->min_size);
        self::assertSame(10, $this->imageStdParams->get_watermark()->xpos);
        self::assertSame(90, $this->imageStdParams->get_watermark()->ypos);
        self::assertSame(2, $this->imageStdParams->get_watermark()->xrepeat);
        self::assertSame(3, $this->imageStdParams->get_watermark()->yrepeat);
        self::assertSame(50, $this->imageStdParams->get_watermark()->opacity);
        self::assertSame(82, $this->imageStdParams->get_quality());

        $reloaded = $this->imageStdParams->get_defined_type_map()['medium'];
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
        self::assertSame(['3xlarge', '4xlarge'], array_keys($this->imageStdParams->get_disabled_type_map()));
    }

    public function test_enabling_a_previously_disabled_size_moves_it_in_place_rather_than_duplicating_the_row(): void
    {
        $params = new DerivativeParams(SizingParams::classic(2232, 1674));
        $this->imageStdParams->set_and_save_disabled(['3xlarge' => $params]);

        self::assertSame(['3xlarge'], array_keys($this->imageStdParams->get_disabled_type_map()));

        $this->imageStdParams->set_and_save(['3xlarge' => $params]);
        $this->imageStdParams->set_and_save_disabled([]);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT enabled FROM ' . 'derivative_size' . " WHERE name = '3xlarge'"
        );
        self::assertCount(1, $rows, '3xlarge must have exactly one row after moving from disabled to enabled, not two.');
        $enabledValue = $rows[0]['enabled'];
        self::assertSame(1, is_numeric($enabledValue) ? (int) $enabledValue : -1);
    }

    public function test_watermark_from_json_rejects_a_non_array_min_size_and_a_pair_with_one_non_numeric_element(): void
    {
        // WatermarkParams::$min_size defaults to [500, 500] --
        // watermarkFromJson()'s own guard must reject anything that isn't a
        // genuine 2-element numeric array and leave that default intact,
        // rather than coercing a malformed value into a bogus min_size.
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_settings');
        $this->conn->insert('derivative_settings', [
            'id' => 1,
            'default_quality' => 95,
            // A 2-character string: is_array() is false, but (via PHP's
            // string-offset access) isset($minSize[0], $minSize[1]) and
            // both is_numeric() checks would still pass on '1'/'2' --
            // exercises the is_array() conjunct specifically.
            'watermark_json' => json_encode(['min_size' => '12']),
            'custom_json' => '{}',
        ]);

        $this->imageStdParams->load_from_db();

        self::assertSame([500, 500], $this->imageStdParams->get_watermark()->min_size);

        // A genuine 2-element array where the first element is numeric but
        // the second isn't -- exercises the trailing is_numeric($minSize[1])
        // conjunct specifically (is_array()/isset() both pass here).
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_settings');
        $this->conn->insert('derivative_settings', [
            'id' => 1,
            'default_quality' => 95,
            'watermark_json' => json_encode(['min_size' => [300, 'abc']]),
            'custom_json' => '{}',
        ]);

        $this->imageStdParams->load_from_db();

        self::assertSame([500, 500], $this->imageStdParams->get_watermark()->min_size);
    }

    public function test_watermark_from_json_casts_numeric_string_json_values_to_int(): void
    {
        // watermarkToJson()/set_watermark() always produce/consume real PHP
        // ints, so a save()/load_from_db() round trip through this class's
        // own API never exercises these (int) casts -- only a hand-edited
        // or externally-written row (Doctrine's `json` column type only
        // guarantees valid JSON, not that every value already has the
        // right PHP type) can contain numeric strings here.
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_settings');
        $this->conn->insert('derivative_settings', [
            'id' => 1,
            'default_quality' => 95,
            'watermark_json' => json_encode([
                'min_size' => ['300', '250'],
                'xpos' => '77',
                'xrepeat' => '4',
                'yrepeat' => '6',
            ]),
            'custom_json' => '{}',
        ]);

        $this->imageStdParams->load_from_db();

        self::assertSame([300, 250], $this->imageStdParams->get_watermark()->min_size);
        self::assertSame(77, $this->imageStdParams->get_watermark()->xpos);
        self::assertSame(4, $this->imageStdParams->get_watermark()->xrepeat);
        self::assertSame(6, $this->imageStdParams->get_watermark()->yrepeat);
    }

    public function test_sizes_from_entities_treats_a_size_with_only_one_of_min_width_or_min_height_set_as_having_no_min_size(): void
    {
        // SizingParams::min_size is only ever meaningful as a genuine
        // 2-element pair (see its own constructor docblock) --
        // sizesFromEntities() must require BOTH minWidth and minHeight
        // non-null, not treat "either one set" as enough to build a
        // malformed 1-real/1-null pair.
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_size');
        $this->conn->insert('derivative_size', [
            'name' => 'thumb',
            'enabled' => 1,
            'max_width' => 200,
            'max_height' => 150,
            'max_crop' => '0.5000',
            'min_width' => 80,
            'min_height' => null,
            'sharpen' => '0.0000',
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
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

        $this->imageStdParams->load_from_db();

        self::assertNull($this->imageStdParams->get_defined_type_map()['thumb']->sizing->min_size);
    }

    public function test_sizes_from_entities_orders_canonical_types_first_then_appends_any_unrecognized_names(): void
    {
        // DerivativeSizeRepository::findAllEnabled() has no ORDER BY (name
        // is the PK, so rows come back alphabetically) -- sizesFromEntities()
        // must re-sort into the canonical square/thumb/.../4xlarge order
        // (see this method's own comment), not just leave the DB's
        // alphabetical order in place. 'medium' sorts before 'xsmall'
        // alphabetically but after it in ImageStdParams::ALL_TYPES, so this
        // pair alone is enough to distinguish the two orders. 'bogus_type'
        // -- a name outside ImageStdParams::ALL_TYPES, something the DB
        // schema has no CHECK constraint to prevent -- must still surface
        // via the canonical loop's own fallback rather than being silently
        // dropped.
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_size');
        $this->conn->insert('derivative_size', [
            'name' => 'medium',
            'enabled' => 1,
            'max_width' => 792,
            'max_height' => 594,
            'max_crop' => '0.0000',
            'min_width' => null,
            'min_height' => null,
            'sharpen' => '0.0000',
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
            'name' => 'xsmall',
            'enabled' => 1,
            'max_width' => 432,
            'max_height' => 324,
            'max_crop' => '0.0000',
            'min_width' => null,
            'min_height' => null,
            'sharpen' => '0.0000',
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
            'name' => 'bogus_type',
            'enabled' => 1,
            'max_width' => 10,
            'max_height' => 10,
            'max_crop' => '0.0000',
            'min_width' => null,
            'min_height' => null,
            'sharpen' => '0.0000',
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
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

        $this->imageStdParams->load_from_db();

        self::assertSame(['xsmall', 'medium', 'bogus_type'], array_keys($this->imageStdParams->get_defined_type_map()));
    }

    public function test_apply_global_never_enables_watermarking_when_the_watermark_file_is_empty(): void
    {
        // A fresh instance's $watermark stays null until set_watermark()/
        // load_from_db() populates it; apply_global() lazily defaults it to
        // a fresh WatermarkParams() whose file stays ''. That empty-file
        // check must short-circuit the whole expression, even for a size
        // whose ideal_size is large enough that the default min_size
        // ([500, 500]) would otherwise satisfy the size comparison below.
        $params = new DerivativeParams(SizingParams::classic(999, 999));

        $this->imageStdParams->apply_global($params);

        self::assertFalse($params->use_watermark);
    }

    public function test_apply_global_compares_each_axis_of_the_watermarks_min_size_against_the_matching_axis_of_the_sizes_ideal_size(): void
    {
        $watermark = new WatermarkParams();
        $watermark->file = 'w.png';

        // Isolates the width (index 0) comparison: min_size[0] equals
        // ideal_size[0] exactly (80<=80 is true), while the height (index 1)
        // pair is unambiguously false (9000<=1 isn't) -- so use_watermark
        // can only end up true here via the width comparison. The extra
        // -1/1 entries are deliberately chosen so that reading the wrong
        // array index, using '<'/'>' instead of '<=', or turning the "or"
        // into an "and" would each flip the result.
        $watermark->min_size = [-1 => 9999, 0 => 80, 1 => 9000];
        $this->imageStdParams->set_watermark($watermark);
        $params = new DerivativeParams(new SizingParams([-1 => 1, 0 => 80, 1 => 1]));

        $this->imageStdParams->apply_global($params);

        self::assertTrue($params->use_watermark);

        // Mirrors the above, isolating the height (index 1) comparison
        // instead: 80<=80 is true there, while the width (index 0) pair is
        // unambiguously false (9000<=1 isn't).
        $watermark->min_size = [0 => 9000, 1 => 80, 2 => 9999];
        $this->imageStdParams->set_watermark($watermark);
        $params2 = new DerivativeParams(new SizingParams([0 => 1, 1 => 80, 2 => 1]));

        $this->imageStdParams->apply_global($params2);

        self::assertTrue($params2->use_watermark);
    }

    public function test_build_maps_applies_the_watermark_to_every_defined_size(): void
    {
        $watermark = new WatermarkParams();
        $watermark->file = 'w.png';
        $watermark->min_size = [10, 10];
        $this->imageStdParams->set_watermark($watermark);

        $params = new DerivativeParams(SizingParams::classic(200, 200));
        $this->imageStdParams->set_and_save(['thumb' => $params]);

        $defined = $this->imageStdParams->get_defined_type_map();
        self::assertSame('thumb', $defined['thumb']->type);
        self::assertTrue($defined['thumb']->use_watermark);
    }

    public function test_build_maps_backfills_from_the_smallest_defined_type_all_the_way_down_to_index_zero(): void
    {
        // Only 'square' (index 0 in ImageStdParams::ALL_TYPES, the very
        // smallest type) is defined -- every other type must fall back to
        // it. build_maps()'s own inner search loop starts at $i - 1 and
        // walks down to (and including) index 0; if that lower bound were
        // ever off by one, 'square' -- sitting at the boundary itself --
        // would never be found, and every other type would stay entirely
        // undefined.
        $square = new DerivativeParams(SizingParams::square(120));
        $this->imageStdParams->set_and_save(['square' => $square]);

        $allTypeMap = $this->imageStdParams->get_all_type_map();
        self::assertCount(11, $allTypeMap);

        $expectedUndefined = array_fill_keys(
            ['thumb', '2small', 'xsmall', 'small', 'medium', 'large', 'xlarge', 'xxlarge', '3xlarge', '4xlarge'],
            'square',
        );
        self::assertSame($expectedUndefined, $this->imageStdParams->get_undefined_type_map());
        self::assertSame($allTypeMap['square'], $allTypeMap['thumb']);
        self::assertSame($allTypeMap['square'], $allTypeMap['4xlarge']);
    }

    public function test_build_maps_never_treats_an_out_of_bounds_negative_index_as_a_valid_fallback(): void
    {
        // ALL_TYPES is a plain 0..10-indexed array; build_maps()'s own inner
        // search loop must stop once it reaches index 0 (the smallest real
        // type) rather than also reading self::ALL_TYPES[-1] (undefined ->
        // null, which PHP then coerces to the empty-string array key '').
        // A row named '' is nothing the application itself ever writes, but
        // the schema has no CHECK constraint against it (see
        // DerivativeSizeEntity's own docblock) -- planting one directly is
        // the only way to observe whether that extra, out-of-bounds
        // iteration would wrongly treat it as a valid fallback source.
        $this->conn->executeStatement('DELETE FROM ' . 'derivative_size');
        $this->conn->insert('derivative_size', [
            'name' => '',
            'enabled' => 1,
            'max_width' => 999,
            'max_height' => 999,
            'max_crop' => '0.0000',
            'min_width' => null,
            'min_height' => null,
            'sharpen' => '0.0000',
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
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

        $this->imageStdParams->load_from_db();

        // None of ImageStdParams::ALL_TYPES is defined (only the '' row
        // is) -- every real type must stay genuinely undefined, not
        // silently backfilled from the out-of-bounds '' entry.
        self::assertSame([], $this->imageStdParams->get_undefined_type_map());
        self::assertCount(1, $this->imageStdParams->get_all_type_map());
    }
}
