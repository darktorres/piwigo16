<?php

declare(strict_types=1);

namespace Piwigo\Core;

use LogicException;
use RuntimeException;

/**
 * Container-shared instance holding the current request's
 * `ThemeConfProviderInterface` implementation -- singleton/service-locator
 * elimination campaign, Phase 6.
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
 */
final class CurrentThemeConfProvider
{
    private static ?self $fallback = null;

    private ?ThemeConfProviderInterface $provider = null;

    public static function current(): self
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(self::class);
            if (! $instance instanceof self) {
                throw new LogicException('Container returned an unexpected type for ' . self::class);
            }

            return $instance;
        }

        return self::$fallback ??= new self();
    }

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
