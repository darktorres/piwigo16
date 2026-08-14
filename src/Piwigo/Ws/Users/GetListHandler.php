<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Piwigo\Auth\AuthService;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\Core\ValidationPattern;
use Piwigo\Core\WsError;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\User\WsUsersGetList;
use Piwigo\Group\GroupService;
use Piwigo\History\HistoryEntity;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\UserListCriteria;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.getList`. Genuinely dynamic response shape: which per-user
 * fields are present depends on the client-controlled 'display' param
 * (a comma-separated field list), not a single fixed row shape.
 */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private UserService $userService,
        private GroupService $groupService,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private AuthService $authService,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<int|string, mixed>
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = GetListParams::fromArray($params);
        $available_permission_levels = $this->currentConfig->availablePermissionLevels;

        if (! (bool) preg_match(ValidationPattern::ORDER, $input->order)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid input parameter order');
        }

        // Insensitive case sort order
        $order = $input->order;
        if (str_contains($order, 'username')) {
            $order = str_ireplace('username', 'LOWER(username)', $order);
        }

        // Every field below is bound, not spliced into a raw SQL
        // fragment (some were already safe -- $conn->quote(), int casts,
        // enum-filtering -- some weren't, but all are bound regardless
        // of exploitability). Each is null when its filter wasn't
        // requested -- UserRepository::findListForWs() (via
        // UserListCriteria) decides for itself which condition to add.
        $userId = null;
        if ($input->userIds !== []) {
            $userId = [];
            foreach ($input->userIds as $rawUserId) {
                $userIdVo = UserId::tryFrom($rawUserId);
                if ($userIdVo instanceof UserId) {
                    $userId[] = $userIdVo;
                }
            }
        }
        $username = $input->username;

        $filter = null;
        $filtered_groups = null;
        if ($input->filter !== null) {
            $filter = $input->filter;
            $filtered_groups = $this->groupService->getIdsByNameLike('%' . $input->filter . '%');
        }

        $minRegister = null;
        if ($input->minRegister !== null) {
            if (! (bool) preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $input->minRegister)) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid input parameter min_register');
            }

            $date_tokens = explode('-', $input->minRegister);
            $min_register_year = $date_tokens[0];
            $min_register_month = $date_tokens[1] ?? 1;
            $min_register_day = $date_tokens[2] ?? 1;
            $min_date = sprintf('%u-%02u-%02u', (int) $min_register_year, (int) $min_register_month, (int) $min_register_day);

            // The regex above only checks the token *shape* (1-4 numeric
            // groups), not real calendar validity -- e.g. 'min_register=
            // 9999-13-99' passes it. SqlDateTime::from()'s own calendar
            // round-trip check is the real validator; a genuinely invalid
            // date now returns a proper WS error instead of silently
            // reaching the SQL comparison as an uncomparable string.
            try {
                $minRegister = SqlDateTime::from($min_date . ' 00:00:00');
            } catch (InvalidArgumentException) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid input parameter min_register');
            }
        }

        $maxRegister = null;
        if ($input->maxRegister !== null) {
            if (! (bool) preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $input->maxRegister)) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid input parameter max_register');
            }

            $max_date_tokens = explode('-', $input->maxRegister);
            $max_register_year = $max_date_tokens[0];
            $max_register_month = $max_date_tokens[1] ?? 12;
            if (isset($max_date_tokens[2])) {
                $max_register_day = $max_date_tokens[2];
            } else {
                // year/month were regex-validated above
                // (\d\d\d\d(-\d{1,2}){0,2}), so this is always a well-formed
                // date string
                $max_register_month_ts = strtotime($max_register_year . '-' . $max_register_month . '-1');
                assert($max_register_month_ts !== false);
                $max_register_day = date('t', $max_register_month_ts);
            }
            $max_date = sprintf('%u-%02u-%02u', (int) $max_register_year, (int) $max_register_month, (int) $max_register_day);

            // Same real-calendar-validity gap as min_register above.
            try {
                $maxRegister = SqlDateTime::from($max_date . ' 23:59:59');
            } catch (InvalidArgumentException) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid input parameter max_register');
            }
        }

        $status = null;
        if ($input->status !== []) {
            $matchedStatus = array_intersect($input->status, array_map(
                static fn (UserStatus $userStatus): string => $userStatus->value,
                UserStatus::cases()
            ));
            if (count($matchedStatus) > 0) {
                $status = array_values($matchedStatus);
            }
        }

        $minLevel = null;
        if ($input->minLevel !== 0) {
            if (! in_array($input->minLevel, $available_permission_levels, true)) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid level');
            }
            $minLevel = $input->minLevel;
        }

        // 'max_level' is not a registered ws.php param (see GetListParams'
        // own docblock) -- reachable only via an unregistered extra
        // GET/POST key, so it's read straight off the raw $params array,
        // genuinely `mixed`, unlike 'min_level'.
        $maxLevel = null;
        $raw_max_level = $params['max_level'] ?? null;
        if (! in_array($raw_max_level, [null, false, 0, '0', '', []], true)) {
            if (! in_array(is_numeric($raw_max_level) ? (int) $raw_max_level : null, $available_permission_levels, true)) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid level');
            }
            $maxLevel = is_numeric($raw_max_level) ? (int) $raw_max_level : 0;
        }

        $groupId = $input->groupIds !== [] ? $input->groupIds : null;
        $exclude = $input->exclude !== [] ? $input->exclude : null;

        $criteria = new UserListCriteria(
            userId: $userId,
            username: $username,
            filter: $filter,
            filteredGroupIds: $filtered_groups,
            minRegister: $minRegister,
            maxRegister: $maxRegister,
            status: $status,
            minLevel: $minLevel,
            maxLevel: $maxLevel,
            groupId: $groupId,
            exclude: $exclude,
        );

        $display = [
            'u.id' => 'id',
        ];

        // $input->display is a comma-separated string per the WS contract;
        // it's never reused/reassigned here as a scratch variable of a
        // different type -- $display_flags is a dedicated, precisely-typed
        // array<string, true> "set" of the requested display options,
        // built via array_fill_keys() (we only ever isset() it, never read
        // its values) so its type stays uniform across every branch below
        // instead of drifting per-branch like array_flip() of a
        // partially-literal list would.
        $display_flags = [];
        if ($input->display !== 'none') {
            $requested_display = array_map(trim(...), explode(',', $input->display));

            if (in_array('all', $requested_display, true)) {
                $requested_display = [
                    'username', 'email', 'status', 'level', 'groups', 'language', 'theme',
                    'nb_image_page', 'recent_period', 'expand', 'show_nb_comments', 'show_nb_hits',
                    'enabled_high', 'registration_date', 'registration_date_string',
                    'registration_date_since', 'last_visit', 'last_visit_string',
                    'last_visit_since', 'total_count',
                ];
            } elseif (in_array('basics', $requested_display, true)) {
                $requested_display = array_merge($requested_display, [
                    'username', 'email', 'status', 'level', 'groups',
                ]);
            } elseif (in_array('only_id', $requested_display, true)) {
                $requested_display = [];
            }
            $display_flags = array_fill_keys($requested_display, true);

            // if registration_date_string or registration_date_since is requested,
            // then registration_date is automatically added
            if (isset($display_flags['registration_date_string']) or isset($display_flags['registration_date_since'])) {
                $display_flags['registration_date'] = true;
            }

            // if last_visit_string or last_visit_since is requested, then
            // last_visit is automatically added
            if (isset($display_flags['last_visit_string']) or isset($display_flags['last_visit_since'])) {
                $display_flags['last_visit'] = true;
            }

            if (isset($display_flags['username'])) {
                $display['u.username'] = 'username';
            }
            if (isset($display_flags['email'])) {
                $display['u.mail_address'] = 'email';
            }

            $ui_fields = [
                'status', 'level', 'language', 'theme', 'nb_image_page', 'recent_period', 'expand',
                'show_nb_comments', 'show_nb_hits', 'enabled_high', 'registration_date',
                'last_visit',
            ];
            foreach ($ui_fields as $field) {
                if (isset($display_flags[$field])) {
                    $display['ui.' . $field] = $field;
                }
            }
        }

        $apply_limit = $input->perPage !== 0 || $display_flags !== [];
        $paginated_users = $this->userService->getListForWs(
            $display,
            isset($display['ui.last_visit']),
            $criteria,
            $order,
            isset($display_flags['total_count']),
            $apply_limit ? $input->perPage : null,
            $input->perPage * $input->page
        );
        $users = [];
        $rows = $paginated_users->rows;
        $total_count = $paginated_users->total ?? 0;

        // Extracted once (instead of re-checking isset($display_flags['groups'])
        // both inside this loop and again below it) because PHPStan's loop-body
        // type narrowing otherwise mis-infers the offset as unconditionally
        // present after the loop.
        $want_groups = isset($display_flags['groups']);
        foreach ($rows as $row) {
            $row['id'] = is_numeric($row['id']) ? (int) $row['id'] : 0;
            if ($want_groups) {
                $row['groups'] = []; // will be filled later
            }
            $users[$row['id']] = $row;
        }

        $users_id_arr = [];
        if (count($users) > 0) {
            if ($want_groups) {
                // a dedicated $group_row (instead of reusing $row from the loop
                // above, which iterates a differently-shaped result set) keeps
                // PHPStan's per-loop type inference precise.
                foreach ($this->groupService->getMembershipsForUserIds(array_keys($users)) as $group_row) {
                    $group_user_id = $group_row['user_id'];
                    $group_id = $group_row['group_id'];
                    if (! isset($users[$group_user_id]) || ! is_array($users[$group_user_id]['groups'] ?? null)) {
                        continue;
                    }
                    $users[$group_user_id]['groups'][] = $group_id;
                }
            }
            foreach ($users as $cur_user) {
                // $cur_user['id'] was intval()'d above when $users was
                // populated, so it's already a real int here.
                $cur_user_id = $cur_user['id'];
                $users_id_arr[] = $cur_user_id;

                $cur_user_registration_date = is_string($cur_user['registration_date'] ?? null) ? $cur_user['registration_date'] : null;

                if (isset($display_flags['registration_date_string'])) {
                    $users[$cur_user_id]['registration_date_string'] = DateHelper::formatDate($cur_user_registration_date ?? false, ['day', 'month', 'year']);
                }
                if (isset($display_flags['registration_date_since'])) {
                    $users[$cur_user_id]['registration_date_since'] = DateHelper::timeSince($cur_user_registration_date ?? '', 'month');
                }
                if (isset($display_flags['last_visit'])) {
                    $last_visit = is_string($cur_user['last_visit']) ? $cur_user['last_visit'] : null;
                    $users[$cur_user_id]['last_visit'] = $last_visit;

                    if (! SqlDialect::getBoolean($cur_user['last_visit_from_history']) and in_array($last_visit, [null, ''], true)) {
                        $lastVisitLookup = $this->entityManager->getRepository(HistoryEntity::class);
                        $last_visit = $this->authService->getUserLastVisitFromHistory($cur_user_id, $lastVisitLookup, true);
                        $users[$cur_user_id]['last_visit'] = $last_visit;
                    }

                    if (isset($display_flags['last_visit_string'])) {
                        $users[$cur_user_id]['last_visit_string'] = DateHelper::formatDate($last_visit ?? false, ['day', 'month', 'year']);
                    }

                    if (isset($display_flags['last_visit_since'])) {
                        $users[$cur_user_id]['last_visit_since'] = DateHelper::timeSince($last_visit ?? '', 'day');
                    }
                }
            }
        }
        // WsUsersGetList::$users is a non-nullable PHP `array` property --
        // dispatchChange()'s own instanceof check guarantees a real array
        // here, unlike the old mixed-returning trigger_change(), so no
        // fallback-to-original-$users guard is needed anymore.
        $users = $this->eventDispatcher->dispatchChange(new WsUsersGetList($users))
            ->users;
        if ($input->perPage === 0 && $display_flags === []) {
            $method_result = $users_id_arr;
        } else {
            $method_result = [
                'paging' => new NamedStruct(
                    [
                        'page' => $input->page,
                        'per_page' => $input->perPage,
                        'count' => count($users),
                        'total_count' => $total_count,
                    ]
                ),
                'users' => new NamedArray(array_values($users), 'user'),
            ];
        }
        return $method_result;
    }
}
