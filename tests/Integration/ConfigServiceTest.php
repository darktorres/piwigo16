<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\PluginConfig\EventDispatcher;
use RuntimeException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
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

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->service = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), $currentConfig);
    }

    public function test_loadConfFromDb_merges_every_row_with_json_decoding(): void
    {
        $this->service->loadConfFromDb();

        // Real fixture rows: 'true' (a bare JSON bool literal) for
        // activate_comments, and a JSON-quoted string for both secret_key
        // and gallery_title.
        $currentConfig = CurrentConfigTestFactory::get();
        self::assertTrue($currentConfig->activateComments());
        self::assertNotSame('', $currentConfig->secretKey());
        self::assertSame('Fixture Gallery', $currentConfig->galleryTitle());
    }

    public function test_loadConfFromDb_with_a_param_loads_only_that_row(): void
    {
        // setUp() already calls ConfigLoader::applyDefaults(), which seeds
        // every non-nullable property (including gallery_title) with its
        // own compiled-in default -- the real assertion is that a
        // single-param load doesn't also overwrite gallery_title with the
        // fixture's actual DB value ('Fixture Gallery').
        $this->service->loadConfFromDb('secret_key');

        $currentConfig = CurrentConfigTestFactory::get();
        self::assertNotSame('', $currentConfig->secretKey());
        self::assertSame('Piwigo', $currentConfig->galleryTitle());
    }

    public function test_loadConfFromDb_throws_when_param_not_found_and_dieIfNotFound_is_true(): void
    {
        $this->expectException(RuntimeException::class);
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
        self::assertSame('Fixture Gallery', CurrentConfigTestFactory::get()->galleryTitle());

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
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        $this->service->loadConfFromDb();
        self::assertSame('Fixture Gallery', $currentConfig->galleryTitle());

        // A real ConfigService write clears the cache; the next bulk load
        // picks up the fresh value.
        $this->service->confUpdateParam('gallery_title', 'Fresh After Invalidation');
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        $this->service->loadConfFromDb();
        self::assertSame('Fresh After Invalidation', $currentConfig->galleryTitle());

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

    public function test_confDeleteParam_resets_the_backing_property_to_its_declared_default(): void
    {
        // Every existing confDeleteParam() call site in this file (the
        // round-trip test above included) uses a genuinely dynamic,
        // no-property key, so KEY_TO_PROPERTY's own lookup always misses
        // and the method's reflection-based "reset to the property's own
        // declared default" branch (lines 495-497) never actually runs --
        // 'session_length' is a real property-backed key with no fixture
        // row of its own (confirmed: absent from piwigo-17.0.sql), so
        // deleting it afterward leaves the DB exactly as the fixture left
        // it, no restore needed.
        $this->service->confUpdateParam('session_length', 9999);
        $this->service->loadConfFromDb('session_length');
        $currentConfig = CurrentConfigTestFactory::get();
        self::assertSame(9999, $currentConfig->sessionLength());

        $this->service->confDeleteParam('session_length');

        self::assertSame(3600, $currentConfig->sessionLength());
    }

    public function test_confUpdateParam_encodes_arrays_via_json(): void
    {
        $param = 'p14_service_array_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, ['a', 'b', 'c']);

        $repo = $this->buildConfigRepository();
        $entry = $repo->find($param);
        self::assertNotNull($entry);
        // Not a raw string comparison against json_encode()'s own PHP-side
        // output: `value` is a real JSON column, and MySQL re-serializes
        // stored JSON in its own canonical form (a space after each comma)
        // rather than preserving the exact bytes originally written --
        // decode-and-compare is the actual contract that matters here.
        self::assertSame(['a', 'b', 'c'], json_decode($entry->value ?? 'null', true));

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
        // hydrate()'s 'bool' match arm only accepts a real is_bool()
        // decode -- a string 'true'/'false' doesn't satisfy it and would
        // silently hydrate to false either way. This exercises a real PHP
        // bool going through confUpdateParam()/loadConfFromDb(), not just
        // ConfigService's own generic bool-encoding mechanism (already
        // covered above).
        try {
            $currentConfig = CurrentConfigTestFactory::get();
            $this->service->confUpdateParam('gallery_locked', true);
            $this->service->loadConfFromDb('gallery_locked');
            self::assertTrue($currentConfig->galleryLocked());

            $this->service->confUpdateParam('gallery_locked', false);
            $this->service->loadConfFromDb('gallery_locked');
            self::assertFalse($currentConfig->galleryLocked());
        } finally {
            $this->service->confUpdateParam('gallery_locked', false);
        }
    }

    public function test_data_dir_checked_round_trips_as_a_real_string_through_load_conf_from_db(): void
    {
        // json_encode(1) produces a bare JSON number, and hydrate()'s
        // 'string' match arm only accepts a real is_string() decode -- an
        // int decode falls through to '' instead, so this property must
        // always be written as a real string.
        try {
            $this->service->confUpdateParam('data_dir_checked', '1');
            $this->service->loadConfFromDb('data_dir_checked');
            self::assertSame('1', CurrentConfigTestFactory::get()->dataDirChecked());
        } finally {
            $this->service->confDeleteParam('data_dir_checked');
        }
    }

    public function test_pwgIsDbconfWriteable_returns_true_against_a_real_writable_db(): void
    {
        self::assertTrue($this->service->pwgIsDbconfWriteable());
    }

    /**
     * hydrate()'s own docblock names 'upload_user_access' as a real
     * example of a genuinely dynamic/unschematized param -- a row for it
     * must load without error and without touching any CurrentConfig
     * property.
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
