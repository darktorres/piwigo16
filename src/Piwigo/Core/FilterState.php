<?php

declare(strict_types=1);

namespace Piwigo\Core;

use LogicException;

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
 * L2aCoreDomain, and L2a may not depend sideways on L2b -- L2a may depend
 * *downward* on L1 without issue, so this placement doesn't block
 * constructor injection into either.
 *
 * Singleton/service-locator elimination campaign, Phase 2: converted from
 * a self-managed static facade to a container-shared instance.
 * `FilterService`/`RequestBootstrap` (the real writers), `SectionPopulator`,
 * `Category\CategoryService::getCategoriesMenu()`, `Menu\MenubarRenderer::
 * render()`, and `Controller\PictureController` all take it via
 * constructor/explicit-parameter injection. `Permission\PermissionService::
 * getSqlConditionFandFAsCondition()` is the one exception: it has ~30 real
 * callers, several inside the still-static `Ws\Pwg*` dispatch layer (Phase
 * 10) -- see the 3 `*Static()` shims below.
 */
final class FilterState
{
    private bool $enabled = false;

    private string $visibleCategories = '';

    private string $visibleImages = '';

    /**
     * Normally CategoryService::getComputedCategories()'s own 'categories'
     * shape, keyed by cat_id -- but FilterService::initializeFromRequest()
     * (the sole writer) may also restore this from a session-stored
     * unserialize() result, which can't be statically verified to match
     * that shape (a corrupted/stale session value deserializes to
     * whatever it deserializes to). Deliberately loose here; the real
     * per-row shape is only assumed by updateCatsWithFilteredData()'s own
     * `?? null`-guarded per-field reads, never relied on wholesale.
     *
     * @var array<int|string, array<int|string, mixed>>
     */
    private array $categories = [];

    private bool $initialized = false;

    /**
     * @param array<int|string, array<int|string, mixed>> $categories
     */
    public function set(bool $enabled, string $visibleCategories = '', string $visibleImages = '', array $categories = []): void
    {
        $this->enabled = $enabled;
        $this->visibleCategories = $visibleCategories;
        $this->visibleImages = $visibleImages;
        $this->categories = $categories;
        $this->initialized = true;
    }

    public function isEnabled(): bool
    {
        $this->assertInitialized();

        return $this->enabled;
    }

    public function visibleCategories(): string
    {
        $this->assertInitialized();

        return $this->visibleCategories;
    }

    public function visibleImages(): string
    {
        $this->assertInitialized();

        return $this->visibleImages;
    }

    /**
     * @return array<int|string, array<int|string, mixed>>
     */
    public function categories(): array
    {
        $this->assertInitialized();

        return $this->categories;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    private function assertInitialized(): void
    {
        if (! $this->initialized) {
            throw new LogicException('FilterState not initialised -- call Piwigo\Filter\FilterService::initializeFromRequest() (or RequestBootstrap::finalize()\'s own disabled-filter fallback) first.');
        }
    }

    public function reset(): void
    {
        $this->enabled = false;
        $this->visibleCategories = '';
        $this->visibleImages = '';
        $this->categories = [];
        $this->initialized = false;
    }
}
