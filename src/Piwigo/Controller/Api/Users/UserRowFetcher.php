<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Group\GroupService;
use Piwigo\Users\UserListCriteria;
use Piwigo\Users\UserService;

/**
 * Shared `UserService::getListForWs()` wrapper for the `/api/v1/users`
 * family -- `ListController`/`CreateController`/`UpdateController` all
 * need to resolve one or more user ids into full, camelCased rows.
 *
 * A fixed display column set replaces `pwg.users.getList`'s own
 * client-controlled `display` string mini-language -- a JSON API returns
 * correctly-typed, complete rows rather than a payload-size optimization
 * that made sense for XML. The per-user UI-preference fields
 * (nb_image_page, recent_period, expand, show_nb_comments, show_nb_hits,
 * enabled_high) and the last-visit History-table fallback enrichment are
 * both dropped -- niche fields a REST client can fetch separately if
 * ever needed, not core identity/admin data.
 */
final readonly class UserRowFetcher
{
    /**
     * @var array<string, string>
     */
    private const array DISPLAY_COLUMNS = [
        'u.id' => 'id',
        'u.username' => 'username',
        'u.mail_address' => 'email',
        'ui.status' => 'status',
        'ui.level' => 'level',
        'ui.language' => 'language',
        'ui.theme' => 'theme',
        'ui.registration_date' => 'registration_date',
        'ui.last_visit' => 'last_visit',
    ];

    public function __construct(
        private UserService $userService,
        private GroupService $groupService,
    ) {}

    /**
     * @param list<UserId> $userIds
     * @return list<array<string, mixed>>
     */
    public function byIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->fetch(new UserListCriteria(userId: $userIds), 'u.id ASC', null, 0, false)['rows'];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(UserListCriteria $criteria, string $orderBy, ?int $limit, int $offset): array
    {
        return $this->fetch($criteria, $orderBy, $limit, $offset, true);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function fetch(UserListCriteria $criteria, string $orderBy, ?int $limit, int $offset, bool $includeTotalCount): array
    {
        $paginated = $this->userService->getListForWs(
            self::DISPLAY_COLUMNS,
            false,
            $criteria,
            $orderBy,
            $includeTotalCount,
            $limit,
            $offset
        );

        $users = [];
        foreach ($paginated->rows as $row) {
            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $users[$id] = [
                'id' => $id,
                'username' => is_string($row['username'] ?? null) ? $row['username'] : '',
                'email' => is_string($row['email'] ?? null) ? $row['email'] : null,
                'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
                'level' => is_numeric($row['level'] ?? null) ? (int) $row['level'] : null,
                'groups' => [],
                'language' => is_string($row['language'] ?? null) ? $row['language'] : null,
                'theme' => is_string($row['theme'] ?? null) ? $row['theme'] : null,
                'registrationDate' => is_string($row['registration_date'] ?? null) ? $row['registration_date'] : null,
                'lastVisit' => is_string($row['last_visit'] ?? null) ? $row['last_visit'] : null,
            ];
        }

        if ($users !== []) {
            foreach ($this->groupService->getMembershipsForUserIds(array_keys($users)) as $membership) {
                if (isset($users[$membership['user_id']])) {
                    $users[$membership['user_id']]['groups'][] = $membership['group_id'];
                }
            }
        }

        return [
            'rows' => array_values($users),
            'total' => $paginated->total ?? count($users),
        ];
    }
}
