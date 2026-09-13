<?php

declare(strict_types=1);

namespace Piwigo\Section\Event;

/**
 * Counterpart to {@see \Piwigo\Image\Event\GetIndexDerivativeParams}/
 * {@see \Piwigo\Image\Event\GetCategoryDerivativeParams} -- legacy never
 * dispatched a filter around the per-page photo count at all (it was
 * always a hardcoded read of the current user's own stored preference),
 * so this has no legacy hook name to trace back to. Added for a real
 * caller (gdThumb, `docs/plugin-porting/gdthumb-port-analysis.md`): its
 * own legacy `init` hook force-overrides `$user['nb_image_page']`/
 * `$page['nb_image_page']` site-wide while active, which has no
 * achievable 1:1 equivalent (`CurrentUser` has no per-request override
 * mutator), but the same *effect* -- this request's own page size -- is
 * reachable by wrapping `SectionPopulator::populate()`'s own hardcoded
 * `CurrentUser::get()->rawAttributes['nb_image_page']` read.
 */
final class GetNbImagePage
{
    public function __construct(
        public int $value,
    ) {}
}
