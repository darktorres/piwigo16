<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Every field here is null when its filter wasn't requested --
 * `UserRepository::findList()` decides for itself, per field,
 * whether to add a condition.
 *
 * $filter/$filteredGroupIds are two separate pieces of the original's one
 * `filter` concept: $filter is the raw (unwrapped) search term matched
 * against username/email; $filteredGroupIds is the *already-resolved*
 * list of group ids whose name matches that same term -- resolving a name
 * pattern to ids is Group-domain business logic, not something this
 * repository's own persistence layer should do, so the caller resolves it
 * first and hands over just the ids. No current `/api/v1/users` caller
 * populates $filteredGroupIds yet -- the free-text (non-`id:NNN`) search
 * box this fed is a deliberately deferred filter (see
 * `Controller\Api\Users\UserListController`'s own docblock).
 *
 * $minRegister/$maxRegister are `SqlDateTime`-typed -- `Controller\Api\
 * Users\UserListController::parseRegisterBound()` rejects an invalid
 * calendar date (e.g. `minRegister=9999-13-99`) with a 422 before ever
 * constructing this criteria object.
 */
final readonly class UserListCriteria
{
    /**
     * @param list<UserId>|null $userId
     * @param list<int>|null $filteredGroupIds
     * @param list<string>|null $status
     * @param list<int>|null $groupId
     * @param list<int>|null $exclude
     */
    public function __construct(
        public ?array $userId = null,
        public ?string $username = null,
        public ?string $filter = null,
        public ?array $filteredGroupIds = null,
        public ?SqlDateTime $minRegister = null,
        public ?SqlDateTime $maxRegister = null,
        public ?array $status = null,
        public ?int $minLevel = null,
        public ?int $maxLevel = null,
        public ?array $groupId = null,
        public ?array $exclude = null,
    ) {}
}
