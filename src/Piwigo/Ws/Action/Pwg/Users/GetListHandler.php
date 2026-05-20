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
        $input    = GetListParams::fromArray($params);
        $orderStr = $input->order;
        if (!preg_match(ValidationPattern::ORDER, $orderStr)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter order');
        }
        if ($orderStr !== '' && str_contains($orderStr, 'username')) {
            $orderStr = str_ireplace('username', 'LOWER(username)', $orderStr);
        }
        $whereClauses = ['1=1'];
        if (count($input->userIds) > 0) {
            $whereClauses[] = 'u.' . Config::userFields()['id'] . ' IN(' . implode(',', $input->userIds) . ')';
        }
        $listParams = [];
        $listTypes  = [];
        if ($input->username !== null) {
            $whereClauses[] = 'u.' . Config::userFields()['username'] . ' LIKE ?';
            $listParams[]   = $input->username;
            $listTypes[]    = \Doctrine\DBAL\ParameterType::STRING;
        }
        if ($input->filter !== null) {
            $filterStr      = $input->filter;
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
        if ($input->minRegister !== null) {
            if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $input->minRegister)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter min_register');
            }
            $dateTokens     = explode('-', $input->minRegister);
            $minDate        = sprintf('%u-%02u-%02u', $dateTokens[0], $dateTokens[1] ?? 1, $dateTokens[2] ?? 1);
            $whereClauses[] = "ui.registration_date >= '$minDate 00:00:00'";
        }
        if ($input->maxRegister !== null) {
            if (!preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $input->maxRegister)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter max_register');
            }
            $maxDateTokens  = explode('-', $input->maxRegister);
            $strResult      = strtotime($maxDateTokens[0] . '-' . ($maxDateTokens[1] ?? '12') . '-1');
            $maxDay         = $maxDateTokens[2] ?? date('t', $strResult !== false ? $strResult : null);
            $maxDate        = sprintf('%u-%02u-%02u', $maxDateTokens[0], $maxDateTokens[1] ?? '12', $maxDay);
            $whereClauses[] = "ui.registration_date <= '$maxDate 23:59:59'";
        }
        if (count($input->statuses) > 0) {
            $statusArr = array_intersect($input->statuses, SchemaHelper::getEnums(Tables::userInfos(), 'status'));
            if (count($statusArr) > 0) {
                $whereClauses[] = 'ui.status IN("' . implode('","', $statusArr) . '")';
            }
        }
        if (!empty($input->minLevel)) {
            if (!in_array($input->minLevel, Config::availablePermissionLevels())) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid level');
            }
            $whereClauses[] = 'ui.level >= ' . (is_numeric($input->minLevel) ? (int) $input->minLevel : 0);
        }
        if (!empty($input->maxLevel)) {
            if (!in_array($input->maxLevel, Config::availablePermissionLevels())) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid level');
            }
            $whereClauses[] = 'ui.level <= ' . (is_numeric($input->maxLevel) ? (int) $input->maxLevel : 0);
        }
        if (count($input->groupIds) > 0) {
            $whereClauses[] = 'ug.group_id IN(' . implode(',', $input->groupIds) . ')';
        }
        if (count($input->excludeIds) > 0) {
            $whereClauses[] = 'u.' . Config::userFields()['id'] . ' NOT IN(' . implode(',', $input->excludeIds) . ')';
        }
        $display      = ['u.' . Config::userFields()['id'] => 'id'];
        $displayMap   = [];
        if ($input->display !== 'none') {
            $displayTokens = array_map(trim(...), explode(',', $input->display));
            if (in_array('all', $displayTokens)) {
                $displayTokens = ['username','email','status','level','groups','language','theme','nb_image_page','recent_period','expand','show_nb_comments','show_nb_hits','enabled_high','registration_date','registration_date_string','registration_date_since','last_visit','last_visit_string','last_visit_since','total_count'];
            } elseif (in_array('basics', $displayTokens)) {
                $displayTokens = array_merge($displayTokens, ['username','email','status','level','groups']);
            } elseif (in_array('only_id', $displayTokens)) {
                $displayTokens = [];
            }
            $displayMap = array_flip($displayTokens);
            if (isset($displayMap['registration_date_string']) || isset($displayMap['registration_date_since'])) {
                $displayMap['registration_date'] = true;
            }
            if (isset($displayMap['last_visit_string']) || isset($displayMap['last_visit_since'])) {
                $displayMap['last_visit'] = true;
            }
            if (isset($displayMap['username'])) {
                $display['u.' . Config::userFields()['username']] = 'username';
            }
            if (isset($displayMap['email'])) {
                $display['u.' . Config::userFields()['email']] = 'email';
            }
            $uiFields = ['status','level','language','theme','nb_image_page','recent_period','expand','show_nb_comments','show_nb_hits','enabled_high','registration_date','last_visit'];
            foreach ($uiFields as $field) {
                if (isset($displayMap[$field])) {
                    $display['ui.' . $field] = $field;
                }
            }
        }
        $query = 'SELECT DISTINCT ';
        if (isset($displayMap['total_count'])) {
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
        $perPage = $input->perPage;
        $page    = $input->page;
        if ($perPage !== 0 || $displayMap !== []) {
            $query .= ' LIMIT ' . $perPage . ' OFFSET ' . ($perPage * $page) . ';';
        }
        $users            = [];
        $captureFoundRows = isset($displayMap['total_count']);
        $listResult       = $this->userRepository->findUsersListPage($query, $captureFoundRows, $listParams, $listTypes);
        $usersRows        = $listResult['rows'];
        $totalCount       = $listResult['total'] ?? 0;
        foreach ($usersRows as $row) {
            $row['id'] = is_numeric($row['id']) ? (int) $row['id'] : 0;
            if (isset($displayMap['groups'])) {
                $row['groups'] = [];
            }
            $users[$row['id']] = $row;
        }
        if (count($users) > 0) {
            if (array_key_exists('groups', $displayMap)) {
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
                if (isset($displayMap['registration_date_string'])) {
                    $regDate                                    = is_scalar($curUser['registration_date'] ?? null) ? (string) $curUser['registration_date'] : null;
                    $users[$curUid]['registration_date_string'] = $this->dateService->formatDate($regDate, ['day', 'month', 'year']);
                }
                if (isset($displayMap['registration_date_since'])) {
                    $regDate2                                  = is_scalar($curUser['registration_date'] ?? null) ? (string) $curUser['registration_date'] : null;
                    $users[$curUid]['registration_date_since'] = $this->dateService->timeSince($regDate2, 'month');
                }
                if (isset($displayMap['last_visit'])) {
                    $lastVisit                    = $curUser['last_visit'] ?? null;
                    $users[$curUid]['last_visit'] = $lastVisit;
                    if (!BoolUtil::fromMixed($curUser['last_visit_from_history'] ?? null) && ($lastVisit === null || $lastVisit === '')) {
                        $lastVisit                    = $this->userService->getUserLastVisitFromHistory($curUid, true);
                        $users[$curUid]['last_visit'] = $lastVisit;
                    }
                    if (isset($displayMap['last_visit_string'])) {
                        $lvStr                               = is_scalar($lastVisit) ? (string) $lastVisit : null;
                        $users[$curUid]['last_visit_string'] = $this->dateService->formatDate($lvStr, ['day', 'month', 'year']);
                    }
                    if (isset($displayMap['last_visit_since'])) {
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
        if ($perPage === 0 && $displayMap === []) {
            $methodResult = array_column(array_values($users), 'id');
        } else {
            $methodResult = ['paging' => new PwgNamedStruct(['page' => $page, 'per_page' => $perPage, 'count' => count($users), 'total_count' => $totalCount]), 'users' => new PwgNamedArray(array_values($users), 'user')];
        }
        if (isset($displayMap['total_count'])) {
            $methodResult['total_count'] = $totalCount;
        }
        return $methodResult;
    }
}
