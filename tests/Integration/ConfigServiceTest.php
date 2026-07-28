<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;

/**
 * hydrate()'s own `match ($paramTypeName) { ... default => $decoded }` arm
 * is unreachable through any currently-defined CurrentConfig setter: every
 * one of them (grepped across the whole class) takes a plain (nullable)
 * bool/int/float/string/array first parameter, all handled by the match's
 * explicit arms above `default` -- a genuinely union/object-typed setter
 * would need to exist for that arm to ever run. Not worth a forced test;
 * same "confirmed unreachable, not worth chasing" treatment this project
 * already applies elsewhere (see e.g. RateRepositoryTest's own identical
 * note on 2 defensive `is_numeric()` continues).
 */
final class ConfigServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ConfigService $service;

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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->service = new ConfigService($this->buildConfigRepository());
    }

    public function test_loadConfFromDb_merges_every_row_with_json_decoding(): void
    {
        $this->service->loadConfFromDb();

        // Real fixture rows: 'true' (a bare JSON bool literal) for
        // activate_comments, and a JSON-quoted string for both secret_key
        // and gallery_title.
        self::assertTrue(CurrentConfig::activateComments());
        self::assertNotSame('', CurrentConfig::secretKey());
        self::assertSame('Fixture Gallery', CurrentConfig::galleryTitle());
    }

    public function test_loadConfFromDb_with_a_param_loads_only_that_row(): void
    {
        // setUp() already calls ConfigLoader::applyDefaults(), which seeds
        // every non-nullable property (including gallery_title) with its
        // own compiled-in default -- the real assertion is that a
        // single-param load doesn't also overwrite gallery_title with the
        // fixture's actual DB value ('Fixture Gallery').
        $this->service->loadConfFromDb('secret_key');

        self::assertNotSame('', CurrentConfig::secretKey());
        self::assertSame('Piwigo', CurrentConfig::galleryTitle());
    }

    public function test_loadConfFromDb_throws_when_param_not_found_and_dieIfNotFound_is_true(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->loadConfFromDb('this_param_does_not_exist_anywhere');
    }

    public function test_loadConfFromDb_returns_quietly_when_param_not_found_and_dieIfNotFound_is_false(): void
    {
        $missingKey = 'this_param_does_not_exist_anywhere';
        $this->service->loadConfFromDb($missingKey, false);

        self::assertNull($this->service->confGetParam($missingKey));
    }

    public function test_confGetParam_falls_back_to_the_given_default_for_a_genuinely_dynamic_unset_key(): void
    {
        $missingKey = 'this_param_does_not_exist_anywhere';

        self::assertSame('fallback', $this->service->confGetParam($missingKey, 'fallback'));
        self::assertNull($this->service->confGetParam($missingKey));
    }

    /**
     * Boot sequence #2 (Config generic-accessor removal follow-up):
     * ConfigService::allRowsFromCacheOrDb() caches the bulk load's
     * param => value map in CachePools::config() -- this is the real test
     * of both halves of that design: a write that bypasses ConfigService
     * entirely (this codebase's real raw config writer, ConfigRepository::
     * upsert() called directly, matching Admin\MenubarPageRenderer's own
     * call site) leaves the cache stale until a real ConfigService write
     * clears it.
     */
    public function test_loadConfFromDb_caches_the_bulk_load_until_a_write_invalidates_it(): void
    {
        $repo = $this->buildConfigRepository();

        // Prime the cache with the fixture's own value.
        $this->service->loadConfFromDb();
        self::assertSame('Fixture Gallery', CurrentConfig::galleryTitle());

        // Write directly through the repository, bypassing ConfigService's
        // own cache-clearing (and its encode() -- json_encode() the value
        // by hand here, matching what a real raw writer like
        // Admin\MenubarPageRenderer's own ConfigRepository::upsert() call
        // does).
        $writtenAroundTheCache = json_encode('Written Around The Cache');
        self::assertIsString($writtenAroundTheCache);
        $repo->upsert('gallery_title', $writtenAroundTheCache);

        // A second bulk load still returns the stale cached snapshot --
        // proves allRowsFromCacheOrDb() is actually caching, not
        // re-querying every time.
        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        $this->service->loadConfFromDb();
        self::assertSame('Fixture Gallery', CurrentConfig::galleryTitle());

        // A real ConfigService write clears the cache; the next bulk load
        // picks up the fresh value.
        $this->service->confUpdateParam('gallery_title', 'Fresh After Invalidation');
        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        $this->service->loadConfFromDb();
        self::assertSame('Fresh After Invalidation', CurrentConfig::galleryTitle());

        // Restore the fixture's own value -- confUpdateParam() also clears
        // the cache again, so later tests in this file don't inherit
        // either the stale write above or this restore itself.
        $this->service->confUpdateParam('gallery_title', 'Fixture Gallery');
    }

    public function test_confUpdateParam_then_confDeleteParam_round_trips(): void
    {
        $param = 'p14_service_test_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, 'a value');
        self::assertSame('a value', $this->service->confGetParam($param));

        $this->service->confDeleteParam($param);
        self::assertNull($this->service->confGetParam($param));
    }

    public function test_confUpdateParam_encodes_arrays_via_json(): void
    {
        $param = 'p14_service_array_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, ['a', 'b', 'c']);

        $repo = $this->buildConfigRepository();
        $entry = $repo->find($param);
        self::assertNotNull($entry);
        self::assertSame(json_encode(['a', 'b', 'c']), $entry->value);

        $repo->deleteByParam($param);
    }

    public function test_confUpdateParam_encodes_bools_as_bare_json_literals(): void
    {
        $param = 'p14_service_bool_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, false);

        $repo = $this->buildConfigRepository();
        $entry = $repo->find($param);
        self::assertNotNull($entry);
        self::assertSame('false', $entry->value);

        $repo->deleteByParam($param);
    }

    public function test_confUpdateParam_encodes_strings_as_json_quoted_text(): void
    {
        $param = 'p14_service_string_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, 'a value');

        $repo = $this->buildConfigRepository();
        $entry = $repo->find($param);
        self::assertNotNull($entry);
        self::assertSame('"a value"', $entry->value);

        $repo->deleteByParam($param);
    }

    public function test_gallery_locked_round_trips_as_a_real_bool_through_load_conf_from_db(): void
    {
        // Real, adversarially-found bug: MaintenanceActionDispatcher used
        // to write the *string* 'true'/'false' to this bool-typed property
        // (harmless under the old per-type-cast convention, but silently
        // hydrates to false either way under json_decode() -- a real
        // string doesn't satisfy the 'bool' match arm's is_bool() check).
        // This exercises the actual fixed call shape (a real PHP bool),
        // not just ConfigService's own generic bool-encoding mechanism
        // (already covered above).
        try {
            $this->service->confUpdateParam('gallery_locked', true);
            $this->service->loadConfFromDb('gallery_locked');
            self::assertTrue(CurrentConfig::galleryLocked());

            $this->service->confUpdateParam('gallery_locked', false);
            $this->service->loadConfFromDb('gallery_locked');
            self::assertFalse(CurrentConfig::galleryLocked());
        } finally {
            $this->service->confUpdateParam('gallery_locked', false);
        }
    }

    public function test_data_dir_checked_round_trips_as_a_real_string_through_load_conf_from_db(): void
    {
        // Real, adversarially-found bug: Template.php used to write the
        // real int 1 to this ?string-typed property. json_encode(1)
        // produces a bare JSON number, and hydrate()'s 'string' match arm
        // only accepts a real is_string() decode -- an int decode falls
        // through to '' instead.
        try {
            $this->service->confUpdateParam('data_dir_checked', '1');
            $this->service->loadConfFromDb('data_dir_checked');
            self::assertSame('1', CurrentConfig::dataDirChecked());
        } finally {
            $this->service->confDeleteParam('data_dir_checked');
        }
    }

    public function test_confUpdateParam_leaves_disabled_derivatives_as_a_serialize_blob(): void
    {
        // ConfigService::OBJECT_SERIALIZED_PARAMS: this key holds real
        // DerivativeParams objects reconstructed via unserialize()'s own
        // object-identity-preserving behavior -- json_encode() would
        // silently lose their class identity.
        $params = ['a', 'b', 'c'];

        $this->service->confUpdateParam('disabled_derivatives', $params);

        $repo = $this->buildConfigRepository();
        $entry = $repo->find('disabled_derivatives');
        self::assertNotNull($entry);
        self::assertSame(serialize($params), $entry->value);

        $repo->deleteByParam('disabled_derivatives');
    }

    public function test_load_conf_from_db_hydrates_disabled_derivatives_as_the_raw_serialize_blob(): void
    {
        $params = ['a', 'b', 'c'];

        try {
            $this->service->confUpdateParam('disabled_derivatives', $params);
            $this->service->loadConfFromDb('disabled_derivatives');

            // Deliberately still the raw blob, not a decoded array -- every
            // real reader (ImageStdParams) unserialize()s it itself.
            self::assertSame(serialize($params), CurrentConfig::disabledDerivatives());
        } finally {
            $this->service->confDeleteParam('disabled_derivatives');
        }
    }

    public function test_pwgIsDbconfWriteable_returns_true_against_a_real_writable_db(): void
    {
        self::assertTrue($this->service->pwgIsDbconfWriteable());
    }

    /**
     * hydrate()'s own docblock names 'upload_user_access' as a real
     * example of a genuinely dynamic/unschematized param -- a row for it
     * must load without error (and without touching any CurrentConfig
     * property), exactly like the SCHEMA-driven design this replaces
     * silently skipped keys with no matching accessor.
     */
    public function test_loadConfFromDb_silently_skips_a_row_with_no_matching_property(): void
    {
        $param = 'upload_user_access';
        $this->service->confUpdateParam($param, 'admins');

        try {
            $this->service->loadConfFromDb();

            self::assertSame('admins', $this->service->confGetParam($param));
        } finally {
            $this->service->confDeleteParam($param);
        }
    }

    public function test_conf_update_param_encodes_null_as_a_literal_null_db_value(): void
    {
        $param = 'a_genuinely_dynamic_nullable_test_param';
        $this->service->confUpdateParam($param, 'first');

        try {
            $this->service->confUpdateParam($param, null);

            self::assertNull($this->buildConfigRepository()->find($param)?->value);
        } finally {
            $this->service->confDeleteParam($param);
        }
    }
}
