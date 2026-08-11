<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Builds a real EntityManager backed by a throwaway in-memory SQLite
 * connection, but with getDatabasePlatform() forced (via reflection --
 * DBAL 4's Connection has no public platform-override constructor
 * param or params-array key, unlike DBAL 2/3's now-removed `'platform'`
 * params entry) to report whichever platform a test wants. Lets
 * Db\DqlFunction\*'s real per-platform getSql() branches be exercised
 * through the actual Doctrine parse()/SQL-generation pipeline -- not a
 * hand-faked SqlWalker, which would also need queryComponents/rsm state
 * correctly wired for any function that calls
 * walkStringPrimary()/walkSimpleArithmeticExpression() on a sub-node --
 * without needing a live MySQL/PostgreSQL server for whichever platform
 * this environment's real test DB connection isn't already using.
 *
 * Passes a fresh ArrayAdapter as EntityManagerFactory::build()'s own
 * $cache override -- its default CachePools::doctrineMetadata() pool
 * also backs Doctrine's *query* cache (ORMSetup::createConfig() wires
 * one PSR-6 pool to both setMetadataCache()/setQueryCache()), keyed by
 * the DQL string itself. Real, confirmed-live bug this fixes: every
 * DqlFunction test here builds one of a handful of near-identical DQL
 * strings, so without an isolated cache, the *first* process to ever
 * compile a given DQL string leaves its compiled SQL sitting in
 * `_data/cache/` on disk -- every later test/mutate run against that
 * same DQL string then gets that stale SQL back without
 * Db\DqlFunction\*'s getSql() ever running again, silently masking real
 * behavior changes (a hand-edited getSql() returning a hardcoded
 * sentinel string had zero effect on a repeated identical query's
 * output until the stale cache files were deleted). A fresh in-memory
 * ArrayAdapter per call can't leak across processes or tests at all.
 */
final class DqlPlatformQueryTestFactory
{
    public static function entityManagerForPlatform(AbstractPlatform $platform): EntityManagerInterface
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        new ReflectionProperty(Connection::class, 'platform')->setValue($conn, $platform);

        return EntityManagerFactory::build($conn, new ArrayAdapter());
    }

    /**
     * getSQL() only builds the SQL string -- it never opens a real
     * connection or touches data, so the throwaway in-memory SQLite
     * connection above never actually gets used for I/O regardless of
     * which platform it's forced to report.
     *
     * AbstractQuery::getSQL() is declared `string|array` -- the `array`
     * shape is Doctrine's own class-table-inheritance case (one SQL
     * statement per table), never reachable for the single-entity,
     * non-inherited queries every DqlFunction test here builds.
     */
    public static function generatedSql(AbstractPlatform $platform, string $dql): string
    {
        $sql = self::entityManagerForPlatform($platform)
            ->createQuery($dql)
            ->getSQL();

        if (! is_string($sql)) {
            throw new LogicException('Expected a single SQL string, got the class-table-inheritance array shape instead.');
        }

        return $sql;
    }
}
