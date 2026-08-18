<?php

declare(strict_types=1);

namespace Piwigo\Asset\Event;

use Piwigo\Asset\AssetContribution;

/**
 * Dispatched once per page, before the layout renders, so a plugin can
 * contribute assets without needing a property on core's typed View
 * classes (which it can't add to). The one genuine extension point in
 * the three-source design `PageAssets` assembles from -- see that
 * class's own docblock. `Get*`-prefixed, matching this codebase's
 * established filter-event convention (mutable field, dispatch returns
 * the event, caller reads the mutated field -- e.g.
 * `Image\Event\GetHighUrl`, `Users\Event\GetUserListRows`).
 *
 * No real dispatch site yet -- `PageAssets`'s current callers are all
 * unit tests. Its first real caller is a page migrated onto the new
 * mechanism starting in P40 (see `docs/PLAN.md`'s P36 section).
 */
final class GetPageAssets
{
    /**
     * @param list<AssetContribution> $assets
     */
    public function __construct(
        public array $assets = [],
    ) {}
}
