<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Override;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Sort\UserSortField;
use Piwigo\Users\UserListCriteria;
use Piwigo\Users\UserStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/users` -- `pwg.users.getList`'s real replacement, admin
 * only. `min_register`/`max_register` date-range filtering and the
 * fuzzy `filter` (username/email/group-name) search are both dropped for
 * this pass -- real but secondary filters, not needed to make the
 * resource usable; can be added later without a breaking change.
 */
final readonly class UserListController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private UserRowFetcher $userRowFetcher,
        private CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $query = $request->getQueryParams();

        $orderBy = UserSortField::parseOrderClause(is_string($query['order'] ?? null) ? $query['order'] : 'id');
        if ($orderBy === null) {
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

        $groupIds = self::intList($query['groupIds'] ?? null);
        $exclude = self::intList($query['exclude'] ?? null);
        $perPage = isset($query['perPage']) && is_numeric($query['perPage']) ? (int) $query['perPage'] : 100;
        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 0;

        $criteria = new UserListCriteria(
            userId: $userIds !== [] ? array_map(UserId::from(...), $userIds) : null,
            username: isset($query['username']) && is_string($query['username']) ? $query['username'] : null,
            status: $matchedStatus !== [] ? $matchedStatus : null,
            minLevel: $minLevel,
            groupId: $groupIds !== [] ? $groupIds : null,
            exclude: $exclude !== [] ? $exclude : null,
        );

        $result = $this->userRowFetcher->page($criteria, $orderBy, $perPage, $perPage * $page);

        return ResponseFactory::json([
            'users' => $result['rows'],
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
