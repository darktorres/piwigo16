<?php

declare(strict_types=1);

namespace Piwigo\Section;

/**
 * Per-request holder for the current SectionContext.
 *
 * Singleton/service-locator elimination campaign, Phase 2: converted from
 * a self-managed static facade to a container-shared instance. Every real
 * reader/writer (`SectionPopulator`, `Piwigo\Url\UrlService`) takes it via
 * constructor injection -- Phase 11 sub-phase 11L confirmed zero remaining
 * callers of the former `currentStatic()` transitional shim (deleted).
 */
final class SectionContextRegistry
{
    private ?SectionContext $current = null;

    public function set(SectionContext $context): void
    {
        $this->current = $context;
    }

    public function current(): ?SectionContext
    {
        return $this->current;
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
