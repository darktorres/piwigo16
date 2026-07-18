<?php

declare(strict_types=1);

namespace Piwigo\Url;

/**
 * Request-scoped ref-counted override for UrlService::getRootUrl()'s
 * "root_path" -- Legacy Coupling Retirement Track A batch A5.2e replaces
 * the former `global $page['save_root_path']`/`['root_path']` mutation
 * pair (UrlService::setMakeFullUrl()/unsetMakeFullUrl()) with this.
 *
 * UrlService is `final readonly class` (unlike 16.x-rewrite's own plain
 * `final class`, which used two simple `private static` properties for
 * this same ref-counting stack) -- PHP forbids `static` properties inside
 * a `readonly class`, so this state can't live on UrlService itself.
 * Static methods here instead, same shape as SectionContextRegistry --
 * required anyway since every real caller (Url\functions.php's
 * set_make_full_url()/unset_make_full_url() free functions) constructs a
 * brand new UrlService instance per call, so a per-instance property
 * couldn't share ref-count state across calls the way the original
 * global did.
 *
 * Simpler than the original's own save/restore dance: the original had
 * to remember the previous `$page['root_path']` value (or its absence)
 * to restore it once the ref count reached zero, because it mutated that
 * global directly. Here, "no active override" just means callers fall
 * back to reading SectionContextRegistry::current()->rootPath again on
 * their own -- nothing to remember or restore.
 */
final class RootPathOverride
{
    private static int $count = 0;

    private static ?string $path = null;

    public static function push(string $absolutePath): void
    {
        if (self::$count === 0) {
            self::$path = $absolutePath;
        }
        self::$count++;
    }

    public static function pop(): void
    {
        if (self::$count === 0) {
            return;
        }
        self::$count--;
        if (self::$count === 0) {
            self::$path = null;
        }
    }

    public static function current(): ?string
    {
        return self::$count > 0 ? self::$path : null;
    }

    /**
     * Tests only -- production code never needs to clear this mid-request.
     */
    public static function reset(): void
    {
        self::$count = 0;
        self::$path = null;
    }
}
