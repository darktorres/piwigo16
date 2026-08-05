<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\TablePrefixListener;

/**
 * ConfigRepository has no Unit-level test file at all -- every public
 * method here is a thin, direct ORM/DBAL call with no branching logic of
 * its own worth exercising against a fake, so it's covered exclusively
 * here. A Unit-scoped mutation-testing sweep (--testsuite Unit) flags
 * deleteByParam()'s own `$em->remove($entry); $em->flush();` as untested
 * as a result -- a genuine suite-scope mismatch, not a real gap:
 * test_deleteByParam_removes_the_row() below re-fetches via a fresh
 * repository (bypassing the identity map) and asserts the row is
 * genuinely gone, which only a real remove()+flush() pair can satisfy.
 */
final class ConfigRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ConfigRepository $repo;

    private EntityManagerInterface $em;

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

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $conn = DbConnection::build();
        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Piwigo'], isDevMode: true);
        $ormConfig->enableNativeLazyObjects(true);
        $this->em = new EntityManager($conn, $ormConfig);
        $this->em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener(\Piwigo\Db\DbCredentials::current()));

        $repo = $this->em->getRepository(ConfigEntry::class);
        self::assertInstanceOf(ConfigRepository::class, $repo);
        $this->repo = $repo;
    }

    public function test_find_returns_a_real_fixture_row(): void
    {
        $entry = $this->repo->find('secret_key');

        self::assertNotNull($entry);
        self::assertSame('secret_key', $entry->param);
        self::assertNotNull($entry->value);
        self::assertNotSame('', $entry->value);
    }

    public function test_find_returns_null_for_a_missing_param(): void
    {
        self::assertNull($this->repo->find('this_param_does_not_exist_anywhere'));
    }

    public function test_findAll_returns_every_fixture_row(): void
    {
        $entries = $this->repo->findAll();

        self::assertGreaterThan(10, count($entries));
        $params = array_map(static fn (ConfigEntry $e): string => $e->param, $entries);
        self::assertContains('secret_key', $params);
        self::assertContains('gallery_title', $params);
    }

    public function test_upsert_creates_a_new_row(): void
    {
        $param = 'p14_test_upsert_new_' . bin2hex(random_bytes(4));

        $this->repo->upsert($param, 'hello');

        $fresh = $this->freshRepo();
        $entry = $fresh->find($param);
        self::assertNotNull($entry);
        self::assertSame('hello', $entry->value);

        $fresh->deleteByParam($param);
    }

    public function test_upsert_updates_an_existing_row(): void
    {
        $param = 'p14_test_upsert_existing_' . bin2hex(random_bytes(4));
        $this->repo->upsert($param, 'first');
        $this->repo->upsert($param, 'second');

        $fresh = $this->freshRepo();
        $entry = $fresh->find($param);
        self::assertNotNull($entry);
        self::assertSame('second', $entry->value);

        $fresh->deleteByParam($param);
    }

    public function test_upsert_updates_the_comment_of_an_existing_row_when_given_one(): void
    {
        $param = 'p14_test_upsert_comment_' . bin2hex(random_bytes(4));
        $this->repo->upsert($param, 'first', 'original comment');

        // a null $comment (the default) leaves the existing comment alone
        $this->repo->upsert($param, 'second');

        $fresh = $this->freshRepo();
        $entry = $fresh->find($param);
        self::assertNotNull($entry);
        self::assertSame('second', $entry->value);
        self::assertSame('original comment', $entry->comment);

        // a non-null $comment on an update DOES overwrite it
        $this->repo->upsert($param, 'third', 'updated comment');

        $fresh2 = $this->freshRepo();
        $entry2 = $fresh2->find($param);
        self::assertNotNull($entry2);
        self::assertSame('updated comment', $entry2->comment);

        $fresh2->deleteByParam($param);
    }

    public function test_deleteByParam_removes_the_row(): void
    {
        $param = 'p14_test_delete_' . bin2hex(random_bytes(4));
        $this->repo->upsert($param, 'temp');

        $this->repo->deleteByParam($param);

        $fresh = $this->freshRepo();
        self::assertNull($fresh->find($param));
    }

    public function test_insert_ignore_raw_value_creates_a_row_then_is_a_silent_noop_on_a_second_call(): void
    {
        $param = 'p14_test_insert_ignore_' . bin2hex(random_bytes(4));

        $this->repo->insertIgnoreRawValue($param, 'first-value');
        self::assertSame('first-value', $this->repo->findRawValue($param));

        // INSERT IGNORE: (param) is the primary key, so a second call for
        // the same $param is a silent no-op, not an overwrite -- the real
        // reason Core\UniqueExecLock uses this method at all (see its own
        // docblock: only the process that wins the race gets its value
        // stored).
        $this->repo->insertIgnoreRawValue($param, 'second-value-should-be-ignored');
        self::assertSame('first-value', $this->repo->findRawValue($param));

        $this->repo->deleteByParam($param);
    }

    /**
     * SQL-modernization audit regression: $param/$value used to be
     * spliced directly into `INSERT IGNORE ... SET param="{$param}",
     * value="{$value}"` (manual double-quote wrapping, no escaping) --
     * now bound. A value containing a literal double quote would have
     * broken out of the old manual quoting (`"..."`.$value.`"..."`
     * shape) into raw SQL text; confirms it now round-trips as inert
     * data instead.
     */
    public function test_insert_ignore_raw_value_and_find_raw_value_round_trip_a_value_containing_a_double_quote(): void
    {
        $param = 'p14_test_quote_' . bin2hex(random_bytes(4));
        $value = 'value with a " double quote and a \' single quote';

        $this->repo->insertIgnoreRawValue($param, $value);

        self::assertSame($value, $this->repo->findRawValue($param));

        $this->repo->deleteByParam($param);
    }

    public function test_find_raw_value_returns_false_for_a_missing_param(): void
    {
        self::assertFalse($this->repo->findRawValue('this_param_does_not_exist_anywhere'));
    }

    public function test_count_by_param_reflects_presence_and_absence(): void
    {
        $param = 'p14_test_count_' . bin2hex(random_bytes(4));

        self::assertSame(0, $this->repo->countByParam($param));

        $this->repo->insertIgnoreRawValue($param, 'x');
        self::assertSame(1, $this->repo->countByParam($param));

        $this->repo->deleteByParam($param);
        self::assertSame(0, $this->repo->countByParam($param));
    }

    /**
     * A fresh EntityManager/repository, bypassing the first one's identity
     * map -- otherwise find() would return the same in-memory object
     * upsert() already mutated, which wouldn't prove the write actually
     * reached the database.
     */
    private function freshRepo(): ConfigRepository
    {
        $conn = DbConnection::build();
        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Piwigo'], isDevMode: true);
        $ormConfig->enableNativeLazyObjects(true);
        $em = new EntityManager($conn, $ormConfig);
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener(\Piwigo\Db\DbCredentials::current()));

        $repo = $em->getRepository(ConfigEntry::class);
        self::assertInstanceOf(ConfigRepository::class, $repo);

        return $repo;
    }
}
