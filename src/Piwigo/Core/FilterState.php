<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed reader/writer for the current request's recent-content filter --
 * Phase 2 global-residual sweep, replacing the legacy `global $filter;`
 * bridge. `Filter\FilterService::initializeFromRequest()` (plus
 * `Bootstrap\RequestBootstrap::finalize()`'s own disabled-filter
 * fallback) is the sole writer.
 *
 * Deliberately lives in `Piwigo\Core` (L1Infrastructure in
 * `deptrac.yaml`), not `Piwigo\Filter` (L2bExtendedDomain) where it would
 * more "naturally" sit next to `FilterService` -- 2 of the 5 real readers
 * (`Permission\PermissionService`, `Category\CategoryService`) are
 * L2aCoreDomain, and L2a may not depend sideways on L2b. This is almost
 * certainly *why* `$filter` was still a raw global after all of Phase 1:
 * `deptrac.yaml`'s own comment on Filter's layer placement already notes
 * it "reads/mutates a plain category-row array via the $filter global, no
 * domain-namespace dependency of its own" -- i.e. the raw global was very
 * likely a deliberate original workaround for this exact layering
 * conflict. Same shape as `PageState` (also L1Infrastructure for the same
 * kind of reason): a self-managed static instance, not constructor-
 * injected DI, so every reader can reach it regardless of its own layer
 * with zero constructor changes.
 */
final class FilterState
{
    private static bool $enabled = false;

    private static string $visibleCategories = '';

    private static string $visibleImages = '';

    /**
     * @var array<int|string, mixed>
     */
    private static array $categories = [];

    private static bool $initialized = false;

    /**
     * @param array<int|string, mixed> $categories
     */
    public static function set(bool $enabled, string $visibleCategories = '', string $visibleImages = '', array $categories = []): void
    {
        self::$enabled = $enabled;
        self::$visibleCategories = $visibleCategories;
        self::$visibleImages = $visibleImages;
        self::$categories = $categories;
        self::$initialized = true;
    }

    public static function isEnabled(): bool
    {
        self::assertInitialized();

        return self::$enabled;
    }

    public static function visibleCategories(): string
    {
        self::assertInitialized();

        return self::$visibleCategories;
    }

    public static function visibleImages(): string
    {
        self::assertInitialized();

        return self::$visibleImages;
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function categories(): array
    {
        self::assertInitialized();

        return self::$categories;
    }

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    private static function assertInitialized(): void
    {
        if (! self::$initialized) {
            throw new \LogicException('FilterState not initialised -- call Piwigo\Filter\FilterService::initializeFromRequest() (or RequestBootstrap::finalize()\'s own disabled-filter fallback) first.');
        }
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on SessionService's/Config's own reset() methods.
     */
    public static function reset(): void
    {
        self::$enabled = false;
        self::$visibleCategories = '';
        self::$visibleImages = '';
        self::$categories = [];
        self::$initialized = false;
    }
}
