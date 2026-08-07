<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Further SQL-modernization audit, Item 13: replaces the ad hoc
 * `list<string> $whereClauses` (each fragment glued via `implode(' AND ',
 * ...)`, bound values tracked in a separately-passed `$params`/`$types`
 * pair) `Ws\PwgUsers::getList()` used to build -- a `1=1` base plus a
 * chain of independently-optional conditions, each appended only when its
 * corresponding `$params` key was present. Every field here is null when
 * its filter wasn't requested, matching that same "appended only when
 * present" semantics -- `UserRepository::findListForWs()` decides for
 * itself, per field, whether to add a condition.
 *
 * $filter/$filteredGroupIds are two separate pieces of the original's one
 * `filter` concept: $filter is the raw (unwrapped) search term matched
 * against username/email; $filteredGroupIds is the *already-resolved*
 * list of group ids whose name matches that same term
 * (`GroupService::getIdsByNameLike()`'s own output) -- resolving a name
 * pattern to ids is Group-domain business logic, not something this
 * repository's own persistence layer should do, so the caller resolves it
 * first and hands over just the ids.
 *
 * $minRegister/$maxRegister are `SqlDateTime`-typed (Phase 5 typed-VO
 * campaign) -- `Ws\PwgUsers::getList()` validates the raw `min_register`/
 * `max_register` WS params through `SqlDateTime::from()` itself (a real
 * PwgError on an invalid calendar date, e.g. `min_register=9999-13-99`,
 * which the WS layer's own shape-only regex doesn't catch) before ever
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
