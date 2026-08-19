<?php

declare(strict_types=1);

namespace Piwigo\Common\Contract;

/**
 * Tags real row Projections that carry a `global_rank` value, so
 * {@see \Piwigo\Category\CategoryService::compareByGlobalRank()} can sort
 * them without an `array`-typed round trip.
 */
interface HasGlobalRank
{
    public function getGlobalRank(): ?string;
}
