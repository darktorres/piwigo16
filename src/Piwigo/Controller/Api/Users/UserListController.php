<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use InvalidArgumentException;
use Override;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Sort\UserSortField;
use Piwigo\Users\Event\GetUserListRows;
use Piwigo\Users\UserListCriteria;
use Piwigo\Users\UserStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/users` -- `pwg.users.getList`'s real replacement, admin
 * only. The fuzzy `filter` (username/email/group-name) search is dropped
 * for this pass -- a real but secondary filter, not needed to make the
 * resource usable; can be added later without a breaking change.
 * `minRegister`/`maxRegister` accept a partial-date shape (`YYYY`,
 * `YYYY-MM`, or `YYYY-MM-DD`), needed for real parity with `user_list.js`'s
 * registration-date range filter.
 */
final readonly class UserListController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private UserRowFetcher $userRowFetcher,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $query = $request->getQueryParams();

        $orderClauses = UserSortField::parseOrderClause(is_string($query['order'] ?? null) ? $query['order'] : 'id');
        if ($orderClauses === null) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid order parameter.');
        }

        $userIds = self::intList($query['userIds'] ?? null);
        $status = self::stringList($query['status'] ?? null);
        $matchedStatus = array_values(array_intersect($status, array_map(
            static fn (UserStatus $s): string => $s->value,
            UserStatus::cases()
        )));

        $minLevel = isset($query['minLevel']) && is_numeric($query['minLevel']) ? (int) $query['minLevel'] : null;
        if ($minLevel !== null && ! in_array($minLevel, $this->currentConfig->availablePermissionLevels, true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid minLevel.');
        }

        $maxLevel = isset($query['maxLevel']) && is_numeric($query['maxLevel']) ? (int) $query['maxLevel'] : null;
        if ($maxLevel !== null && ! in_array($maxLevel, $this->currentConfig->availablePermissionLevels, true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid maxLevel.');
        }

        $minRegister = null;
        if (isset($query['minRegister']) && is_string($query['minRegister']) && $query['minRegister'] !== '') {
            $minRegister = self::parseRegisterBound($query['minRegister'], isMax: false);
            if ($minRegister === false) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid minRegister.');
            }
        }

        $maxRegister = null;
        if (isset($query['maxRegister']) && is_string($query['maxRegister']) && $query['maxRegister'] !== '') {
            $maxRegister = self::parseRegisterBound($query['maxRegister'], isMax: true);
            if ($maxRegister === false) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid maxRegister.');
            }
        }

        $groupIds = self::intList($query['groupIds'] ?? null);
        $exclude = self::intList($query['exclude'] ?? null);
        $perPage = isset($query['perPage']) && is_numeric($query['perPage']) ? (int) $query['perPage'] : 100;
        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 0;

        $criteria = new UserListCriteria(
            userId: $userIds !== [] ? array_map(UserId::from(...), $userIds) : null,
            username: isset($query['username']) && is_string($query['username']) ? $query['username'] : null,
            minRegister: $minRegister,
            maxRegister: $maxRegister,
            status: $matchedStatus !== [] ? $matchedStatus : null,
            minLevel: $minLevel,
            maxLevel: $maxLevel,
            groupId: $groupIds !== [] ? $groupIds : null,
            exclude: $exclude !== [] ? $exclude : null,
        );

        // perPage=0 means "no limit" -- user_list.js's select-all-filtered
        // action depends on this to fetch every matching id in one request.
        $result = $this->userRowFetcher->page($criteria, $orderClauses, $perPage === 0 ? null : $perPage, $perPage * $page);
        $rows = $this->eventDispatcher->dispatch(new GetUserListRows($result['rows']))->rows;

        return ResponseFactory::json([
            'users' => $rows,
            'page' => $page,
            'perPage' => $perPage,
            'totalCount' => $result['total'],
        ]);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }

    /**
     * Parses a `YYYY`/`YYYY-MM`/`YYYY-MM-DD` partial date into a
     * day-bounded `SqlDateTime` (`00:00:00` for a min bound, `23:59:59`
     * for a max bound; a missing month/day defaults to the first day for
     * a min bound and the last day of the month for a max bound).
     * Returns `false` on a shape or calendar validation failure.
     */
    private static function parseRegisterBound(string $raw, bool $isMax): SqlDateTime|false
    {
        if (! (bool) preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $raw)) {
            return false;
        }

        $tokens = explode('-', $raw);
        $year = $tokens[0];
        $month = $tokens[1] ?? ($isMax ? 12 : 1);

        if (isset($tokens[2])) {
            $day = $tokens[2];
        } elseif ($isMax) {
            $monthTimestamp = strtotime($year . '-' . $month . '-1');
            assert($monthTimestamp !== false);
            $day = date('t', $monthTimestamp);
        } else {
            $day = 1;
        }

        $date = sprintf('%u-%02u-%02u', (int) $year, (int) $month, (int) $day);

        try {
            return SqlDateTime::from($date . ($isMax ? ' 23:59:59' : ' 00:00:00'));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $v) {
            if (is_string($v)) {
                $values[] = $v;
            }
        }

        return $values;
    }
}
