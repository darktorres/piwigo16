<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Replaces the ad hoc `list<SqlCondition>` that
 * `Ws\PwgCore::getActivityList()` used to build and combine itself
 * before handing the finished raw fragment to
 * {@see ActivityRepository::findPaginated()}. One immutable object built
 * once by the caller from its own already-validated `$param`, passed
 * straight through -- `findPaginated()` itself decides how each field
 * translates into a DQL condition, same shape
 * {@see \Piwigo\Comment\CommentApiCriteria} also uses.
 *
 * $performedBy/$minDate/$maxDate/$adminIds are real VOs.
 * `ActivityEntity::$performedBy` is `?UserId`-typed, so
 * `ActivityRepository::findPaginated()` compares the VO directly rather
 * than unwrap-then-rewrap. $adminIds stays `list<UserId>` all the way
 * from its real producer (`UserRepository::findAdminIds()`, itself
 * already `list<UserId>`) through to the repository, which unwraps
 * `->value` only at its own `objectId` comparison (that column is a
 * genuinely polymorphic int -- see `$objectId`'s own docblock below --
 * so it can't be VO-typed itself, but the *admin id list* being compared
 * against it still can be). `$objectId` itself stays a plain `?int`: it's
 * a real cross-object-type id (category/image/group/tag/... depending on
 * `$object`), the same "genuinely heterogeneous" shape `ActivityEntity::
 * $objectId` itself is (still a plain `int` column, deliberately).
 */
final readonly class ActivityListCriteria
{
    /**
     * @param list<UserId> $adminIds
     */
    public function __construct(
        public ?UserId $performedBy = null,
        public ?string $action = null,
        public ?string $object = null,
        public ?SqlDateTime $minDate = null,
        public ?SqlDateTime $maxDate = null,
        public ?int $objectId = null,
        public string $connectionsMode = 'all',
        public array $adminIds = [],
    ) {}
}
