<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Piwigo\Activity\Details\EmptyDetails;

/**
 * Immutable record of a single action to be persisted to the activity log.
 *
 * `$objectId` is `int` for the common single-target case (a user id, a
 * photo id, …), `int[]` when one logical action touches several objects
 * (bulk edit / delete), or `ActivitySystem` constant (`Core` / `Plugin` /
 * `Theme`) for system-scoped events.
 */
final readonly class ActivityEvent
{
    /** @param int|int[] $objectId */
    public function __construct(
        public ActivityObject   $object,
        public int|array        $objectId,
        public ActivityAction   $action,
        public ActivityDetails  $details = new EmptyDetails(),
    ) {
    }
}
