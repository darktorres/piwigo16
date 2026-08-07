<?php

declare(strict_types=1);

namespace Piwigo\Url;

/**
 * Request-scoped ref-counted override for UrlService::getRootUrl()'s
 * "root_path".
 *
 * A container-shared instance, constructor-injected into `UrlService`
 * directly. Its own cross-instance sharing requirement -- `setMakeFullUrl()`
 * called on one `UrlService` instance (e.g. `UploadService`'s
 * constructor-injected one) must be visible to a *different* instance's
 * later `getRootUrl()` read (e.g. `Image\DerivativeImage`'s own internal
 * `urlService()` bridge) -- is why every real `UrlService` construction
 * site, with zero exceptions, must resolve this same container-shared
 * instance rather than a fresh one: PHP-DI's default autowiring-and-sharing
 * already provides this for free, as long as nothing bypasses it.
 *
 * All 4 real usages are internal to `UrlService` itself -- no external
 * caller needs to reach this directly.
 *
 * "No active override" just means callers fall back to reading
 * SectionContextRegistry::current()->rootPath again on their own --
 * push()/pop() don't need to remember or restore anything themselves.
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
