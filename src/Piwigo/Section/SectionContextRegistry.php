<?php

declare(strict_types=1);

namespace Piwigo\Section;

/**
 * Per-request holder for the current SectionContext.
 *
 * Every reader/writer (`SectionPopulator`, `Piwigo\Url\UrlService`) takes
 * it via constructor injection.
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
