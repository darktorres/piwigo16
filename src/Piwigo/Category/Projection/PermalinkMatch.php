<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\OldPermalinkLookupInterface::findPermalinkMatches()}'s
 * per-permalink match row -- lives in `Category` (L2aCoreDomain), not
 * `Permalink` (L2bExtendedDomain), since the interface itself does (see
 * that interface's own docblock on the layering seam this avoids).
 * `permalink` itself isn't carried -- the real result is already keyed by
 * it, and no real caller reads it back off the row.
 */
final readonly class PermalinkMatch
{
    public function __construct(
        public int $catId,
        public bool $isOld,
    ) {}
}
