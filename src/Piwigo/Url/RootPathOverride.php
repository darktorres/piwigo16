<?php

declare(strict_types=1);

namespace Piwigo\Url;

/**
 * Request-scoped ref-counted override for UrlService::getRootUrl()'s
 * "root_path" -- Legacy Coupling Retirement Track A batch A5.2e replaces
 * the former `global $page['save_root_path']`/`['root_path']` mutation
 * pair (UrlService::setMakeFullUrl()/unsetMakeFullUrl()) with this.
 *
 * Singleton/service-locator elimination campaign, Phase 6: converted from
 * a bare static (its own former docblock explained why a per-instance
 * property couldn't work, back when every real caller manually
 * constructed its own throwaway `UrlService`) to a container-shared
 * instance, constructor-injected into `UrlService` directly. Its own
 * cross-instance sharing requirement -- `setMakeFullUrl()` called on one
 * `UrlService` instance (e.g. `UploadService`'s constructor-injected one)
 * must be visible to a *different* instance's later `getRootUrl()` read
 * (e.g. `Image\DerivativeImage`'s own internal `urlService()` bridge) --
 * is why every real `UrlService` construction site, with zero exceptions,
 * must resolve this same container-shared instance rather than a fresh
 * one: PHP-DI's default autowiring-and-sharing already provides this for
 * free, as long as nothing bypasses it.
 *
 * All 4 real usages are internal to `UrlService` itself (confirmed by
 * direct grep) -- no external caller needs a transitional shim here.
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
    private int $count = 0;

    private ?string $path = null;

    public function push(string $absolutePath): void
    {
        if ($this->count === 0) {
            $this->path = $absolutePath;
        }
        $this->count++;
    }

    public function pop(): void
    {
        if ($this->count === 0) {
            return;
        }
        $this->count--;
        if ($this->count === 0) {
            $this->path = null;
        }
    }

    public function current(): ?string
    {
        return $this->count > 0 ? $this->path : null;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on CurrentTemplate's/CurrentUser's own reset()
     * methods.
     */
    public function reset(): void
    {
        $this->count = 0;
        $this->path = null;
    }
}
