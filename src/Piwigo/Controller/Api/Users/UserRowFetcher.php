<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AuthService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\DateHelper;
use Piwigo\Db\SqlDialect;
use Piwigo\Group\GroupService;
use Piwigo\History\HistoryEntity;
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
 * that made sense for XML. The 6 per-user UI-preference fields and the
 * last-visit History-table fallback (both ported here verbatim from
 * `Ws\Users\GetListHandler`) are real fields `user_list.js`'s edit-user
 * popup reads and writes through its preferences tab -- restored after
 * that JS conversion showed dropping them broke the tab's controls
 * entirely, not just trimmed a payload-size optimization.
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
        'ui.nb_image_page' => 'nb_image_page',
        'ui.recent_period' => 'recent_period',
        'ui.expand' => 'expand',
        'ui.show_nb_comments' => 'show_nb_comments',
        'ui.show_nb_hits' => 'show_nb_hits',
        'ui.enabled_high' => 'enabled_high',
    ];

    public function __construct(
        private UserService $userService,
        private GroupService $groupService,
        private AuthService $authService,
        private EntityManagerInterface $entityManager,
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
            true,
            $criteria,
            $orderBy,
            $includeTotalCount,
            $limit,
            $offset
        );

        $users = [];
        foreach ($paginated->rows as $row) {
            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $registrationDate = is_string($row['registration_date'] ?? null) ? $row['registration_date'] : null;

            $lastVisit = is_string($row['last_visit'] ?? null) ? $row['last_visit'] : null;
            if (! SqlDialect::getBoolean($row['last_visit_from_history'] ?? null) && in_array($lastVisit, [null, ''], true)) {
                $lastVisit = $this->authService->getUserLastVisitFromHistory(
                    $id,
                    $this->entityManager->getRepository(HistoryEntity::class),
                    true
                );
            }

            $users[$id] = [
                'id' => $id,
                'username' => is_string($row['username'] ?? null) ? $row['username'] : '',
                'email' => is_string($row['email'] ?? null) ? $row['email'] : null,
                'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
                'level' => is_numeric($row['level'] ?? null) ? (int) $row['level'] : null,
                'groups' => [],
                'language' => is_string($row['language'] ?? null) ? $row['language'] : null,
                'theme' => is_string($row['theme'] ?? null) ? $row['theme'] : null,
                'registrationDate' => $registrationDate,
                'registrationDateString' => DateHelper::formatDate($registrationDate ?? false, ['day', 'month', 'year']),
                'registrationDateSince' => DateHelper::timeSince($registrationDate ?? '', 'month'),
                'lastVisit' => $lastVisit,
                'lastVisitString' => DateHelper::formatDate($lastVisit ?? false, ['day', 'month', 'year']),
                'lastVisitSince' => DateHelper::timeSince($lastVisit ?? '', 'day'),
                'nbImagePage' => is_numeric($row['nb_image_page'] ?? null) ? (int) $row['nb_image_page'] : null,
                'recentPeriod' => is_numeric($row['recent_period'] ?? null) ? (int) $row['recent_period'] : null,
                'expand' => SqlDialect::getBoolean($row['expand'] ?? null),
                'showNbComments' => SqlDialect::getBoolean($row['show_nb_comments'] ?? null),
                'showNbHits' => SqlDialect::getBoolean($row['show_nb_hits'] ?? null),
                'enabledHigh' => SqlDialect::getBoolean($row['enabled_high'] ?? null),
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
