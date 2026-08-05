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
 * PictureModifyPageRenderer`, `Piwigo\Admin\BatchManagerUnitPageRenderer`,
 * `Piwigo\Html\HtmlService`, and `Piwigo\Users\UserService` all
 * constructor-inject it directly. `Piwigo\Core\RecentIconResolver` (a
 * genuinely static-only utility, no constructor of its own) closed its
 * own former `*Static()` shim usage in Phase 11 sub-phase 11G by taking
 * this as an explicit method parameter instead.
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
}
