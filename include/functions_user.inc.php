<?php

declare(strict_types=1);


// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\user
 */

function validate_mail_address(?int $user_id, ?string $mail_address): string|null
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->validateMailAddress($user_id, $mail_address);
}

function validate_login_case(mixed $login): string|null
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->validateLoginCase($login);
}

function search_case_username(mixed $username): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->searchCaseUsername($username);
}

function calculate_auto_login_key(mixed $user_id, mixed $time, mixed &$username): string|false
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->calculateAutoLoginKey($user_id, $time, $username);
}

function log_user(mixed $user_id, mixed $remember_me): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->logUser($user_id, $remember_me);
}

function auto_login(): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->autoLogin();
}

function try_log_user(mixed $username, mixed $password, mixed $remember_me): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->tryLogUser($username, $password, $remember_me);
}

// File-level: register the default login handler — must stay here since
// add_event_handler() calls EventDispatcher directly (no ServiceLocator).
add_event_handler('try_log_user', 'pwg_login');

function pwg_login(bool $success, string $username, string $password, bool $remember_me): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->pwgLogin($success, $username, $password, $remember_me);
}

/** @return array<string,mixed>|null */
function find_user_by_username_or_email(string $username_or_email): ?array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->findUserByUsernameOrEmail($username_or_email);
}

/** @return array<string,mixed> */
function generate_fake_user(): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->generateFakeUser();
}

function clear_fake_user_cache(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->clearFakeUserCache();
}

function logout_user(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->logoutUser();
}

function auth_key_login(string $auth_key, bool $connection_by_header = false): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->authKeyLogin($auth_key, $connection_by_header);
}

/** @return array<string,mixed>|false */
function create_user_auth_key(int $user_id, ?string $user_status = null): array|false
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->createUserAuthKey($user_id, $user_status);
}

function deactivate_user_auth_keys(mixed $user_id): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->deactivateUserAuthKeys($user_id);
}

function deactivate_password_reset_key(mixed $user_id): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->deactivatePasswordResetKey($user_id);
}

/** @return array<string,mixed> */
function generate_password_link(int $user_id, bool $first_login = false): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->generatePasswordLink($user_id, $first_login);
}

function connected_with_pwg_ui(): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\AuthService::class)->connectedWithPwgUi();
}

/** @param string[] $errors */
function register_user(string $login, string $password, ?string $mail_address = null, bool $notify_admin = true, array &$errors = [], bool $notify_user = false): int|false
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->registerUser($login, $password, $mail_address, $notify_admin, $errors, $notify_user);
}

/** @return array<string,mixed> */
function build_user(int $user_id, bool $use_cache = true): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->buildUser($user_id, $use_cache);
}

/** @return array<string,mixed>|false */
function getuserdata(int $user_id, bool $use_cache = false): array|false
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getuserdata($user_id, $use_cache);
}

function check_user_favorites(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->checkUserFavorites();
}

function get_userid(string $username): int|false
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getUserid($username);
}

function get_userid_by_email(string $email): int|false
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getUseridByEmail($email);
}

/** @return array<mixed,mixed>|null */
function get_default_user_info(bool $convert_str = true): ?array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getDefaultUserInfo($convert_str);
}

function get_default_user_value(mixed $value_name, mixed $default): mixed
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getDefaultUserValue($value_name, $default);
}

function get_default_theme(): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getDefaultTheme();
}

function get_default_language(): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getDefaultLanguage();
}

/**
 * @param int[]|int         $user_ids
 * @param array<mixed>|null $override_values
 */
function create_user_infos(array|int $user_ids, ?array $override_values = null): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->createUserInfos($user_ids, $override_values);
}

function get_user_last_visit_from_history(mixed $user_id, bool $save_in_user_infos = false): ?string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getUserLastVisitFromHistory($user_id, $save_in_user_infos);
}

function has_already_logged_in(mixed $user_id): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->hasAlreadyLoggedIn($user_id);
}

/**
 * @param array<mixed>  $params
 * @return array<mixed>
 */
function check_and_save_user_infos(array $params): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->checkAndSaveUserInfos($params);
}

/** @return array<string,mixed> */
function create_api_key(int $user_id, int $duration, string $key_name): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->createApiKey($user_id, $duration, $key_name);
}

/** @return list<array<mixed>>|false */
function get_api_key(string $user_id): false|array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getApiKey($user_id);
}

/** @return array<mixed>|false */
function get_available_api_key(string $user_id): array|false
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getAvailableApiKey($user_id);
}

function revoke_api_key(mixed $user_id, string $pkid): string|bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->revokeApiKey($user_id, $pkid);
}

function edit_api_key(int $user_id, string $pkid, string $api_name): string|true
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->editApiKey($user_id, $pkid, $api_name);
}

function notification_api_key_expiration(string $username, string $email, int $days_left): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->notificationApiKeyExpiration($username, $email, $days_left);
}

/** @return array<string,mixed> */
function generate_user_code(): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->generateUserCode();
}

function verify_user_code(string $secret, string $code): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->verifyUserCode($secret, $code);
}

function save_edit_context(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->saveEditContext();
}

function get_edit_context(mixed $image_id): false|string|null
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserService::class)->getEditContext($image_id);
}

function get_user_status(string $user_status = ''): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->getUserStatus($user_status);
}

function get_access_type_status(string $user_status = ''): int
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->getAccessTypeStatus($user_status);
}

function is_autorize_status(int $access_type, string $user_status = ''): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->isAutorizeStatus($access_type, $user_status);
}

function check_status(int $access_type, string $user_status = ''): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->checkStatus($access_type, $user_status);
}

function is_generic(string $user_status = ''): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->isGeneric($user_status);
}

function is_a_guest(string $user_status = ''): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->isAGuest($user_status);
}

function is_classic_user(string $user_status = ''): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->isClassicUser($user_status);
}

function is_admin(string $user_status = ''): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->isAdmin($user_status);
}

function is_webmaster(string $user_status = ''): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->isWebmaster($user_status);
}

function can_manage_comment(string $action, mixed $comment_author_id): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->canManageComment($action, $comment_author_id);
}

function calculate_permissions(mixed $user_id, string $user_status): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->calculatePermissions(is_numeric($user_id) ? (int) $user_id : 0, $user_status);
}

/** @param array<string,string> $condition_fields */
function get_sql_condition_FandF(array $condition_fields, ?string $prefix_condition = null, bool $force_one_condition = false): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->getSqlConditionFandF($condition_fields, $prefix_condition, $force_one_condition);
}

function get_recent_photos_sql(string $db_field): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PermissionService::class)->getRecentPhotosSql($db_field);
}

function get_browser_language(): false|int|string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PreferencesService::class)->getBrowserLanguage();
}

function userprefs_save(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PreferencesService::class)->userprefsSave();
}

function userprefs_update_param(mixed $param, mixed $value): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PreferencesService::class)->userprefsUpdateParam($param, $value);
}

function userprefs_delete_param(mixed $params): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PreferencesService::class)->userprefsDeleteParam(is_array($params) || is_string($params) ? $params : []);
}

function userprefs_get_param(mixed $param, mixed $default_value = null): mixed
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\PreferencesService::class)->userprefsGetParam($param, $default_value);
}
