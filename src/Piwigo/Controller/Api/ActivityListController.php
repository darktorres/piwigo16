<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityListCriteria;
use Piwigo\Activity\ActivityService;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/activity` -- `pwg.activity.getList`'s real replacement,
 * admin only. One row per real activity entry, correctly typed --
 * deliberately not merged into consecutive-same-session/object/action
 * display lines with a counter, a display-density optimization that
 * would also lose information (it collapses distinct object ids into
 * one synthetic line). This response stays flat by design (D3), but
 * carries `sessionIdx` and a pre-formatted `dateFormatted` (via
 * `DateHelper::formatDate()`, real locale-aware output) so a client that
 * *does* want grouped display can merge on `sessionIdx~object~action`
 * without a second round-trip for date formatting --
 * `user_activity.js` (the real admin log page, `admin.php?
 * page=history`) is exactly that client, merging these flat rows
 * client-side.
 *
 * `position` is `offset + index` in this response, not a real activity
 * row id -- `PaginatedActivityRow` deliberately doesn't carry the
 * underlying `activity_id` auto-increment column (see its own
 * docblock); it exists here only so a client can request the next page.
 */
final readonly class ActivityListController implements ControllerInterface
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        private AdminGuard $adminGuard,
        private ActivityService $activityService,
        private UserService $userService,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $query = $request->getQueryParams();

        $dateMinRaw = isset($query['dateMin']) && is_string($query['dateMin']) && $query['dateMin'] !== '' ? $query['dateMin'] : null;
        $dateMaxRaw = isset($query['dateMax']) && is_string($query['dateMax']) && $query['dateMax'] !== '' ? $query['dateMax'] : null;

        foreach ([
            'dateMin' => $dateMinRaw,
            'dateMax' => $dateMaxRaw,
        ] as $field => $value) {
            if ($value !== null && ! DateHelper::isValidMysqlDatetime($value)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid ' . $field . '.');
            }
        }

        $minDate = null;
        if ($dateMinRaw !== null) {
            $minDateParsed = date_create($dateMinRaw);
            assert($minDateParsed !== false);
            $minDate = SqlDateTime::from(date_format($minDateParsed, 'Y-m-d H:i:s'));
        }

        $maxDate = null;
        if ($dateMaxRaw !== null) {
            $maxDateParsed = date_create($dateMaxRaw);
            assert($maxDateParsed !== false);
            $maxDate = SqlDateTime::from(date_format($maxDateParsed, 'Y-m-d 23:59:59'));
        }

        $userId = isset($query['userId']) && is_numeric($query['userId']) && (int) $query['userId'] !== 0 ? (int) $query['userId'] : null;
        $objectId = isset($query['objectId']) && is_numeric($query['objectId']) && (int) $query['objectId'] !== 0 ? (int) $query['objectId'] : null;
        $object = isset($query['object']) && is_string($query['object']) ? $query['object'] : null;
        $action = isset($query['action']) && is_string($query['action']) ? $query['action'] : null;
        $offset = isset($query['offset']) && is_numeric($query['offset']) ? max(0, (int) $query['offset']) : 0;

        $connectionsMode = $this->currentConfig->activityDisplayConnections;
        $adminIds = [];
        if ($connectionsMode === 'admins_only') {
            $adminIds = new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
                ->findAdminIds();
        }

        $criteria = new ActivityListCriteria(
            performedBy: $userId !== null ? UserId::from($userId) : null,
            action: $action,
            object: $object,
            minDate: $minDate,
            maxDate: $maxDate,
            objectId: $objectId,
            connectionsMode: $connectionsMode,
            adminIds: $adminIds,
        );

        $rows = $this->activityService->getPaginated($criteria, self::PAGE_SIZE, $offset);

        $userIds = [];
        foreach ($rows as $row) {
            if ($row->performedBy !== null) {
                $userIds[$row->performedBy] = true;
            }
        }
        $usernameOf = $userIds !== [] ? $this->userService->getUsernamesByIds(array_map(strval(...), array_keys($userIds))) : [];

        $activities = [];
        foreach ($rows as $index => $row) {
            // sessionIdx/dateFormatted exist for a client-side line-merging
            // consumer (user_activity.js) to group consecutive rows by the
            // same sessionIdx~object~action key with locale-aware date
            // display -- this response deliberately doesn't merge rows
            // itself (see this class's own docblock), but a client that
            // wants to needs the same grouping key and the same
            // DateHelper::formatDate() output, not a raw ISO date.
            [$occuredOnDate] = explode(' ', $row->occuredOn->value);
            $activities[] = [
                'position' => $offset + $index,
                'performedBy' => $row->performedBy,
                'performedByUsername' => $row->performedBy !== null ? ($usernameOf[$row->performedBy] ?? null) : null,
                'object' => $row->object,
                'objectId' => $row->objectId,
                'action' => $row->action,
                'sessionIdx' => $row->sessionIdx,
                'ipAddress' => $row->ipAddress?->value,
                'occuredOn' => $row->occuredOn->value,
                'dateFormatted' => DateHelper::formatDate($occuredOnDate),
                'userAgent' => $row->userAgent,
                'details' => $row->details,
            ];
        }

        return ResponseFactory::json([
            'activities' => $activities,
            'offset' => $offset,
            'limit' => self::PAGE_SIZE,
            'hasMore' => count($rows) === self::PAGE_SIZE,
        ]);
    }
}
