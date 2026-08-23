<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\Dimensions;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Image\WatermarkParams;
use ReflectionProperty;

/**
 * Piwigo\Image\ImageStdParams had zero dedicated test file. Every other
 * Integration suite that calls loadFromDb() (CalendarMonthlyTest,
 * CategoryDefaultRendererTest, NotificationByMailSenderTest,
 * TemplateAdapterTest) deliberately keeps the fixture's own real
 * `derivative_settings`/`derivative_size` rows valid so loadFromDb()
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

        $this->originalSettingsRows = $this->conn->fetchAllAssociative('SELECT * FROM derivative_settings');
        $this->originalSizeRows = $this->conn->fetchAllAssociative('SELECT * FROM derivative_size');

        $this->imageStdParams = new ImageStdParams();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM derivative_settings');
        foreach ($this->originalSettingsRows as $row) {
            $this->conn->insert('derivative_settings', $row);
        }

        $this->conn->executeStatement('DELETE FROM derivative_size');
        foreach ($this->originalSizeRows as $row) {
            $this->conn->insert('derivative_size', $row);
        }

        parent::tearDown();
    }

    public function testLoadFromDbFallsBackToEnabledDefaultsAndAFreshWatermarkWhenNoRowsExist(): void
    {
        $this->conn->executeStatement('DELETE FROM derivative_settings');
        $this->conn->executeStatement('DELETE FROM derivative_size');

        $this->imageStdParams->loadFromDb();

        self::assertSame('', $this->imageStdParams->getWatermark()->file);

        $enabledKeys = array_keys(ImageStdParams::getEnabledDefaultSizes());
        self::assertSame($enabledKeys, array_keys($this->imageStdParams->getDefinedTypeMap()));

        $disabledKeys = array_keys(ImageStdParams::getDisabledDefaultSizes());
        self::assertSame(['3xlarge', '4xlarge'], $disabledKeys);
        $disabledTypeMap = $this->imageStdParams->getDisabledTypeMap();
        self::assertSame($disabledKeys, array_keys($disabledTypeMap));

        // buildMaps() maps each of the 2 disabled-by-default types to the
        // nearest smaller *defined* type -- both 3xlarge and 4xlarge fall
        // back to 'xxlarge' (the largest enabled-by-default size).
        self::assertSame([
            '3xlarge' => 'xxlarge',
            '4xlarge' => 'xxlarge',
        ], $this->imageStdParams->getUndefinedTypeMap());
        $allTypeMap = $this->imageStdParams->getAllTypeMap();
        self::assertCount(11, $allTypeMap);
        self::assertSame($allTypeMap['xxlarge'], $allTypeMap['3xlarge']);
        self::assertSame($allTypeMap['xxlarge'], $allTypeMap['4xlarge']);

        $settingsRowCount = $this->conn->fetchOne('SELECT COUNT(*) FROM derivative_settings');
        self::assertSame(1, $settingsRowCount);
        $sizeRowCount = $this->conn->fetchOne('SELECT COUNT(*) FROM derivative_size');
        self::assertSame(11, $sizeRowCount);
    }

    /**
     * Mirrors ImageStdParams::seedLockName()'s own documented formula
     * exactly.
     */
    private function seedLockName(string $suffix): string
    {
        return 'piwigo_isp_seed_' . sha1($this->dbName . ':' . $suffix);
    }

    /**
     * Same technique as UploadServiceTest::spawnBackgroundLockHolder() --
     * a real, separate OS process holding $lockName for a short window (a
     * same-process GET_LOCK() call would just re-acquire its own
     * already-held lock).
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawnBackgroundLockHolder(string $lockName): array
    {
        if ($this->dbDriver === 'pgsql') {
            $key = AdvisorySessionLock::key($lockName);
            $sql = sprintf(
                "SET lock_timeout = '5s'; SELECT pg_advisory_lock(%d); SELECT pg_sleep(0.3); SELECT pg_advisory_unlock(%d);",
                $key,
                $key,
            );
            $cmd = ['psql', '-U' . $this->dbUser, '-h' . $this->dbHost, '-d' . $this->dbName, '-q', '-t', '-c', $sql];
            $env = $this->dbPass !== '' ? array_merge(getenv(), [
                'PGPASSWORD' => $this->dbPass,
            ]) : null;
        } else {
            $sql = sprintf(
                "SELECT GET_LOCK('%s', 5); SELECT SLEEP(0.3); SELECT RELEASE_LOCK('%s');",
                $lockName,
                $lockName,
            );
            $cmd = ['mysql', '-u' . $this->dbUser];
            if ($this->dbPass !== '') {
                $cmd[] = '-p' . $this->dbPass;
            }
            $cmd[] = str_starts_with($this->dbHost, '/') ? '--socket=' . $this->dbHost : '-h' . $this->dbHost;
            $cmd[] = $this->dbName;
            $cmd[] = '-e';
            $cmd[] = $sql;
            $env = null;
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($proc, 'proc_open failed for the background lock-holder process');

        return [$proc, $pipes];
    }

    /**
     * Real gap: two requests racing right after a fresh install/DB
     * reimport (no derivative_settings row yet) used to both decide "not
     * seeded" and both try to insert the same default derivative_size
     * rows -- confirmed live: UniqueConstraintViolationException:
     * Duplicate entry '4xlarge' for key 'derivative_size.PRIMARY'. A
     * wrong lock-name formula (dropped database name, dropped separator,
     * swapped operand order, or a hardcoded literal) would let
     * loadFromDb() race straight past a concurrently-held lock instead
     * of genuinely blocking on it -- a real, separate connection holding
     * the exact documented name for a short window is the only way to
     * prove loadFromDb() computes that same name.
     */
    public function testLoadFromDbBlocksOnAConcurrentlyHeldSeedLockThenProceedsOnceItsReleased(): void
    {
        $this->conn->executeStatement('DELETE FROM derivative_settings');
        $this->conn->executeStatement('DELETE FROM derivative_size');

        $lockName = $this->seedLockName('enabled');
        [$proc, $pipes] = $this->spawnBackgroundLockHolder($lockName);

        try {
            // A head start for the background process to actually acquire
            // the lock before loadFromDb() reaches it.
            usleep(50_000);

            $start = microtime(true);
            $this->imageStdParams->loadFromDb();
            $elapsed = microtime(true) - $start;

            self::assertGreaterThan(0.15, $elapsed, 'must have genuinely blocked on the exact same held lock name computed from the documented formula, not raced past it');
            self::assertLessThan(8.0, $elapsed, 'must unblock promptly once the background process releases, not wait anywhere near the full 10s production timeout');

            // Proceeded to seed exactly once (the lock-holder never wrote
            // any rows itself), not skipped or duplicated.
            $settingsRowCount = $this->conn->fetchOne('SELECT COUNT(*) FROM derivative_settings');
            self::assertSame(1, $settingsRowCount);
        } finally {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            $exit = proc_close($proc);
            self::assertSame(0, $exit, "background lock-holder process failed. stdout=[{$stdout}] stderr=[{$stderr}]");
        }
    }

    public function testLoadFromDbReadsARealSettingsRowAndFiltersMalformedCustomJsonEntries(): void
    {
        $customJson = json_encode([
            'my_custom_key' => 1_700_000_000,
            '0' => 5,
            'not_numeric' => 'nope',
        ]);
        assert($customJson !== false);

        $this->conn->executeStatement('DELETE FROM derivative_settings');
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
            'custom_json' => $customJson,
        ]);

        $this->conn->executeStatement('DELETE FROM derivative_size');
        $thumb = new DerivativeParams(SizingParams::classic(100, 100));
        $this->conn->insert('derivative_size', [
            'name' => 'thumb',
            'enabled' => 1,
            'max_width' => 100,
            'max_height' => 100,
            'max_crop' => 0.0,
            'min_width' => null,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => $thumb->last_mod_time,
        ]);
        // Non-empty so loadFromDb()'s own "disabled map is empty ->
        // rebuild defaults" branch (covered by the previous test) isn't
        // exercised again here -- this test stays focused on settings/size
        // row parsing above.
        $this->conn->insert('derivative_size', [
            'name' => '3xlarge',
            'enabled' => 0,
            'max_width' => 2232,
            'max_height' => 1674,
            'max_crop' => 0.0,
            'min_width' => null,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => 0,
        ]);

        $this->imageStdParams->loadFromDb();

        self::assertSame('', $this->imageStdParams->getWatermark()->file);
        self::assertSame([
            'my_custom_key' => 1_700_000_000,
        ], $this->imageStdParams->getCustomTimestamps());
        self::assertSame(90, $this->imageStdParams->getQuality());

        $defined = $this->imageStdParams->getDefinedTypeMap();
        self::assertSame(['thumb'], array_keys($defined));
        self::assertEquals(new Dimensions(100, 100), $defined['thumb']->sizing->ideal_size);
        self::assertSame('thumb', $defined['thumb']->type);

        $disabledTypeMap = $this->imageStdParams->getDisabledTypeMap();
        self::assertSame(['3xlarge'], array_keys($disabledTypeMap));
    }

    public function testGetCustomReturnsADerivativeParamsMatchingTheGivenSizeAndRecordsAFreshCustomKeyOnlyOncePerSize(): void
    {
        // Seeded non-empty so getCustom()'s own save() -> syncDisabled()
        // upserts the derivative_size disabled rows instead of deleting
        // them all (syncDisabled([]) deletes every disabled row when the
        // in-memory map is empty) -- this test's own tearDown() restores
        // the real fixture rows regardless, but an upsert (rather than a
        // delete followed by a same-test re-read) keeps this test's own
        // assertions simple.
        new ReflectionProperty(ImageStdParams::class, 'disabled_type_map')
            ->setValue($this->imageStdParams, ImageStdParams::getDisabledDefaultSizes());

        $watermark = new WatermarkParams();
        $watermark->file = 'watermark.png';
        $watermark->min_size = [80, 80];
        $this->imageStdParams->setWatermark($watermark);

        $large = $this->imageStdParams->getCustom(600, 400, 0.3, 200, 150);

        self::assertEquals(new Dimensions(600, 400), $large->sizing->ideal_size);
        self::assertSame(0.3, $large->sizing->max_crop);
        self::assertEquals(new Dimensions(200, 150), $large->sizing->min_size);
        self::assertSame(ImageStdParams::CUSTOM, $large->type);
        // applyGlobal() compares the watermark's min_size against this
        // type's own ideal_size (600x400): 80<=600, so watermarking applies.
        self::assertTrue($large->use_watermark);
        self::assertCount(1, $this->imageStdParams->getCustomTimestamps());
        $firstTimestamps = $this->imageStdParams->getCustomTimestamps();
        foreach ($firstTimestamps as $timestamp) {
            self::assertGreaterThanOrEqual(time() - 5, $timestamp);
            self::assertLessThanOrEqual(time() + 5, $timestamp);
        }

        $small = $this->imageStdParams->getCustom(50, 40);

        self::assertEquals(new Dimensions(50, 40), $small->sizing->ideal_size);
        self::assertSame(0, $small->sizing->max_crop);
        self::assertNull($small->sizing->min_size);
        // 80<=50 and 80<=40 are both false -- no watermarking for this
        // much smaller size, distinct from $large's own true above.
        self::assertFalse($small->use_watermark);
        self::assertCount(2, $this->imageStdParams->getCustomTimestamps());

        // The first size's own recorded key/timestamp is untouched by the
        // second getCustom() call -- confirms the per-key freshness
        // check, not just "a" write happening.
        $customTimestamps = $this->imageStdParams->getCustomTimestamps();
        foreach ($firstTimestamps as $key => $timestamp) {
            self::assertSame($timestamp, $customTimestamps[$key]);
        }

        $savedCustomJson = $this->conn->fetchOne(
            'SELECT custom_json FROM derivative_settings WHERE id = 1'
        );
        self::assertIsString($savedCustomJson);
        $decoded = json_decode($savedCustomJson, true);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
    }

    public function testSaveAndLoadFromDbRoundTripEveryFieldOfASizeAndTheWatermark(): void
    {
        $watermark = new WatermarkParams();
        $watermark->file = 'my-watermark.png';
        $watermark->min_size = [300, 250];
        $watermark->xpos = 10;
        $watermark->ypos = 90;
        $watermark->xrepeat = 2;
        $watermark->yrepeat = 3;
        $watermark->opacity = 50;
        $this->imageStdParams->setWatermark($watermark);
        $this->imageStdParams->setQuality(82);

        $params = new DerivativeParams(new SizingParams(new Dimensions(500, 400), 0.5, new Dimensions(200, 150)));
        $params->sharpen = 0.25;
        $params->last_mod_time = 1_800_000_000;

        $this->imageStdParams->setAndSave([
            'medium' => $params,
        ]);
        $this->imageStdParams->setAndSaveDisabled([]);

        // Checked here, immediately after setAndSaveDisabled([]) and
        // before the reload below -- loadFromDb()'s own "disabled map
        // came back empty -> reseed the 2 defaults" fallback (preserved
        // faithfully from the original blob-based behavior) would
        // otherwise mask a real deletion bug by refilling the table right
        // back up before this test could observe the empty state.
        $sizeRowCountAfterClear = $this->conn->fetchOne('SELECT COUNT(*) FROM derivative_size WHERE enabled = 0');
        self::assertSame(0, $sizeRowCountAfterClear, 'setAndSaveDisabled([]) must delete every disabled row, not leave stale ones behind.');

        // A fresh instance -- reload() must derive everything from the DB
        // rows just saved above, not from any in-memory state left behind
        // by the writes above.
        $this->imageStdParams = new ImageStdParams();
        $this->imageStdParams->loadFromDb();

        self::assertSame('my-watermark.png', $this->imageStdParams->getWatermark()->file);
        self::assertSame([300, 250], $this->imageStdParams->getWatermark()->min_size);
        self::assertSame(10, $this->imageStdParams->getWatermark()->xpos);
        self::assertSame(90, $this->imageStdParams->getWatermark()->ypos);
        self::assertSame(2, $this->imageStdParams->getWatermark()->xrepeat);
        self::assertSame(3, $this->imageStdParams->getWatermark()->yrepeat);
        self::assertSame(50, $this->imageStdParams->getWatermark()->opacity);
        self::assertSame(82, $this->imageStdParams->getQuality());

        $reloaded = $this->imageStdParams->getDefinedTypeMap()['medium'];
        self::assertEquals(new Dimensions(500, 400), $reloaded->sizing->ideal_size);
        self::assertSame(0.5, $reloaded->sizing->max_crop);
        self::assertEquals(new Dimensions(200, 150), $reloaded->sizing->min_size);
        self::assertSame(0.25, $reloaded->sharpen);
        self::assertSame(1_800_000_000, $reloaded->last_mod_time);

        // loadFromDb()'s own reseed-on-empty fallback (see the earlier
        // assertion's own comment) means the disabled set is back to the
        // 2 defaults after this reload, not empty -- that fallback is
        // pre-existing, faithfully preserved behavior, not something this
        // round-trip test is about.
        self::assertSame(['3xlarge', '4xlarge'], array_keys($this->imageStdParams->getDisabledTypeMap()));
    }

    public function testEnablingAPreviouslyDisabledSizeMovesItInPlaceRatherThanDuplicatingTheRow(): void
    {
        $params = new DerivativeParams(SizingParams::classic(2232, 1674));
        $this->imageStdParams->setAndSaveDisabled([
            '3xlarge' => $params,
        ]);

        self::assertSame(['3xlarge'], array_keys($this->imageStdParams->getDisabledTypeMap()));

        $this->imageStdParams->setAndSave([
            '3xlarge' => $params,
        ]);
        $this->imageStdParams->setAndSaveDisabled([]);

        $rows = $this->conn->fetchAllAssociative(
            "SELECT enabled FROM derivative_size WHERE name = '3xlarge'"
        );
        self::assertCount(1, $rows, '3xlarge must have exactly one row after moving from disabled to enabled, not two.');
        self::assertSame(1, $rows[0]['enabled']);
    }

    public function testWatermarkFromJsonRejectsANonArrayMinSizeAndAPairWithOneNonNumericElement(): void
    {
        // WatermarkParams::$min_size defaults to [500, 500] --
        // watermarkFromJson()'s own guard must reject anything that isn't a
        // genuine 2-element numeric array and leave that default intact,
        // rather than coercing a malformed value into a bogus min_size.
        $twoCharWatermarkJson = json_encode([
            'min_size' => '12',
        ]);
        assert($twoCharWatermarkJson !== false);

        $this->conn->executeStatement('DELETE FROM derivative_settings');
        $this->conn->insert('derivative_settings', [
            'id' => 1,
            'default_quality' => 95,
            // A 2-character string: is_array() is false, but (via PHP's
            // string-offset access) isset($minSize[0], $minSize[1]) and
            // both is_numeric() checks would still pass on '1'/'2' --
            // exercises the is_array() conjunct specifically.
            'watermark_json' => $twoCharWatermarkJson,
            'custom_json' => '{}',
        ]);

        $this->imageStdParams->loadFromDb();

        self::assertSame([500, 500], $this->imageStdParams->getWatermark()->min_size);

        // A genuine 2-element array where the first element is numeric but
        // the second isn't -- exercises the trailing is_numeric($minSize[1])
        // conjunct specifically (is_array()/isset() both pass here).
        $mixedMinSizeWatermarkJson = json_encode([
            'min_size' => [300, 'abc'],
        ]);
        assert($mixedMinSizeWatermarkJson !== false);

        $this->conn->executeStatement('DELETE FROM derivative_settings');
        $this->conn->insert('derivative_settings', [
            'id' => 1,
            'default_quality' => 95,
            'watermark_json' => $mixedMinSizeWatermarkJson,
            'custom_json' => '{}',
        ]);

        $this->imageStdParams->loadFromDb();

        self::assertSame([500, 500], $this->imageStdParams->getWatermark()->min_size);
    }

    public function testWatermarkFromJsonCastsNumericStringJsonValuesToInt(): void
    {
        // watermarkToJson()/setWatermark() always produce/consume real PHP
        // ints, so a save()/loadFromDb() round trip through this class's
        // own API never exercises these (int) casts -- only a hand-edited
        // or externally-written row (Doctrine's `json` column type only
        // guarantees valid JSON, not that every value already has the
        // right PHP type) can contain numeric strings here.
        $numericStringWatermarkJson = json_encode([
            'min_size' => ['300', '250'],
            'xpos' => '77',
            'xrepeat' => '4',
            'yrepeat' => '6',
        ]);
        assert($numericStringWatermarkJson !== false);

        $this->conn->executeStatement('DELETE FROM derivative_settings');
        $this->conn->insert('derivative_settings', [
            'id' => 1,
            'default_quality' => 95,
            'watermark_json' => $numericStringWatermarkJson,
            'custom_json' => '{}',
        ]);

        $this->imageStdParams->loadFromDb();

        self::assertSame([300, 250], $this->imageStdParams->getWatermark()->min_size);
        self::assertSame(77, $this->imageStdParams->getWatermark()->xpos);
        self::assertSame(4, $this->imageStdParams->getWatermark()->xrepeat);
        self::assertSame(6, $this->imageStdParams->getWatermark()->yrepeat);
    }

    public function testSizesFromEntitiesTreatsASizeWithOnlyOneOfMinWidthOrMinHeightSetAsHavingNoMinSize(): void
    {
        // SizingParams::min_size is only ever meaningful as a genuine
        // 2-element pair (see its own constructor docblock) --
        // sizesFromEntities() must require BOTH minWidth and minHeight
        // non-null, not treat "either one set" as enough to build a
        // malformed 1-real/1-null pair.
        $this->conn->executeStatement('DELETE FROM derivative_size');
        $this->conn->insert('derivative_size', [
            'name' => 'thumb',
            'enabled' => 1,
            'max_width' => 200,
            'max_height' => 150,
            'max_crop' => 0.5,
            'min_width' => 80,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
            'name' => '3xlarge',
            'enabled' => 0,
            'max_width' => 2232,
            'max_height' => 1674,
            'max_crop' => 0.0,
            'min_width' => null,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => 0,
        ]);

        $this->imageStdParams->loadFromDb();

        self::assertNull($this->imageStdParams->getDefinedTypeMap()['thumb']->sizing->min_size);
    }

    public function testSizesFromEntitiesOrdersCanonicalTypesFirstThenAppendsAnyUnrecognizedNames(): void
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
        $this->conn->executeStatement('DELETE FROM derivative_size');
        $this->conn->insert('derivative_size', [
            'name' => 'medium',
            'enabled' => 1,
            'max_width' => 792,
            'max_height' => 594,
            'max_crop' => 0.0,
            'min_width' => null,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
            'name' => 'xsmall',
            'enabled' => 1,
            'max_width' => 432,
            'max_height' => 324,
            'max_crop' => 0.0,
            'min_width' => null,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
            'name' => 'bogus_type',
            'enabled' => 1,
            'max_width' => 10,
            'max_height' => 10,
            'max_crop' => 0.0,
            'min_width' => null,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
            'name' => '3xlarge',
            'enabled' => 0,
            'max_width' => 2232,
            'max_height' => 1674,
            'max_crop' => 0.0,
            'min_width' => null,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => 0,
        ]);

        $this->imageStdParams->loadFromDb();

        self::assertSame(['xsmall', 'medium', 'bogus_type'], array_keys($this->imageStdParams->getDefinedTypeMap()));
    }

    public function testApplyGlobalNeverEnablesWatermarkingWhenTheWatermarkFileIsEmpty(): void
    {
        // A fresh instance's $watermark stays null until setWatermark()/
        // loadFromDb() populates it; applyGlobal() lazily defaults it to
        // a fresh WatermarkParams() whose file stays ''. That empty-file
        // check must short-circuit the whole expression, even for a size
        // whose ideal_size is large enough that the default min_size
        // ([500, 500]) would otherwise satisfy the size comparison below.
        $params = new DerivativeParams(SizingParams::classic(999, 999));

        $this->imageStdParams->applyGlobal($params);

        self::assertFalse($params->use_watermark);
    }

    public function testApplyGlobalComparesEachAxisOfTheWatermarksMinSizeAgainstTheMatchingAxisOfTheSizesIdealSize(): void
    {
        $watermark = new WatermarkParams();
        $watermark->file = 'w.png';

        // Isolates the width comparison: min_size[0] equals ideal_size's
        // own width exactly (80<=80 is true), while the height pair is
        // unambiguously false (9000<=1 isn't) -- so use_watermark can only
        // end up true here via the width comparison. The extra -1 entry on
        // $watermark->min_size (still a plain array) is deliberately chosen
        // so that reading the wrong array index there, using '<'/'>'
        // instead of '<=', or turning the "or" into an "and" would each
        // flip the result; ideal_size's own width/height are named
        // Dimensions properties now, so no equivalent decoy-index trick
        // applies to them -- a width<->height mix-up on that side is
        // exercised structurally, by using deliberately different values
        // for each axis instead.
        $watermark->min_size = [
            -1 => 9999,
            0 => 80,
            1 => 9000,
        ];
        $this->imageStdParams->setWatermark($watermark);
        $params = new DerivativeParams(new SizingParams(new Dimensions(80, 1)));

        $this->imageStdParams->applyGlobal($params);

        self::assertTrue($params->use_watermark);

        // Mirrors the above, isolating the height comparison instead:
        // 80<=80 is true there, while the width pair is unambiguously
        // false (9000<=1 isn't).
        $watermark->min_size = [
            0 => 9000,
            1 => 80,
            2 => 9999,
        ];
        $this->imageStdParams->setWatermark($watermark);
        $params2 = new DerivativeParams(new SizingParams(new Dimensions(1, 80)));

        $this->imageStdParams->applyGlobal($params2);

        self::assertTrue($params2->use_watermark);
    }

    public function testBuildMapsAppliesTheWatermarkToEveryDefinedSize(): void
    {
        $watermark = new WatermarkParams();
        $watermark->file = 'w.png';
        $watermark->min_size = [10, 10];
        $this->imageStdParams->setWatermark($watermark);

        $params = new DerivativeParams(SizingParams::classic(200, 200));
        $this->imageStdParams->setAndSave([
            'thumb' => $params,
        ]);

        $defined = $this->imageStdParams->getDefinedTypeMap();
        self::assertSame('thumb', $defined['thumb']->type);
        self::assertTrue($defined['thumb']->use_watermark);
    }

    public function testBuildMapsBackfillsFromTheSmallestDefinedTypeAllTheWayDownToIndexZero(): void
    {
        // Only 'square' (index 0 in ImageStdParams::ALL_TYPES, the very
        // smallest type) is defined -- every other type must fall back to
        // it. buildMaps()'s own inner search loop starts at $i - 1 and
        // walks down to (and including) index 0; if that lower bound were
        // ever off by one, 'square' -- sitting at the boundary itself --
        // would never be found, and every other type would stay entirely
        // undefined.
        $square = new DerivativeParams(SizingParams::square(120));
        $this->imageStdParams->setAndSave([
            'square' => $square,
        ]);

        $allTypeMap = $this->imageStdParams->getAllTypeMap();
        self::assertCount(11, $allTypeMap);

        $expectedUndefined = array_fill_keys(
            ['thumb', '2small', 'xsmall', 'small', 'medium', 'large', 'xlarge', 'xxlarge', '3xlarge', '4xlarge'],
            'square',
        );
        self::assertSame($expectedUndefined, $this->imageStdParams->getUndefinedTypeMap());
        self::assertSame($allTypeMap['square'], $allTypeMap['thumb']);
        self::assertSame($allTypeMap['square'], $allTypeMap['4xlarge']);
    }

    public function testBuildMapsNeverTreatsAnOutOfBoundsNegativeIndexAsAValidFallback(): void
    {
        // ALL_TYPES is a plain 0..10-indexed array; buildMaps()'s own inner
        // search loop must stop once it reaches index 0 (the smallest real
        // type) rather than also reading self::ALL_TYPES[-1] (undefined ->
        // null, which PHP then coerces to the empty-string array key '').
        // A row named '' is nothing the application itself ever writes, but
        // the schema has no CHECK constraint against it (see
        // DerivativeSizeEntity's own docblock) -- planting one directly is
        // the only way to observe whether that extra, out-of-bounds
        // iteration would wrongly treat it as a valid fallback source.
        $this->conn->executeStatement('DELETE FROM derivative_size');
        $this->conn->insert('derivative_size', [
            'name' => '',
            'enabled' => 1,
            'max_width' => 999,
            'max_height' => 999,
            'max_crop' => 0.0,
            'min_width' => null,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => 0,
        ]);
        $this->conn->insert('derivative_size', [
            'name' => '3xlarge',
            'enabled' => 0,
            'max_width' => 2232,
            'max_height' => 1674,
            'max_crop' => 0.0,
            'min_width' => null,
            'min_height' => null,
            'sharpen' => 0.0,
            'last_mod_time' => 0,
        ]);

        $this->imageStdParams->loadFromDb();

        // None of ImageStdParams::ALL_TYPES is defined (only the '' row
        // is) -- every real type must stay genuinely undefined, not
        // silently backfilled from the out-of-bounds '' entry.
        self::assertSame([], $this->imageStdParams->getUndefinedTypeMap());
        self::assertCount(1, $this->imageStdParams->getAllTypeMap());
    }
}
