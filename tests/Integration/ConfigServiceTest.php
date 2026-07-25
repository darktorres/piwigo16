<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;

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

    public function test_loadConfFromDb_merges_every_row_with_boolean_coercion(): void
    {
        $this->service->loadConfFromDb();

        // Real fixture row seeded 'true'/'false' string values -- confirm
        // the exact load_conf_from_db() coercion, not JSON decoding.
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
     * entirely (this codebase's real raw-SQL config writer,
     * MenubarLayoutRepository) leaves the cache stale until a real
     * ConfigService write clears it.
     */
    public function test_loadConfFromDb_caches_the_bulk_load_until_a_write_invalidates_it(): void
    {
        $repo = $this->buildConfigRepository();

        // Prime the cache with the fixture's own value.
        $this->service->loadConfFromDb();
        self::assertSame('Fixture Gallery', CurrentConfig::galleryTitle());

        // Write directly through the repository, bypassing ConfigService's
        // own cache-clearing.
        $repo->upsert('gallery_title', 'Written Around The Cache');

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

    public function test_confUpdateParam_encodes_arrays_via_serialize(): void
    {
        $param = 'p14_service_array_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, ['a', 'b', 'c']);

        $repo = $this->buildConfigRepository();
        $entry = $repo->find($param);
        self::assertNotNull($entry);
        self::assertSame(serialize(['a', 'b', 'c']), $entry->value);

        $repo->deleteByParam($param);
    }

    public function test_confUpdateParam_encodes_bools_as_true_false_strings(): void
    {
        $param = 'p14_service_bool_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, false);

        $repo = $this->buildConfigRepository();
        $entry = $repo->find($param);
        self::assertNotNull($entry);
        self::assertSame('false', $entry->value);

        $repo->deleteByParam($param);
    }

    public function test_pwgIsDbconfWriteable_returns_true_against_a_real_writable_db(): void
    {
        self::assertTrue($this->service->pwgIsDbconfWriteable());
    }
}
