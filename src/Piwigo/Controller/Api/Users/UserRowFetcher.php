<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AuthService;
use Piwigo\Common\ValueObject\SortEntry;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\DateHelper;
use Piwigo\Group\GroupService;
use Piwigo\History\HistoryEntity;
use Piwigo\Sort\UserSortField;
use Piwigo\Users\Projection\UserListRow;
use Piwigo\Users\UserListCriteria;
use Piwigo\Users\UserService;

/**
 * Shared `UserService::getList()` wrapper for the `/api/v1/users`
 * family -- `ListController`/`CreateController`/`UpdateController` all
 * need to resolve one or more user ids into full, camelCased rows.
 *
 * A fixed display column set replaces `pwg.users.getList`'s own
 * client-controlled `display` string mini-language -- a JSON API returns
 * correctly-typed, complete rows rather than a payload-size optimization
 * that made sense for XML. The 6 per-user UI-preference fields and the
 * last-visit History-table fallback are real fields `user_list.js`'s
 * edit-user popup reads and writes through its preferences tab -- restored
 * after that JS conversion showed dropping them broke the tab's controls
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

        return $this->fetch(new UserListCriteria(userId: $userIds), [
            new SortEntry(UserSortField::Id, 'ASC'),
        ], null, 0, false)['rows'];
    }

    /**
     * @param list<SortEntry<UserSortField, string>> $orderClauses
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(UserListCriteria $criteria, array $orderClauses, ?int $limit, int $offset): array
    {
        return $this->fetch($criteria, $orderClauses, $limit, $offset, true);
    }

    /**
     * @param list<SortEntry<UserSortField, string>> $orderClauses
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function fetch(UserListCriteria $criteria, array $orderClauses, ?int $limit, int $offset, bool $includeTotalCount): array
    {
        $paginated = $this->userService->getList(
            self::DISPLAY_COLUMNS,
            true,
            $criteria,
            $orderClauses,
            $includeTotalCount,
            $limit,
            $offset
        );

        $users = [];
        foreach ($paginated->rows as $row) {
            $listRow = UserListRow::fromRow($row);
            $id = $listRow->id;
            $registrationDate = $listRow->registrationDate;

            $lastVisit = $listRow->lastVisit;
            if (! $listRow->lastVisitFromHistory && in_array($lastVisit, [null, ''], true)) {
                /**
                 * @psalm-suppress InvalidArgument getRepository() always
                 *   really returns HistoryEntity's own custom
                 *   repositoryClass at runtime (HistoryRepository), which
                 *   implements LastVisitLookupInterface; Psalm's Doctrine
                 *   plugin doesn't narrow getRepository()'s return type
                 *   per-entity's own repositoryClass binding, only the
                 *   generic EntityRepository<T>.
                 */
                $lastVisit = $this->authService->getUserLastVisitFromHistory(
                    $id,
                    $this->entityManager->getRepository(HistoryEntity::class),
                    true
                );
            }

            $users[$id] = [
                'id' => $id,
                'username' => $listRow->username,
                'email' => $listRow->email,
                'status' => $listRow->status,
                'level' => $listRow->level,
                'groups' => [],
                'language' => $listRow->language,
                'theme' => $listRow->theme,
                'registrationDate' => $registrationDate,
                'registrationDateString' => DateHelper::formatDate($registrationDate ?? false, ['day', 'month', 'year']),
                'registrationDateSince' => DateHelper::timeSince($registrationDate ?? '', 'month'),
                'lastVisit' => $lastVisit,
                'lastVisitString' => DateHelper::formatDate($lastVisit ?? false, ['day', 'month', 'year']),
                'lastVisitSince' => DateHelper::timeSince($lastVisit ?? '', 'day'),
                'nbImagePage' => $listRow->nbImagePage,
                'recentPeriod' => $listRow->recentPeriod,
                'expand' => $listRow->expand,
                'showNbComments' => $listRow->showNbComments,
                'showNbHits' => $listRow->showNbHits,
                'enabledHigh' => $listRow->enabledHigh,
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
