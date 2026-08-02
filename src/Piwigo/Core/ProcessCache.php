<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Generic per-request memoization registry -- Legacy Coupling Retirement
 * Track A gap-fill batch G5, replacing the legacy `global $cache;` bag.
 * Unlike Piwigo\Config\Config (a config snapshot with well-known keys),
 * this is a plain, unstructured key/value store: unrelated call sites each
 * memoize their own one-off computation under their own key (e.g.
 * `'cat_names'`, `'get_icon'`, `'default_user'`,
 * `self::class . '::tagAlphaCompare'`) for the remainder of the same
 * request/process. Every consumer already defensively checks has()/reads
 * with a `?? null` fallback before first use.
 *
 * Singleton/service-locator elimination campaign, Phase 1: converted from
 * a self-managed static facade to a container-shared instance.
 * `Piwigo\Permalink\PermalinkService`, `Piwigo\Admin\
 * PictureModifyPageRenderer`, and `Piwigo\Admin\BatchManagerUnitPageRenderer`
 * constructor-inject it directly. `Piwigo\Html\HtmlService` (444 manual
 * `new` construction sites, unrelated cleanup out of scope here),
 * `Piwigo\Template\Template` (constructed with runtime-computed
 * root-path/theme args, never autowireable as-is), `Piwigo\Users\
 * UserService` (Phase 4/8 territory, large fan-out), and
 * `Piwigo\Core\RecentIconResolver` (a genuinely static-only utility, no
 * constructor of its own) aren't converted, so they keep calling the
 * `*Static()` shims below.
 */
final class ProcessCache
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Test-only -- production code never needs to clear this mid-request.
     */
    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection -- see this class's own docblock for the
     * current list. PHP forbids an instance method and a static method
     * sharing one name, hence the `Static` suffix (not a rename of the
     * real API -- the instance methods above are the real ones; these are
     * scaffolding only). Delete once `grep -rn "ProcessCache::hasStatic("`
     * outside tests/ returns nothing (and the sibling get/set/forget
     * shims below are equally clear).
     *
     * Falls back to `false` (the same result a never-populated key always
     * produced) when `Kernel::isBooted()` is false -- a great many tests
     * reach these callers indirectly without ever booting the Kernel, and
     * none of them relied on cross-request memoization surviving that.
     */
    public static function hasStatic(string $key): bool
    {
        return self::sharedInstance()?->has($key) ?? false;
    }

    /**
     * @deprecated transitional bridge, see hasStatic()'s own docblock.
     * Falls back to `null` (the same result a never-populated key always
     * produced) when Kernel::isBooted() is false.
     */
    public static function getStatic(string $key): mixed
    {
        return self::sharedInstance()?->get($key);
    }

    /**
     * @deprecated transitional bridge, see hasStatic()'s own docblock.
     * Silently does nothing when Kernel::isBooted() is false -- memoizing
     * into a cache that's about to be thrown away (no container, no
     * request) has no observable effect either way.
     */
    public static function setStatic(string $key, mixed $value): void
    {
        self::sharedInstance()?->set($key, $value);
    }

    /**
     * @deprecated transitional bridge, see hasStatic()'s own docblock.
     */
    public static function forgetStatic(string $key): void
    {
        self::sharedInstance()?->forget($key);
    }

    private static function sharedInstance(): ?self
    {
        if (! Kernel::isBooted()) {
            return null;
        }

        $instance = Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new \LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance;
    }
}
