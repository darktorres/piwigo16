<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Creates a PSR-6 cache pool for the requested (or env-configured) backend.
 *
 * Adapter choice via PIWIGO_CACHE_ADAPTER (apcu|redis|filesystem). When
 * unset, auto-detects: APCu if available, else filesystem; ext-apcu isn't
 * installed in every environment. An
 * *explicit* request for an unavailable adapter (PIWIGO_CACHE_ADAPTER=apcu
 * with no ext-apcu) fails loudly instead of silently falling back -- that's
 * a real misconfiguration worth surfacing, unlike the auto-detect default
 * case.
 *
 * `$namespace`/`$defaultLifetime` let CachePools build several independent,
 * non-colliding pools sharing one backend -- every Symfony cache adapter
 * used here accepts both (ApcuAdapter/FilesystemAdapter as their first two
 * constructor params; RedisAdapter as its 2nd/3rd, after the required
 * connection). 'piwigo'/0 remain the defaults, matching the single generic
 * pool wired in config/container.php.
 */
final class CacheFactory
{
    public static function create(?string $adapter = null, string $namespace = 'piwigo', int $defaultLifetime = 0): CacheItemPoolInterface
    {
        $explicit = $adapter ?? self::envAdapter();
        $resolved = $explicit ?? (ApcuAdapter::isSupported() ? 'apcu' : 'filesystem');
        $namespace = self::scopeToDatabase($namespace);

        return match ($resolved) {
            'apcu' => self::buildApcu($namespace, $defaultLifetime),
            'redis' => self::buildRedis($namespace, $defaultLifetime),
            'filesystem' => self::buildFilesystem($namespace, $defaultLifetime),
            default => throw new InvalidArgumentException(
                "Unknown cache adapter '{$resolved}'. Expected apcu, redis, or filesystem."
            ),
        };
    }

    /**
     * Every real call site here hardcodes a fixed namespace string
     * ('piwigo.config', 'piwigo.category_tree', ...) -- none of them are
     * derived from which database the cached content actually describes.
     * `PIWIGO_DB_BASE` (the real, connected-to database name -- `piwigo`
     * live vs `piwigo_test`, or a distinct name per worktree/parallel test
     * worker, see Env::testModeHeader()'s own `test-wN` support) is the
     * one signal that already correctly captures that distinction in
     * every real case, so folding it into the namespace here, once, fixes
     * every call site at once rather than needing each of the dozen-plus
     * `CacheFactory::create()` call sites to do it individually.
     *
     * Found live: a shared dev Apache instance serving both the live
     * (`piwigo`) and test (`piwigo_test`) databases via the
     * `X-Piwigo-Env` header kept serving one database's own cached
     * `gallery_title`/`page_banner` to the OTHER database's requests --
     * whichever database's request warmed the shared, unscoped
     * `piwigo.config` pool first "won" for every later request
     * regardless of which database it was actually for. For the
     * filesystem adapter this is a same-worktree, same-`_data/cache/`
     * collision; for APCu (a single shared-memory segment for the whole
     * machine, not per-worktree) the same unscoped namespace would
     * collide across every worktree on this box sharing that adapter,
     * not just live vs test within one -- a real cross-worktree data
     * leak, not merely a local annoyance.
     */
    private static function scopeToDatabase(string $namespace): string
    {
        $dbBase = getenv('PIWIGO_DB_BASE');

        if ($dbBase === false || $dbBase === '') {
            return $namespace;
        }

        return $namespace . '.' . self::toNamespaceSegment($dbBase);
    }

    /**
     * Symfony's pool namespaces accept only `[-+_.A-Za-z0-9]` and throw on
     * anything else, so `PIWIGO_DB_BASE` cannot be folded in raw: sqlite
     * accepts `:memory:` (and a plain file path) as a real database name,
     * which `DbMaintenanceRepositorySqliteTest` sets for real. Any
     * `CacheFactory::create()` reached while that value is in the
     * environment threw `Namespace contains ":"` before this sanitized.
     *
     * Only the env-derived half is sanitized. The `$namespace` argument is
     * developer-controlled and hardcoded at every call site ('piwigo.config',
     * ...), so a bad one there should keep failing loudly rather than being
     * silently rewritten.
     *
     * The hash suffix is what keeps the mapping injective: `:memory:` and
     * `/memory/` both sanitize to `_memory_`, and two databases sharing a
     * pool is the exact cross-database leak this scoping exists to prevent.
     * Values that are already legal are left untouched, so the common case
     * stays readable on disk.
     */
    private static function toNamespaceSegment(string $dbBase): string
    {
        $safe = preg_replace('#[^-+_.A-Za-z0-9]#', '_', $dbBase) ?? '';

        if ($safe === $dbBase) {
            return $safe;
        }

        return $safe . '-' . substr(hash('sha256', $dbBase), 0, 8);
    }

    private static function envAdapter(): ?string
    {
        $env = getenv('PIWIGO_CACHE_ADAPTER');

        return $env !== false && $env !== '' ? $env : null;
    }

    private static function buildApcu(string $namespace, int $defaultLifetime): CacheItemPoolInterface
    {
        if (! ApcuAdapter::isSupported()) {
            throw new RuntimeException(
                'APCu extension is not available. Install/enable ext-apcu or choose a different PIWIGO_CACHE_ADAPTER.'
            );
        }

        return new ApcuAdapter($namespace, $defaultLifetime);
    }

    private static function buildFilesystem(string $namespace, int $defaultLifetime): CacheItemPoolInterface
    {
        return new FilesystemAdapter($namespace, $defaultLifetime, dirname(__DIR__, 3) . '/_data/cache/');
    }

    private static function buildRedis(string $namespace, int $defaultLifetime): CacheItemPoolInterface
    {
        $dsn = getenv('PIWIGO_REDIS_DSN');
        $dsn = $dsn !== false && $dsn !== '' ? $dsn : 'redis://localhost:6379';

        $client = RedisAdapter::createConnection($dsn);

        return new RedisAdapter($client, $namespace, $defaultLifetime);
    }
}
