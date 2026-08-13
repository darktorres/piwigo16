<?php

declare(strict_types=1);

namespace Piwigo\Core;

use RuntimeException;

/**
 * Container-shared instance holding the current request's
 * `ThemeConfProviderInterface` implementation.
 *
 * Deliberately *not* a delegate to `Piwigo\Template\CurrentTemplate`, even
 * though `Template implements ThemeConfProviderInterface` and is the only
 * real production implementation: `ThemeConfProviderInterface` exists
 * specifically so `Piwigo\Image\SrcImage` (L2aCoreDomain) can depend
 * downward on it instead of upward on the concrete `Template`
 * (L3Presentation, deptrac-forbidden) -- see that interface's own
 * docblock. Delegating to `CurrentTemplate::get(): Template` (a
 * concrete-typed return) would silently reintroduce that same forbidden
 * coupling in spirit, and would also make it impossible for a test to
 * inject a fake `ThemeConfProviderInterface` without constructing a real
 * `Template` (a much heavier fixture) -- this wrapper keeps both the
 * layering and the test seam independent of `CurrentTemplate`, even
 * though `RequestBootstrap::finalize()` seeds both with the same request
 * `Template` instance in practice.
 *
 * No `current()` service-locator method -- `SrcImage::themeConf()` (the
 * one real caller) resolves this class from the DI container directly,
 * matching its sibling collaborator methods
 * (`urlService()`/`currentConfig()`). `RequestBootstrap::finalize()`
 * (the other real caller, for `set()`) already did the same.
 */
final class CurrentThemeConfProvider
{
    private ?ThemeConfProviderInterface $provider = null;

    public function get(): ThemeConfProviderInterface
    {
        if (! $this->provider instanceof ThemeConfProviderInterface) {
            throw new RuntimeException('SrcImage: no theme-conf provider set (Template not constructed yet?)');
        }

        return $this->provider;
    }

    public function set(ThemeConfProviderInterface $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on CurrentTemplate's/CurrentUser's own reset()
     * methods.
     */
    public function reset(): void
    {
        $this->provider = null;
    }
}
