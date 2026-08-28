<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * One row of the search sidebar's "added by" filter: a user, and how many
 * photos in the current filter scope they added (P58-A).
 *
 * `$addedById` keeps `int|string|null` rather than being narrowed. The
 * column is DQL-hydrated as a native `?int`, but the row set also comes
 * back out of the persistent cache pool, where it is mixed; and the
 * surrounding code already treats `is_int() || is_string()` as the real
 * gate, deliberately, because narrowing to `is_string()` alone silently
 * filtered every id out. `null` is a photo whose uploader no longer
 * resolves, which the template renders as an empty id exactly as before.
 *
 * `$addedByName` is resolved from the id through
 * `UserService::getUsernamesByIds()`, falling back to the
 * `user #N (deleted)` label the producer already built.
 */
final readonly class AddedByFilterCount
{
    public function __construct(
        public int|string|null $addedById,
        public string $addedByName,
        public int $counter,
    ) {}
}
