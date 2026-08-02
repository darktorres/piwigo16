<?php

declare(strict_types=1);

namespace Piwigo\Activity;

/**
 * Further SQL-modernization audit, Item 14 Sub-phase B3: replaces the ad
 * hoc `list<SqlCondition>` that `Ws\PwgCore::getActivityList()` used to
 * build and combine itself before handing the finished raw fragment to
 * {@see ActivityRepository::findPaginated()}. One immutable object built
 * once by the caller from its own already-validated `$param`, passed
 * straight through -- `findPaginated()` itself now decides how each field
 * translates into a DQL condition, replacing the caller-built
 * `SqlCondition::combine('AND', ...)` chain with real, readable
 * per-repository code (same shape {@see \Piwigo\Comment\CommentApiCriteria}
 * already established).
 *
 * $minDate/$maxDate are already `Y-m-d H:i:s`-formatted by the caller
 * (`date_format($dateTime, ...)` can't emit SQL metacharacters), same as
 * the original `SqlCondition` values did. $adminIds is only meaningful
 * when $connectionsMode is `'admins_only'` -- the caller already resolves
 * the real admin id list before constructing this object (same
 * responsibility split as today: the caller resolves ids, the repository
 * only consumes already-resolved values), since `ActivityRepository`
 * itself has no reason to gain a new cross-domain `Users` dependency just
 * to look them up on its own.
 */
final readonly class ActivityListCriteria
{
    /**
     * @param list<int> $adminIds
     */
    public function __construct(
        public ?int $performedBy = null,
        public ?string $action = null,
        public ?string $object = null,
        public ?string $minDate = null,
        public ?string $maxDate = null,
        public ?int $objectId = null,
        public string $connectionsMode = 'all',
        public array $adminIds = [],
    ) {}
}
