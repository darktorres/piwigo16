<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use InvalidArgumentException;
use LogicException;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\Projection\ApiKeySummary;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\User\WsUsersGetList;
use Piwigo\Group\GroupService;
use Piwigo\History\HistoryEntity;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserListCriteria;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;

/**
 * `pwg.users.*` WS methods (16 registrations) -- registered via callable
 * arrays in WsDefaultMethods.
 */
final class Users
{
    public function __construct(
        private readonly UserService $userService,
        private readonly AuthService $authService,
        private readonly ApiKeyService $apiKeyService,
        private readonly GroupService $groupService,
        private readonly ImageService $imageService,
        private readonly AccessControl $accessControl,
        private readonly CurrentUser $currentUser,
        private readonly CurrentConfig $currentConfig,
        private readonly EventDispatcher $eventDispatcher,
        private readonly Lang $lang,
        private readonly Translator $translator,
        private readonly SessionService $sessionService,
        private readonly UrlServiceInterface $urlService,
        private readonly MailService $mailService,
        private readonly PermissionService $permissionService,
        private readonly PageState $pageState,
        private readonly CurrentLogger $currentLogger,
        private readonly PasswordService $passwordService,
        private readonly PreferencesService $preferencesService,
        private readonly ConfigService $configService,
        private readonly WsHelper $wsHelper,
    ) {}

    /**
     * API method
     * Returns a list of users
     *
     * @param array{user_id?: array<int, int>, username?: string, status?: array<int, string>, min_level: int, group_id?: array<int, int>, per_page: int, page: int, order: string, exclude?: array<int, int>, display: string, filter?: string, min_register?: string, max_register?: string, ...} $params
     *   user_id/status/group_id/exclude: WsParamFlag::OPTIONAL with no 'default'
     *   key -- may be entirely absent; FORCE_ARRAY (user_id/group_id/exclude)
     *   always coerces to a list when present. username/filter/min_register/
     *   max_register: WsParamFlag::OPTIONAL with no 'default' key -- may be
     *   entirely absent, no 'type' flag so plain string when present.
     *   min_level/per_page/page: non-null default, WsParamType::INT|WsParamType::POSITIVE
     *   -- always present, always int. order/display: non-null string
     *   defaults, no 'type' flag -- always present, always string.
     *   max_level: not a registered param at all (checked in the body via
     *   $params['max_level'] but absent from this method's ws.php signature)
     *   -- reachable only if a client sends an unregistered extra GET/POST
     *   key, covered by the shape's open tail, never explicitly typed.
     * Genuinely dynamic response shape: which per-user fields are present
     * depends on the client-controlled 'display' param (a comma-separated
     * field list), not a single fixed row shape.
     * @return WsErrorResponse|array<int|string, mixed>
     */
    public function getList(array $params, Server &$service): WsErrorResponse|array
    {
        $available_permission_levels = $this->currentConfig->availablePermissionLevels;

        if (! (bool) preg_match(ValidationPattern::ORDER, $params['order'])) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid input parameter order');
        }

        // Insensitive case sort order
        if (str_contains($params['order'], 'username')) {
            $params['order'] = str_ireplace('username', 'LOWER(username)', $params['order']);
        }

        // Every field below is bound, not spliced into a raw SQL
        // fragment (some were already safe -- $conn->quote(), int casts,
        // enum-filtering -- some weren't, but all are bound regardless
        // of exploitability). Each is null when its filter wasn't
        // requested -- UserRepository::findListForWs() (via
        // UserListCriteria) decides for itself which condition to add.
        $userId = null;
        if (isset($params['user_id']) && $params['user_id'] !== []) {
            $userId = [];
            foreach ($params['user_id'] as $rawUserId) {
                $userIdVo = UserId::tryFrom($rawUserId);
                if ($userIdVo !== null) {
                    $userId[] = $userIdVo;
                }
            }
        }
        $username = (isset($params['username']) && $params['username'] !== '') ? $params['username'] : null;

        $filter = null;
        $filtered_groups = null;
        if (isset($params['filter']) && $params['filter'] !== '') {
            $filter = $params['filter'];
            $filtered_groups = $this->groupService->getIdsByNameLike('%' . $params['filter'] . '%');
        }

        $minRegister = null;
        if (isset($params['min_register']) && $params['min_register'] !== '') {
            if (! (bool) preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $params['min_register'])) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid input parameter min_register');
            }

            $date_tokens = explode('-', $params['min_register']);
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
        if (isset($params['max_register']) && $params['max_register'] !== '') {
            if (! (bool) preg_match('/^\d\d\d\d(-\d{1,2}){0,2}$/', $params['max_register'])) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid input parameter max_register');
            }

            $max_date_tokens = explode('-', $params['max_register']);
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
        if (isset($params['status']) && $params['status'] !== []) {
            $matchedStatus = array_intersect($params['status'], array_map(
                static fn (UserStatus $userStatus): string => $userStatus->value,
                UserStatus::cases()
            ));
            if (count($matchedStatus) > 0) {
                $status = array_values($matchedStatus);
            }
        }

        $minLevel = null;
        if ($params['min_level'] !== 0) {
            if (! in_array($params['min_level'], $available_permission_levels, true)) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid level');
            }
            $minLevel = $params['min_level'];
        }

        $maxLevel = null;
        if (! in_array($params['max_level'] ?? null, [null, false, 0, '0', '', []], true)) {
            if (! in_array(is_numeric($params['max_level']) ? (int) $params['max_level'] : null, $available_permission_levels, true)) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid level');
            }
            // 'max_level' is not a registered ws.php param (see this function's
            // @param docblock) -- reachable only via the shape's open tail, so
            // it's genuinely `mixed` here, unlike 'min_level'.
            $maxLevel = is_numeric($params['max_level']) ? (int) $params['max_level'] : 0;
        }

        $groupId = (isset($params['group_id']) && $params['group_id'] !== []) ? array_values($params['group_id']) : null;
        $exclude = (isset($params['exclude']) && $params['exclude'] !== []) ? array_values($params['exclude']) : null;

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

        // $params['display'] is a comma-separated string per the WS contract
        // (see the @param docblock above); it's never reused/reassigned here as
        // a scratch variable of a different type -- $display_flags is a
        // dedicated, precisely-typed array<string, true> "set" of the
        // requested display options, built via array_fill_keys() (we only ever
        // isset() it, never read its values) so its type stays uniform across
        // every branch below instead of drifting per-branch like array_flip()
        // of a partially-literal list would.
        $display_flags = [];
        if ($params['display'] !== 'none') {
            $requested_display = array_map(trim(...), explode(',', $params['display']));

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

        $apply_limit = $params['per_page'] !== 0 || $display_flags !== [];
        $paginated_users = $this->userService->getListForWs(
            $display,
            isset($display['ui.last_visit']),
            $criteria,
            $params['order'],
            isset($display_flags['total_count']),
            $apply_limit ? $params['per_page'] : null,
            $params['per_page'] * $params['page']
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
                    $group_user_id = is_numeric($group_row['user_id']) ? (int) $group_row['user_id'] : null;
                    $group_id = is_numeric($group_row['group_id']) ? (int) $group_row['group_id'] : null;
                    if ($group_user_id === null || $group_id === null || ! isset($users[$group_user_id]) || ! is_array($users[$group_user_id]['groups'] ?? null)) {
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
                        $lastVisitLookup = EntityManagerFactory::build(DbConnection::build())->getRepository(HistoryEntity::class);
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
        if ($params['per_page'] === 0 && $display_flags === []) {
            $method_result = $users_id_arr;
        } else {
            $method_result = [
                'paging' => new NamedStruct(
                    [
                        'page' => $params['page'],
                        'per_page' => $params['per_page'],
                        'count' => count($users),
                        'total_count' => $total_count,
                    ]
                ),
                'users' => new NamedArray(array_values($users), 'user'),
            ];
        }
        // deprecated: kept for retrocompatibility
        if (isset($display_flags['total_count'])) {
            $method_result['total_count'] = $total_count;
        }
        return $method_result;
    }

    /**
     * API method
     * Adds a user
     *
     * @param array{username: string, auto_password: bool, password: string|null, password_confirm?: string, email: string|null, send_password_by_mail: bool, pwg_token: string, ...} $params
     *   username/pwg_token: no 'default' key -- mandatory, always present.
     *   auto_password/send_password_by_mail: non-null bool default,
     *   WsParamType::BOOL -- always present, always bool. password/email: null
     *   default, no 'type' flag -- always present, string|null.
     *   password_confirm: WsParamFlag::OPTIONAL with no 'default' key -- may be
     *   entirely absent.
     * @return WsErrorResponse|array<int|string, mixed> WsErrorResponse, or the result of
     *   the pwg.users.getList invocation
     */
    public function add(array $params, Server &$service): WsErrorResponse|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        if (strlen(str_replace(' ', '', $params['username'])) === 0) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Name field must not be empty');
        }

        if ($this->currentConfig->doublePasswordTypeInAdmin) {
            if (($params['password'] ?? '') !== ($params['password_confirm'] ?? '')) {
                return new WsErrorResponse(WsError::INVALID_PARAM, $this->lang->t('The passwords do not match'));
            }
        }

        if ($params['auto_password']) {
            $params['password'] = $this->sessionService->generateKey(mt_rand(15, 20));
        }

        // register_user() genuinely requires a string password; a client that
        // sends neither auto_password=true nor an explicit password would
        // otherwise reach it with null and crash inside pwg_password_hash() ->
        // password_hash() (a real string-typed native function).
        if ($params['password'] === null) {
            return new WsErrorResponse(WsError::INVALID_PARAM, $this->lang->t('Please, enter a password'));
        }

        // Preserves the pre-SEC-31 behavior for this real caller (admin-
        // authenticated ws.users.add legitimately needs the real "already
        // used" message to let an operator pick a different username) --
        // UserService::registerUser() itself never puts that message in
        // errors (it would let an attacker enumerate accounts through the
        // public self-registration form).
        $result = $this->userService
            ->registerUser(
                $params['username'],
                $params['password'],
                $params['email'],
                $this->urlService,
                false, // notify admin
                false // $params['send_password_by_mail']
            );

        $errors = $result->errors;
        if ($result->duplicateUsername) {
            array_unshift($errors, $this->lang->t('this login is already used'));
        }

        $user_id = $result->userId ?? false;

        if (! (bool) $user_id) {
            return new WsErrorResponse(WsError::INVALID_PARAM, $errors[0] ?? '');
        }

        return $this->narrowGetListResult($service->invoke('pwg.users.getList', [
            'user_id' => $user_id,
        ]));
    }

    /**
     * API method
     * Get a new authentication key for a user.
     *
     * @param array{user_id: int, pwg_token: string, ...} $params neither has a
     *   'default' key -- both mandatory, always present. user_id: WsParamType::ID,
     *   not FORCE_ARRAY here -- a plain int.
     *
     * @return WsErrorResponse|array{auth_key: string, user_id: int, created_on: string, duration: int, expired_on: string, key_type: string, auth_key_id: string}
     */
    public function getAuthKey(array $params, Server &$service): WsErrorResponse|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $authkey = $this->authService->createUserAuthKey($params['user_id']);

        if ($authkey === false) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'invalid user_id');
        }

        return $authkey;
    }

    /**
     * API method
     * Deletes users
     *
     * @param array{user_id: array<int, int>, pwg_token: string, ...} $params
     *   neither has a 'default' key -- both mandatory, always present;
     *   FORCE_ARRAY always coerces user_id to a list of positive ints.
     */
    public function delete(array $params, Server &$service): WsErrorResponse|string
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $currentUser = $this->currentUser->get();

        $protected_users = [
            $currentUser->id->value,
            $this->currentConfig->guestId,
            $this->currentConfig->defaultUserId,
            $this->currentConfig->webmasterId,
        ];

        // an admin can't delete other admin/webmaster
        if ($currentUser->status === UserStatus::Admin) {
            $protected_users = array_merge($protected_users, $this->userService->getAdminIds());
        }

        // protect some users
        // array_diff() requires every element to be string-castable;
        // array_column()'s list<mixed> return can contain null for a NULL
        // user_id column, and $protected_users' $conf-sourced entries are
        // still `mixed` even after typing $conf above, so filter down to
        // scalars (int/string) before diffing.
        $protected_users = array_filter($protected_users, is_scalar(...));
        $params['user_id'] = array_diff($params['user_id'], $protected_users);

        $counter = 0;

        $user_service = $this->userService;
        foreach ($params['user_id'] as $user_id) {
            $user_service->deleteUser(UserId::from($user_id));
            $counter++;
        }

        return $this->translator->plural(
            '%d user deleted',
            '%d users deleted',
            $counter
        );
    }

    /**
     * API method
     * Updates users
     *
     * @param array{user_id: array<int, int>, username?: string, password?: string, email?: string, status?: string, level?: int, language?: string, theme?: string, group_id?: array<int, int>, nb_image_page?: int, recent_period?: int, expand?: bool, show_nb_comments?: bool, show_nb_hits?: bool, enabled_high?: bool, pwg_token: string, ...} $params
     *   user_id/pwg_token: no 'default' key -- mandatory, always present;
     *   FORCE_ARRAY always coerces user_id to a list of positive ints. every
     *   other key: WsParamFlag::OPTIONAL with no 'default' key -- may be entirely
     *   absent; group_id: WsParamType::INT only (no POSITIVE) since -1 is a valid
     *   value ("dissociate from all groups").
     * @return WsErrorResponse|array<int|string, mixed> WsErrorResponse, or the result of
     *   the pwg.users.getList invocation
     */
    public function setInfo(array $params, Server &$service): WsErrorResponse|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $updated_users = $this->userService->checkAndSaveUserInfos($params, $this->pageState);

        if (isset($updated_users['error'])) {
            // UserService::checkAndSaveUserInfos() is declared to return plain
            // `array`; its error branches always
            // populate error.code (int) and error.message (string), but that
            // shape isn't statically expressed, so narrow defensively here
            // rather than trust the mixed offsets.
            $error = $updated_users['error'];
            $error_code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : WsError::INVALID_PARAM;
            $error_message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Invalid parameters';
            return new WsErrorResponse($error_code, $error_message);
        }

        $updated_infos = is_array($updated_users['infos'] ?? null) ? $updated_users['infos'] : [];

        return $this->narrowGetListResult($service->invoke('pwg.users.getList', [
            'user_id' => $updated_users['user_id'],
            'display' => 'basics,' . implode(',', array_keys($updated_infos)),
        ]));
    }

    /**
     * $service->invoke() is a genuine string-keyed dynamic dispatcher (see
     * Server's own class docblock) -- its declared return type is
     * `mixed` by design. This narrows it to the real shape this specific
     * sub-invocation (always 'pwg.users.getList', which itself really
     * does return WsErrorResponse|array<int|string, mixed>) is known to return,
     * the same "resolve, narrow, or throw" idiom already used throughout
     * this codebase for other statically-unknowable-but-really-fixed-shape
     * values (e.g. PwgImage::currentConfig()'s container resolve).
     *
     * @return WsErrorResponse|array<int|string, mixed>
     */
    private function narrowGetListResult(mixed $result): WsErrorResponse|array
    {
        if (! $result instanceof WsErrorResponse && ! is_array($result)) {
            throw new LogicException('pwg.users.getList returned an unexpected type');
        }

        return $result;
    }

    /**
     * API method
     * Update user
     *
     * @param array{email?: string, nb_image_page?: int, theme?: string, language?: string, recent_period?: int, expand?: bool, show_nb_comments?: bool, show_nb_hits?: bool, password?: string, new_password?: string, conf_new_password?: string, pwg_token: string, ...} $params
     *   pwg_token: no 'default' key -- mandatory, always present. every other
     *   key: WsParamFlag::OPTIONAL with no 'default' key -- may be entirely
     *   absent. (the body's unset() calls for 'username'/'status'/'level'/
     *   'group_id'/'enabled_high' target keys not in this method's own
     *   ws.php registration at all -- harmless no-ops, not part of the real
     *   shape.)
     */
    public function setMyInfo(array $params, Server &$service): WsErrorResponse|string
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(401, 'Access Denied');
        }

        $currentUser = $this->currentUser->get();

        // ACTIVATE_COMMENTS
        if (! $this->currentConfig->activateComments) {
            unset($params['show_nb_comments']);
        }

        // ALLOW_USER_CUSTOMIZATION
        if (! $this->currentConfig->allowUserCustomization) {
            unset(
                $params['nb_image_page'],
                $params['theme'],
                $params['language'],
                $params['recent_period'],
                $params['expand'],
                $params['show_nb_comments'],
                $params['show_nb_hits']
            );
        }

        // SPECIAL_USER
        $special_user = in_array($currentUser->id->value, [$this->currentConfig->guestId, $this->currentConfig->defaultUserId], true);
        if ($special_user) {
            unset(
                $params['password'],
                $params['theme'],
                $params['language']
            );
        }

        if (isset($params['password']) && $params['password'] !== '') {
            if (($params['new_password'] ?? '') !== ($params['conf_new_password'] ?? '')) {
                return new WsErrorResponse(403, $this->lang->t('The passwords do not match'));
            }

            $current_password = $this->authService->getPasswordHash($currentUser->id);
            $current_password ??= '';

            // $params['password'] is declared string via this function's own
            // @param docblock, but the conditional unset($params['password'])
            // above (SPECIAL_USER branch) makes PHPStan lose that offset's
            // precise type after the merge, so it's read back as mixed here.
            $params_password = is_string($params['password']) ? $params['password'] : '';

            if (! $this->passwordService->verify($params_password, $current_password)) {
                return new WsErrorResponse(403, $this->lang->t('Current password is wrong'));
            }

            $params['password'] = $params['new_password'] ?? null;
        }

        // Unset admin field also new and conf password
        unset(
            $params['new_password'],
            $params['conf_new_password'],
            $params['username'],
            $params['status'],
            $params['level'],
            $params['group_id'],
            $params['enabled_high']
        );

        $params['user_id'] = [$currentUser->id->value];
        $updated_users = $this->userService->checkAndSaveUserInfos($params, $this->pageState);

        if (isset($updated_users['error'])) {
            // UserService::checkAndSaveUserInfos() is declared to return plain
            // `array`; its error branches always
            // populate error.code (int) and error.message (string), but that
            // shape isn't statically expressed, so narrow defensively here
            // rather than trust the mixed offsets.
            $error = $updated_users['error'];
            $error_code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : WsError::INVALID_PARAM;
            $error_message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Invalid parameters';
            return new WsErrorResponse($error_code, $error_message);
        }

        return $this->lang->t('Your changes have been applied.');
    }

    /**
     * API method
     * Set a preferences parameter to current user
     *
     * @param array{param: string, value?: string, is_json: bool, ...} $params
     *   param: no 'default' key -- mandatory, always present. value:
     *   WsParamFlag::OPTIONAL with no 'default' key -- may be entirely absent.
     *   is_json: non-null bool default, WsParamType::BOOL -- always present.
     *
     * @return WsErrorResponse|array<string, mixed> matches
     *   Users\User::$preferences' own by-design arbitrary per-user
     *   key-value shape (User.php's own $preferences docblock)
     */
    public function preferencesSet(array $params, Server &$service): WsErrorResponse|array
    {
        if (! (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $params['param'])) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid param name #' . $params['param'] . '#');
        }

        $value = stripslashes($params['value'] ?? '');
        if ($params['is_json']) {
            $value = json_decode($value, true);
        }

        $this->preferencesService->updateParam($params['param'], $value);

        return $this->currentUser->get()
            ->preferences;
    }

    /**
     * API method
     * Adds a favorite image for the current user
     *
     * @param array{image_id: int, ...} $params no 'default' key -- mandatory,
     *   always present, WsParamType::ID guarantees a plain int.
     */
    public function favoritesAdd(array $params, Server &$service): WsErrorResponse|true
    {
        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(403, 'User must be logged in.');
        }

        // does the image really exist?
        if (! $this->imageService->existsById(ImageId::from($params['image_id']))) {
            return new WsErrorResponse(404, 'image_id not found');
        }

        $this->userService->addFavorite($this->currentUser->get()->id, $params['image_id'], ignoreDuplicate: true);

        return true;
    }

    /**
     * API method
     * Removes a favorite image for the current user
     *
     * @param array{image_id: int, ...} $params no 'default' key -- mandatory,
     *   always present, WsParamType::ID guarantees a plain int.
     */
    public function favoritesRemove(array $params, Server &$service): WsErrorResponse|true
    {
        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(403, 'User must be logged in.');
        }

        // does the image really exist?
        if (! $this->imageService->existsById(ImageId::from($params['image_id']))) {
            return new WsErrorResponse(404, 'image_id not found');
        }

        $this->userService->removeFavorite($this->currentUser->get()->id, $params['image_id']);

        return true;
    }

    /**
     * API method
     * Returns the favorite images of the current user
     *
     * @param array{per_page: int, page: int, order: string|null, ...} $params
     *   per_page/page: non-null int default, WsParamType::INT|WsParamType::POSITIVE --
     *   always present. order: null default, no 'type' flag -- always
     *   present, string|null.
     * @return false|array{paging: NamedStruct, images: NamedArray}
     */
    public function favoritesGetList(array $params, Server &$service): false|array
    {

        if ($this->accessControl->isAGuest()) {
            return false;
        }

        $this->userService->checkUserFavorites();

        $order_by = $this->wsHelper->stdImageSqlOrder($params, 'i.');
        $order_by = $order_by === '' ? $this->currentConfig->orderBy : 'ORDER BY ' . $order_by;

        $permission_condition = $this->permissionService->getPermissionCriteria();

        $images = [];
        foreach ($this->userService->getVisibleFavoriteImages($this->currentUser->get()->id, $permission_condition, $order_by) as $row) {
            $image = [];

            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                }
            }

            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k] ?? null;
            }

            $images[] = array_merge($image, $this->wsHelper->stdGetUrls($row, $this->urlService));
        }

        $count = count($images);
        $images = array_slice($images, $params['per_page'] * $params['page'], $params['per_page']);

        return [
            'paging' => new NamedStruct(
                [
                    'page' => $params['page'],
                    'per_page' => $params['per_page'],
                    'count' => $count,
                ]
            ),
            'images' => new NamedArray(
                $images,
                'image',
                $this->wsHelper->stdGetImageXmlAttributes()
            ),
        ];
    }

    /**
     * API method
     * Returns the reset password link of the current user
     *
     * @param array{user_id: int, pwg_token: string, send_by_mail: bool, ...} $params
     *   user_id/pwg_token: no 'default' key -- mandatory, always present,
     *   WsParamType::ID guarantees a plain int for user_id. send_by_mail: non-null
     *   bool default, WsParamType::BOOL -- always present.
     * @return WsErrorResponse|array{generated_link: string, send_by_mail: string|false|null, time_validation: string}
     */
    public function generatePasswordLink(array $params, Server &$service): WsErrorResponse|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $lost_user_id = UserId::from($params['user_id']);

        // check if user exist
        if ($this->userService->getUsername($lost_user_id) === null) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'This user does not exist.');
        }

        // UserService::getUserData() is declared to return
        // array<string, mixed> (its own @return docblock); narrow the
        // specific fields this function consumes to their real column types.
        $user_lost = $this->userService->getUserData($lost_user_id);
        $user_lost_status = is_string($user_lost['status']) ? $user_lost['status'] : '';

        // Cannot perform this action for a guest or generic user
        if ($this->accessControl->isAGuest($user_lost_status) or $this->accessControl->isGeneric($user_lost_status)) {
            return new WsErrorResponse(403, 'Password reset is not allowed for this user');
        }

        // Only webmaster can perform this action for another webmaster
        if ($this->currentUser->get()->status === UserStatus::Admin && $user_lost_status === 'webmaster') {
            return new WsErrorResponse(403, 'You cannot perform this action');
        }

        $conn = DbConnection::build();
        $first_login = $this->authService->hasAlreadyLoggedIn($params['user_id'], EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class));
        $send_by_mail_response = null;
        $user_lost_language = is_string($user_lost['language']) ? $user_lost['language'] : $this->userService->getDefaultLanguage();
        $lang_to_use = $first_login ? $this->userService->getDefaultLanguage() : $user_lost_language;

        $this->mailService
            ->switchLangTo($lang_to_use);
        $generate_link = $this->authService->generatePasswordLink($params['user_id'], $this->urlService, $first_login);

        $user_lost_email = is_string($user_lost['email']) ? $user_lost['email'] : null;

        // $this->currentConfig->galleryTitle is a raw config string; pwg_generate_set/
        // reset_password_mail() both require a real string for their 3rd
        // parameter.
        $gallery_title = $this->currentConfig->galleryTitle;

        if ($params['send_by_mail'] and ! in_array($user_lost_email, [null, ''], true)) {
            $user_lost_username = is_string($user_lost['username']) ? $user_lost['username'] : '';
            if ($first_login) {
                $email_params = $this->mailService
                    ->generateSetPasswordMail($user_lost_username, $generate_link['password_link'], $gallery_title, $generate_link['time_validation']);
            } else {
                $email_params = $this->mailService
                    ->generateResetPasswordMail($user_lost_username, $generate_link['password_link'], $gallery_title, $generate_link['time_validation']);
            }
            // Here we remove the display of errors because they prevent the response from being parsed
            if (@$this->mailService->mail($user_lost_email, $email_params->toArray())) {
                $send_by_mail_response = 'Mail sent at : ' . $user_lost_email;
            } else {
                $send_by_mail_response = false;
            }
        }
        $this->mailService
            ->switchLangBack();

        return [
            'generated_link' => $generate_link['password_link'],
            'send_by_mail' => $send_by_mail_response,
            'time_validation' => $generate_link['time_validation'],
        ];
    }

    /**
     * API method
     * Set a user as the main user
     *
     * @param array{user_id: int, pwg_token: string, ...} $params neither has a
     *   'default' key -- both mandatory, always present, WsParamType::ID guarantees
     *   a plain int for user_id.
     */
    public function setMainUser(array $params, Server &$service): WsErrorResponse|string
    {
        // check if not webmaster
        if (! $this->accessControl->isWebmaster()) {
            return new WsErrorResponse(403, 'You cannot perform this action');
        }

        // check pwg_token
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $new_main_user_id = UserId::from($params['user_id']);

        // checl if user exist
        if ($this->userService->getUsername($new_main_user_id) === null) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'This user does not exist.');
        }

        $new_main_user = $this->userService->getUserData($new_main_user_id);

        // check if the user to set as main user is not webmaster
        if ($new_main_user['status'] !== 'webmaster') {
            return new WsErrorResponse(403, 'This user cannot become a main user because he is not a webmaster.');
        }

        $this->configService->confUpdateParam('webmaster_id', $params['user_id']);
        return 'The main user has been changed.';
    }

    /**
     * API method
     * Create a new api key for the current user
     *
     * @param array{key_name: string, duration: int, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present; duration:
     *   WsParamType::INT|WsParamType::POSITIVE guarantees a plain int.
     * @return WsErrorResponse|array{auth_key: string, apikey_secret: string, apikey_name: string, user_id: int, created_on: string, duration: int, key_type: string, expired_on: string}
     */
    public function createApiKey(array $params, Server &$service): WsErrorResponse|array
    {
        $logger = $this->currentLogger->get();

        if ($this->accessControl->isAGuest() or ! $this->apiKeyService->connectedWithPwgUi()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        if ($params['duration'] < 1 or $params['duration'] > 999999) {
            return new WsErrorResponse(400, 'Invalid duration max days is 999999');
        }

        if (strlen($params['key_name']) > 100) {
            return new WsErrorResponse(400, 'Key name is too long');
        }

        // realEscapeString() dropped: ApiKeyRepository::insert() parameterizes
        // apikey_name instead of interpolating it, same "dead pre-escaping"
        // rationale as Ws\Tags::rename().
        $key_name = $params['key_name'];
        // the guard above already rejects any duration outside [1, 999999], so
        // it can never be 0 here.
        $duration = $params['duration'];

        $user_id = $this->currentUser->get()
            ->id->value;

        $secret = $this->apiKeyService->create($user_id, $duration, $key_name);

        $logger->info('[api_key][user_id=' . $user_id . '][action=create][key_name=' . $params['key_name'] . ']');

        return $secret->toArray();
    }

    /**
     * API method
     * Revoke a api key for the current user
     *
     * @param array{pkid: string, pwg_token: string, ...} $params neither has a
     *   'default' key -- both mandatory, always present, no 'type' flag.
     */
    public function revokeApiKey(array $params, Server &$service): WsErrorResponse|string
    {
        $logger = $this->currentLogger->get();

        if ($this->accessControl->isAGuest() or ! $this->apiKeyService->connectedWithPwgUi()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, $this->lang->t('Invalid security token'));
        }

        if (! (bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $params['pkid'])) {
            return new WsErrorResponse(403, $this->lang->t('Invalid pkid format'));
        }

        $user_id = $this->currentUser->get()
            ->id->value;

        $revoked_key = $this->apiKeyService->revoke($user_id, $params['pkid']);

        if ($revoked_key !== true) {
            return new WsErrorResponse(403, $revoked_key);
        }

        $logger->info('[api_key][user_id=' . $user_id . '][action=revoke][pkid=' . $params['pkid'] . ']');

        return $this->lang->t('API Key has been successfully revoked.');
    }

    /**
     * API method
     * Edit a api key for the current user
     *
     * @param array{key_name: string, pkid: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present, no 'type'
     *   flag.
     */
    public function editApiKey(array $params, Server &$service): WsErrorResponse|string
    {
        $logger = $this->currentLogger->get();

        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        if (! $this->apiKeyService->connectedWithPwgUi()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, $this->lang->t('Invalid security token'));
        }

        if (! (bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $params['pkid'])) {
            return new WsErrorResponse(403, $this->lang->t('Invalid pkid format'));
        }

        // realEscapeString() dropped: ApiKeyRepository::updateName()
        // parameterizes apikey_name instead of interpolating it, same
        // "dead pre-escaping" rationale as createApiKey() above.
        $key_name = $params['key_name'];
        $user_id = $this->currentUser->get()
            ->id->value;
        $edited_key = $this->apiKeyService->edit($user_id, $params['pkid'], $key_name);

        if ($edited_key !== true) {
            return new WsErrorResponse(403, $edited_key);
        }

        $logger->info('[api_key][user_id=' . $user_id . '][action=edit][pkid=' . $params['pkid'] . '][new_name=' . $key_name . ']');

        return $this->lang->t('API Key has been successfully edited.');
    }

    /**
     * API method
     * Get all api key for the current user
     *
     * @param array{pwg_token: string, ...} $params no 'default' key --
     *   mandatory, always present, no 'type' flag.
     * @return WsErrorResponse|string|list<array{auth_key: string, apikey_secret: string, apikey_name: string, created_on: string, duration: ?int, expired_on: string, revoked_on: ?string, last_used_on: ?string, last_notified_on: ?string, created_on_format: string, expired_on_format: string, last_used_on_since: string, is_expired: bool, expiration: string, expired_on_since: string, revoked_on_since: ?string, revoked_on_message: ?string}>
     */
    public function getApiKey(array $params, Server &$service): WsErrorResponse|array|string
    {
        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        if (! $this->apiKeyService->connectedWithPwgUi()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        // ApiKeyService::get() takes a native int $userId, same as
        // create()/revoke()/edit() above.
        $user_id = $this->currentUser->get()
            ->id->value;
        $api_keys = $this->apiKeyService->get($user_id);

        return ((bool) $api_keys) ? array_map(static fn (ApiKeySummary $key): array => $key->toArray(), $api_keys) : $this->lang->t('No API key found');
    }
}
