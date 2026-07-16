<?php

declare(strict_types=1);

// P23 batch 8d one-shot codemod map: old free-function name => [constructor
// PHP expression code, new instance method name]. Every mapped function is
// a pure rename -- the new class method has an identical signature to the
// free function it replaces, so passing the original call's arguments
// through unchanged is always correct. See
// tools/rector-rules/FuncCallToNewMethodCallRector.php.

$activityServiceCtor = 'new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))';
$userServiceCtor = 'new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), ' . $activityServiceCtor . ')';
$authServiceCtor = 'new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), ' . $activityServiceCtor . ')';
$passwordServiceCtor = 'new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\DbConnection::build()))';
$permissionServiceCtor = 'new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()))';
$preferencesServiceCtor = 'new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()))';
$apiKeyServiceCtor = 'new \Piwigo\Auth\ApiKeyService(new \Piwigo\Mail\MailService())';
$csrfServiceCtor = 'new \Piwigo\Csrf\CsrfService()';
$inputValidatorCtor = 'new \Piwigo\Validation\InputValidator()';
$paginationServiceCtor = 'new \Piwigo\Core\PaginationService()';
$ephemeralKeyServiceCtor = 'new \Piwigo\Auth\EphemeralKeyService()';

return [
    'search_case_username' => [$userServiceCtor, 'searchCaseUsername'],
    'get_userid' => [$userServiceCtor, 'getUserId'],
    'get_userid_by_email' => [$userServiceCtor, 'getUserIdByEmail'],
    'get_default_user_info' => [$userServiceCtor, 'getDefaultUserInfo'],
    'get_default_user_value' => [$userServiceCtor, 'getDefaultUserValue'],
    'create_user_infos' => [$userServiceCtor, 'createUserInfos'],
    'validate_mail_address' => [$userServiceCtor, 'validateMailAddress'],
    'build_user' => [$userServiceCtor, 'buildUser'],
    'getuserdata' => [$userServiceCtor, 'getUserData'],
    'check_user_favorites' => [$userServiceCtor, 'checkUserFavorites'],
    'get_default_theme' => [$userServiceCtor, 'getDefaultTheme'],
    'get_default_language' => [$userServiceCtor, 'getDefaultLanguage'],
    'get_browser_language' => [$userServiceCtor, 'getBrowserLanguage'],
    'get_recent_photos_sql' => [$userServiceCtor, 'getRecentPhotosSql'],
    'save_edit_context' => [$userServiceCtor, 'saveEditContext'],
    'get_edit_context' => [$userServiceCtor, 'getEditContext'],
    'check_and_save_user_infos' => [$userServiceCtor, 'checkAndSaveUserInfos'],

    // calculate_auto_login_key() intentionally NOT mapped: its free-function
    // signature has a by-ref 3rd param (&$username) that AuthService::
    // calculateAutoLoginKey() replaced with an array return -- confirmed
    // zero real external callers remain (dry-run matched none), so it's
    // dropped outright below rather than retargeted.
    'log_user' => [$authServiceCtor, 'logUser'],
    'auto_login' => [$authServiceCtor, 'autoLogin'],
    'try_log_user' => [$authServiceCtor, 'tryLogUser'],
    'logout_user' => [$authServiceCtor, 'logoutUser'],
    'auth_key_login' => [$authServiceCtor, 'authKeyLogin'],
    'create_user_auth_key' => [$authServiceCtor, 'createUserAuthKey'],
    'deactivate_user_auth_keys' => [$authServiceCtor, 'deactivateUserAuthKeys'],
    'deactivate_password_reset_key' => [$authServiceCtor, 'deactivatePasswordResetKey'],
    'generate_password_link' => [$authServiceCtor, 'generatePasswordLink'],
    'get_user_last_visit_from_history' => [$authServiceCtor, 'getUserLastVisitFromHistory'],
    'has_already_logged_in' => [$authServiceCtor, 'hasAlreadyLoggedIn'],

    'pwg_password_hash' => [$passwordServiceCtor, 'hash'],
    'pwg_password_verify' => [$passwordServiceCtor, 'verify'],

    'calculate_permissions' => [$permissionServiceCtor, 'getForbiddenCategories'],
    'get_sql_condition_FandF' => [$permissionServiceCtor, 'getSqlConditionFandF'],

    'userprefs_save' => [$preferencesServiceCtor, 'save'],
    'userprefs_update_param' => [$preferencesServiceCtor, 'updateParam'],
    'userprefs_delete_param' => [$preferencesServiceCtor, 'deleteParam'],
    'userprefs_get_param' => [$preferencesServiceCtor, 'getParam'],

    'create_api_key' => [$apiKeyServiceCtor, 'create'],
    'revoke_api_key' => [$apiKeyServiceCtor, 'revoke'],
    'edit_api_key' => [$apiKeyServiceCtor, 'edit'],
    'get_api_key' => [$apiKeyServiceCtor, 'get'],
    'get_available_api_key' => [$apiKeyServiceCtor, 'getAvailable'],
    'connected_with_pwg_ui' => [$apiKeyServiceCtor, 'connectedWithPwgUi'],
    'notification_api_key_expiration' => [$apiKeyServiceCtor, 'notifyExpiration'],

    // P23 batch 8d, include/functions.inc.php pass 1 (already-delegating
    // functions -- same "half the work is done" shape as pass 0).
    // pwg_activity() itself: the 3 real L2aCoreDomain callers
    // (UserService/GroupService/AuthService) were fixed by hand first
    // (constructor-injected ActivityLoggerInterface, $this->activityLogger->
    // record()) BEFORE this map runs, so this mapping only ever matches
    // the remaining L3/L4/legacy call sites -- safe to retarget straight
    // to the concrete class there.
    'pwg_activity' => [$activityServiceCtor, 'record'],
    'get_pwg_token' => [$csrfServiceCtor, 'getToken'],
    'check_input_parameter' => [$inputValidatorCtor, 'validate'],
    'create_navigation_bar' => [$paginationServiceCtor, 'createNavigationBar'],
    'get_ephemeral_key' => [$ephemeralKeyServiceCtor, 'generate'],
    'verify_ephemeral_key' => [$ephemeralKeyServiceCtor, 'verify'],
    // check_pwg_token() intentionally NOT mapped: CsrfService::check()
    // deliberately returns bool|null instead of acting on failure itself
    // (see that class's own docblock) -- L2bExtendedDomain may not depend
    // upward on L3Presentation's HtmlService, which bad_request()/
    // access_denied() need. check_pwg_token() is a permanent free-function
    // facade, same structural shape as fatal_error() (P23 batch 8c finding
    // 8 case 3) -- already fully delegated (its own body is a pure
    // CsrfService::check() call plus the failure-handling glue), so "real
    // logic lives in a real class" is already satisfied; only the thin
    // facade survives, deliberately.
];
