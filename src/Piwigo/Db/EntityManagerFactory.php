<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Piwigo\Cache\CachePools;
use Piwigo\Core\Kernel;
use Piwigo\Db\DqlFunction\DateFormatMonthDayFunction;
use Piwigo\Db\DqlFunction\DateFormatYearMonthFunction;
use Piwigo\Db\DqlFunction\DateSubFunction;
use Piwigo\Db\DqlFunction\DayOfMonthFunction;
use Piwigo\Db\DqlFunction\DayOfWeekFunction;
use Piwigo\Db\DqlFunction\GroupConcatFunction;
use Piwigo\Db\DqlFunction\MonthFunction;
use Piwigo\Db\DqlFunction\RandFunction;
use Piwigo\Db\DqlFunction\RegexpFunction;
use Piwigo\Db\DqlFunction\SubstringIndexFunction;
use Piwigo\Db\DqlFunction\WeekdayFunction;
use Piwigo\Db\DqlFunction\WeekFunction;
use Piwigo\Db\DqlFunction\YearFunction;
use Piwigo\Db\Type\CategoryIdType;
use Piwigo\Db\Type\CommentIdType;
use Piwigo\Db\Type\GroupIdType;
use Piwigo\Db\Type\ImageIdType;
use Piwigo\Db\Type\IpAddressType;
use Piwigo\Db\Type\LangCodeType;
use Piwigo\Db\Type\Md5SumType;
use Piwigo\Db\Type\PermalinkType;
use Piwigo\Db\Type\PluginIdType;
use Piwigo\Db\Type\TagIdType;
use Piwigo\Db\Type\ThemeIdType;
use Piwigo\Db\Type\UserIdType;

/**
 * Factory for a Doctrine ORM EntityManager -- the ORM counterpart to
 * DbConnection::build(). Extracted from config/container.php's own
 * EntityManagerInterface factory (which now delegates here) so that
 * callers structurally unable to receive it via constructor injection
 * (a static L1Infrastructure method, a self-managed singleton's fallback
 * branch, a test helper deliberately bypassing full app bootstrap) have a
 * direct path to a working EntityManager, same as DbConnection::build()
 * already gives every layer a direct path to a Connection. Lazy, like
 * DbConnection::build() itself -- constructing an EntityManager/resolving
 * an EntityRepository doesn't touch the DB until a real query runs.
 */
final class EntityManagerFactory
{
    /**
     * Direct container resolve, not the DbCredentials::current() shim --
     * this class is a purely static factory (see this class's own
     * docblock), matching FilesystemHelper's own established "no wrapper
     * instance" precedent. Mirrors DbCredentials::current()'s own graceful
     * degradation (a fresh fromEnv() read, not a throw) when Kernel isn't
     * booted -- most callers of build() are plain Unit tests that never
     * boot a Kernel at all.
     */
    private static function dbCredentials(): DbCredentials
    {
        if (Kernel::isBooted()) {
            $dbCredentials = Kernel::container()->get(DbCredentials::class);
            if ($dbCredentials instanceof DbCredentials) {
                return $dbCredentials;
            }
        }

        return DbCredentials::fromEnv();
    }

    public static function build(?Connection $conn = null): EntityManagerInterface
    {
        // Guarded by hasType() since this factory is deliberately not
        // memoized (called fresh per-request/per-test) and addType()
        // throws on double-registration.
        foreach ([
            'group_id' => GroupIdType::class,
            'user_id' => UserIdType::class,
            'category_id' => CategoryIdType::class,
            'ip_address' => IpAddressType::class,
            'comment_id' => CommentIdType::class,
            'tag_id' => TagIdType::class,
            'image_id' => ImageIdType::class,
            'md5sum' => Md5SumType::class,
            'permalink' => PermalinkType::class,
            'theme_id' => ThemeIdType::class,
            'plugin_id' => PluginIdType::class,
            'lang_code' => LangCodeType::class,
        ] as $name => $class) {
            if (! Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }

        // The dbCredentials()->prefix half of this cache key is
        // load-bearing, not defensive: TablePrefixListener below (registered
        // on Events::loadClassMetadata) only fires on a real metadata
        // *miss* -- it's what turns an entity's bare table name ('config')
        // into the real, connection-specific one ('piwigo_config'). Caching
        // metadata keyed on entity mtimes alone (this cache's first version)
        // meant the *first* prefix ever seen got baked into the cached
        // metadata and silently reused for every later build() call
        // regardless of which connection's prefix was actually current --
        // confirmed live as a real regression, not theoretical:
        // InstallWizardTest legitimately calls build() against many
        // different temp databases (DbCredentials::current() is a mutable,
        // container-shared instance specifically so InstallWizard's own
        // mid-request seed() call takes effect for later reads -- see its
        // own docblock) within one process, and 6 of its 23 tests failed
        // with "Table '<temp db>.piwigo_config' doesn't exist" once
        // metadata caching landed without this. Hashed (md5), not
        // concatenated raw, because a real install's prefix is
        // user-submitted input, not a trusted config constant.
        $config = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__)],
            isDevMode: true,
            cache: CachePools::doctrineMetadata(self::entityMtimeHash() . '.' . md5(self::dbCredentials()->prefix)),
        );
        $config->enableNativeLazyObjects(true);
        $config->addCustomStringFunction('REGEXP', RegexpFunction::class);
        $config->addCustomStringFunction('GROUP_CONCAT', GroupConcatFunction::class);
        $config->addCustomStringFunction('SUBSTRING_INDEX', SubstringIndexFunction::class);
        $config->addCustomNumericFunction('RAND', RandFunction::class);
        // Overrides Doctrine ORM's own built-in DATE_SUB -- see
        // DateSubFunction's own docblock for the real Postgres bug this
        // fixes (custom function lookup runs before the built-in one in
        // Doctrine's own parser, confirmed by reading its source).
        $config->addCustomDatetimeFunction('DATE_SUB', DateSubFunction::class);
        $config->addCustomStringFunction('DATE_FORMAT_YEAR_MONTH', DateFormatYearMonthFunction::class);
        $config->addCustomStringFunction('DATE_FORMAT_MONTH_DAY', DateFormatMonthDayFunction::class);
        $config->addCustomNumericFunction('DAYOFMONTH', DayOfMonthFunction::class);
        $config->addCustomNumericFunction('DAYOFWEEK', DayOfWeekFunction::class);
        $config->addCustomNumericFunction('WEEKDAY', WeekdayFunction::class);
        $config->addCustomNumericFunction('WEEK', WeekFunction::class);
        $config->addCustomNumericFunction('YEAR', YearFunction::class);
        $config->addCustomNumericFunction('MONTH', MonthFunction::class);

        $em = new EntityManager($conn ?? DbConnection::build(), $config);
        $em->getEventManager()
            ->addEventListener(Events::loadClassMetadata, new TablePrefixListener(self::dbCredentials()));

        return $em;
    }

    /**
     * A hash of every `*Entity.php` file's mtime under src/Piwigo/, folded
     * into CachePools::doctrineMetadata()'s own namespace -- editing an
     * entity's attributes lands the very next build() in a fresh, disjoint
     * cache namespace automatically, no manual clear and no staleness
     * window at all.
     *
     * Reads Composer's own generated classmap (vendor/composer/
     * autoload_classmap.php -- the same file tools/opcache-preload.php
     * already uses) instead of walking the filesystem: a first version of
     * this method used RecursiveDirectoryIterator to scan all of
     * src/Piwigo's ~840 files looking for the ~40 real ones, and a real
     * Xdebug re-profile after wiring it in caught that the directory
     * traversal itself (not the handful of filemtime() calls on actual
     * matches) was ~17-18% of bootstrap time on its own -- a straight
     * regression versus the Reflection-based metadata rebuild this whole
     * cache exists to avoid. Filtering the classmap array in memory and
     * calling filemtime() on only the already-known matching paths skips
     * the directory walk entirely, so this needs no TTL/caching of its own
     * to be cheap, and stays exactly-correct (no staleness window) rather
     * than trading correctness for speed.
     *
     * Not a hardcoded file list: matching by filename suffix against
     * whatever the classmap currently contains self-updates as entities
     * are added/removed, matching this codebase's actual convention
     * (confirmed live: 38 of 40 real `#[ORM\Entity]` classes follow it;
     * the 2 exceptions -- ImageStdParams/ConfigEntry, both stable
     * config-shape entities rarely edited -- are a known, accepted gap).
     *
     * Memoized in $entityMtimeHashCache -- NOT the same "not memoized"
     * design as build() itself (that's about never reusing a whole
     * EntityManager/Configuration instance across calls). build() has 201
     * real call sites across this codebase and is called ~35 times in a
     * single admin.php request (confirmed live); the hash is invariant for
     * the entire duration of one request (entity files cannot change
     * mid-request), so recomputing it fresh on every one of those 35 calls
     * is pure redundant work -- confirmed live, via a controlled stash/
     * restore A/B comparison under identical conditions, that skipping this
     * memoization made real `%D` timing *worse* than the original
     * Reflection-rebuild-every-call baseline this cache exists to beat
     * (~35 calls x ~2-3ms each is real, additive per-request cost, even
     * though each individual call is cheap in isolation). A plain static
     * property is exactly "once per request" here: PHP tears down all
     * static state between separate HTTP requests in this SAPI model, so
     * there is no cross-request staleness risk to reason about.
     */
    private static ?string $entityMtimeHashCache = null;

    private static function entityMtimeHash(): string
    {
        if (self::$entityMtimeHashCache !== null) {
            return self::$entityMtimeHashCache;
        }

        /** @var array<string, string> $classMap */
        $classMap = require dirname(__DIR__, 3) . '/vendor/composer/autoload_classmap.php';

        $mtimes = [];

        foreach ($classMap as $class => $file) {
            if (str_starts_with($class, 'Piwigo\\') && str_ends_with($file, 'Entity.php')) {
                $mtime = filemtime($file);
                if ($mtime !== false) {
                    $mtimes[] = $mtime;
                }
            }
        }

        sort($mtimes);

        return self::$entityMtimeHashCache = md5(implode(',', $mtimes));
    }
}
