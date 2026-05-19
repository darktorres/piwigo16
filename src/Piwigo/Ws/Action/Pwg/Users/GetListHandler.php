<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\Tables;
use Piwigo\Event\User\WsUsersGetList;
use Piwigo\Group\GroupRepository;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.users.getList` — paginated user list with rich filter/display modes. */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private DateService $dateService,
        private EventDispatcherInterface $dispatcher,
        private GroupRepository $groupRepository,
        private UserRepository $userRepository,
        private UserService $userService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $orderStr = is_string($params['order'] ?? null) ? $params['order'] : '';
        if (!preg_match(ValidationPattern::ORDER, $orderStr)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter order');
        }
        if (isset($params['order']) && str_contains($orderStr, 'username')) {
            $orderStr = str_ireplace('username', 'LOWER(username)', $orderStr);
        }
        $whereClauses = ['1=1'];
        if (!empty($params['user_id'])) {
            $userIdArr      = is_array($params['user_id']) ? $params['user_id'] : [];
            $whereClauses[] = 'u.' . Config::userFields()['id'] . ' IN(' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $userIdArr)) . ')';
        }
        $listParams = [];
        $listTypes  = [];
        if (!empty($params['username'])) {
            $whereClauses[] = 'u.' . Config::userFields()['username'] . ' LIKE ?';
            $listParams[]   = is_string($params['username']) ? $params['username'] : '';
            $listTypes[]    = \Doctrine\DBAL\ParameterType::STRING;
        }
        if (!empty($params['filter'])) {
            $filterStr      = is_string($params['filter']) ? $params['filter'] : '';
            $filteredGroups = $this->groupRepository->findIdsByNameLike($filterStr);
            $filterLike     = '%' . $filterStr . '%';
            $filterWhere    = '(u.' . Config::userFields()['username'] . ' LIKE ? OR u.' . Config::userFields()['email'] . ' LIKE ?';
            $listParams[]   = $filterLike;
            $listParams[]   = $filterLike;
            $listTypes[]    = \Doctrine\DBAL\ParameterType::STRING;
            $listTypes[]    = \Doctrine\DBAL\ParameterType::STRING;
            if (!empty($filteredGroups)) {
                $filterWhere .= ' OR ug.group_id IN (' . implode(',', array_map(static fn (int $v): string => (string) $v, $filteredGroups)) . ')';
            }
            $whereClauses[] = $filterWhere . ')';
        }
        if (!empty($params['min_register'])) {
            $minRegisterStr = is_string($params['min_register']) ? $params['min_register'] : '';
            if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $minRegisterStr)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter min_register');
            }
            $dateTokens     = explode('-', $minRegisterStr);
            $minDate        = sprintf('%u-%02u-%02u', $dateTokens[0], $dateTokens[1] ?? 1, $dateTokens[2] ?? 1);
            $whereClauses[] = "ui.registration_date >= '$minDate 00:00:00'";
        }
        if (!empty($params['max_register'])) {
            $maxRegisterStr = is_string($params['max_register']) ? $params['max_register'] : '';
            if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $maxRegisterStr)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter max_register');
            }
            $maxDateTokens  = explode('-', $maxRegisterStr);
            $strResult      = strtotime($maxDateTokens[0] . '-' . ($maxDateTokens[1] ?? '12') . '-1');
            $maxDay         = $maxDateTokens[2] ?? date('t', $strResult !== false ? $strResult : null);
            $maxDate        = sprintf('%u-%02u-%02u', $maxDateTokens[0], $maxDateTokens[1] ?? '12', $maxDay);
            $whereClauses[] = "ui.registration_date <= '$maxDate 23:59:59'";
        }
        if (!empty($params['status'])) {
            $statusArr = is_array($params['status']) ? $params['status'] : [];
            $statusArr = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $statusArr);
            $statusArr = array_intersect($statusArr, SchemaHelper::getEnums(Tables::userInfos(), 'status'));
            if (count($statusArr) > 0) {
                $whereClauses[] = 'ui.status IN("' . implode('","', $statusArr) . '")';
            }
        }
        if (!empty($params['min_level'])) {
            if (!in_array($params['min_level'], Config::availablePermissionLevels())) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid level');
            }
            $whereClauses[] = 'ui.level >= ' . (is_numeric($params['min_level']) ? (int) $params['min_level'] : 0);
        }
        if (!empty($params['max_level'])) {
            if (!in_array($params['max_level'], Config::availablePermissionLevels())) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid level');
            }
            $whereClauses[] = 'ui.level <= ' . (is_numeric($params['max_level']) ? (int) $params['max_level'] : 0);
        }
        if (!empty($params['group_id'])) {
            $groupIdArr     = is_array($params['group_id']) ? $params['group_id'] : [];
            $whereClauses[] = 'ug.group_id IN(' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $groupIdArr)) . ')';
        }
        if (!empty($params['exclude'])) {
            $excludeArr     = is_array($params['exclude']) ? $params['exclude'] : [];
            $whereClauses[] = 'u.' . Config::userFields()['id'] . ' NOT IN(' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $excludeArr)) . ')';
        }
        $display      = ['u.' . Config::userFields()['id'] => 'id'];
        $displayParam = is_string($params['display'] ?? null) ? $params['display'] : 'none';
        if ($displayParam !== 'none') {
            $params['display'] = array_map(trim(...), explode(',', $displayParam));
            if (in_array('all', $params['display'])) {
                $params['display'] = ['username','email','status','level','groups','language','theme','nb_image_page','recent_period','expand','show_nb_comments','show_nb_hits','enabled_high','registration_date','registration_date_string','registration_date_since','last_visit','last_visit_string','last_visit_since','total_count'];
            } elseif (in_array('basics', $params['display'])) {
                $params['display'] = array_merge($params['display'], ['username','email','status','level','groups']);
            } elseif (in_array('only_id', $params['display'])) {
                $params['display'] = [];
            }
            $params['display'] = array_flip($params['display']);
            if (isset($params['display']['registration_date_string']) || isset($params['display']['registration_date_since'])) {
                $params['display']['registration_date'] = true;
            }
            if (isset($params['display']['last_visit_string']) || isset($params['display']['last_visit_since'])) {
                $params['display']['last_visit'] = true;
            }
            if (isset($params['display']['username'])) {
                $display['u.' . Config::userFields()['username']] = 'username';
            }
            if (isset($params['display']['email'])) {
                $display['u.' . Config::userFields()['email']] = 'email';
            }
            $uiFields = ['status','level','language','theme','nb_image_page','recent_period','expand','show_nb_comments','show_nb_hits','enabled_high','registration_date','last_visit'];
            foreach ($uiFields as $field) {
                if (isset($params['display'][$field])) {
                    $display['ui.' . $field] = $field;
                }
            }
        } else {
            $params['display'] = [];
        }
        $query = 'SELECT DISTINCT ';
        if (isset($params['display']['total_count'])) {
            $query .= 'SQL_CALC_FOUND_ROWS ';
        }
        $first = true;
        foreach ($display as $field => $name) {
            if (!$first) {
                $query .= ', ';
            } else {
                $first = false;
            }
            $query .= $field . ' AS ' . $name;
        }
        if (isset($display['ui.last_visit'])) {
            $query .= ', ui.last_visit_from_history AS last_visit_from_history';
        }
        $query  .= ' FROM ' . Tables::users() . ' AS u INNER JOIN ' . Tables::userInfos() . ' AS ui ON u.' . Config::userFields()['id'] . ' = ui.user_id LEFT JOIN ' . Tables::userGroup() . ' AS ug ON u.' . Config::userFields()['id'] . ' = ug.user_id WHERE ' . implode(' AND ', $whereClauses) . ' ORDER BY ' . $orderStr;
        $perPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $page    = is_numeric($params['page']) ? (int) $params['page'] : 0;
        if ($perPage !== 0 || $params['display'] !== []) {
            $query .= ' LIMIT ' . $perPage . ' OFFSET ' . ($perPage * $page) . ';';
        }
        $users            = [];
        $captureFoundRows = isset($params['display']['total_count']);
        $listResult       = $this->userRepository->findUsersListPage($query, $captureFoundRows, $listParams, $listTypes);
        $usersRows        = $listResult['rows'];
        $totalCount       = $listResult['total'] ?? 0;
        foreach ($usersRows as $row) {
            $row['id'] = is_numeric($row['id']) ? (int) $row['id'] : 0;
            if (isset($params['display']['groups'])) {
                $row['groups'] = [];
            }
            $users[$row['id']] = $row;
        }
        if (count($users) > 0) {
            if (array_key_exists('groups', $params['display'])) {
                $userIds = array_keys($users);
                foreach ($this->userRepository->findUserGroupPairsByUserIds($userIds) as $row) {
                    $grpUid = $row['user_id'];
                    if (!isset($users[$grpUid]['groups']) || !is_array($users[$grpUid]['groups'])) {
                        $users[$grpUid]['groups'] = [];
                    }
                    $users[$grpUid]['groups'][] = $row['group_id'];
                }
            }
            foreach ($users as $curUser) {
                /** @var array<string, mixed> $curUser */
                $curUid = is_numeric($curUser['id'] ?? null) ? (int) $curUser['id'] : 0;
                if (isset($params['display']['registration_date_string'])) {
                    $regDate                                    = is_scalar($curUser['registration_date'] ?? null) ? (string) $curUser['registration_date'] : null;
                    $users[$curUid]['registration_date_string'] = $this->dateService->formatDate($regDate, ['day', 'month', 'year']);
                }
                if (isset($params['display']['registration_date_since'])) {
                    $regDate2                                  = is_scalar($curUser['registration_date'] ?? null) ? (string) $curUser['registration_date'] : null;
                    $users[$curUid]['registration_date_since'] = $this->dateService->timeSince($regDate2, 'month');
                }
                if (isset($params['display']['last_visit'])) {
                    $lastVisit                    = $curUser['last_visit'] ?? null;
                    $users[$curUid]['last_visit'] = $lastVisit;
                    if (!BoolUtil::fromMixed($curUser['last_visit_from_history'] ?? null) && ($lastVisit === null || $lastVisit === '')) {
                        $lastVisit                    = $this->userService->getUserLastVisitFromHistory($curUid, true);
                        $users[$curUid]['last_visit'] = $lastVisit;
                    }
                    if (isset($params['display']['last_visit_string'])) {
                        $lvStr                               = is_scalar($lastVisit) ? (string) $lastVisit : null;
                        $users[$curUid]['last_visit_string'] = $this->dateService->formatDate($lvStr, ['day', 'month', 'year']);
                    }
                    if (isset($params['display']['last_visit_since'])) {
                        $lvSince                            = is_scalar($lastVisit) ? (string) $lastVisit : null;
                        $users[$curUid]['last_visit_since'] = $this->dateService->timeSince($lvSince, 'day');
                    }
                }
            }
        }
        $usersEvent = new WsUsersGetList($users);
        $this->dispatcher->dispatch($usersEvent);
        /** @var array<int|string, array<string, mixed>> $users */
        $users = $usersEvent->users;
        if ($perPage === 0 && $params['display'] === []) {
            $methodResult = array_column(array_values($users), 'id');
        } else {
            $methodResult = ['paging' => new PwgNamedStruct(['page' => $page, 'per_page' => $perPage, 'count' => count($users), 'total_count' => $totalCount]), 'users' => new PwgNamedArray(array_values($users), 'user')];
        }
        if (isset($params['display']['total_count'])) {
            $methodResult['total_count'] = $totalCount;
        }
        return $methodResult;
    }
}
