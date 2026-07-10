<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $user
 */
global $conf, $user;
// Set by include/ws_init.inc.php, included conditionally below.
global $service;

// by default we start with guest
$user['id'] = $conf['guest_id'];

if (isset($_COOKIE[session_name()])) {
    if (isset($_GET['act']) and $_GET['act'] == 'logout') { // logout
        logout_user();
        // get_gallery_home_url() is declared to return `mixed` (include/functions_url.inc.php);
        // every real branch of its body actually returns a string, so this narrows locally
        // rather than widening redirect()'s $url parameter.
        $gallery_home_url = get_gallery_home_url();
        redirect(is_string($gallery_home_url) ? $gallery_home_url : '/');
    } elseif (! empty($_SESSION['pwg_uid'])) {
        $user['id'] = $_SESSION['pwg_uid'];
    }
}

// Now check the auto-login
if ($user['id'] == $conf['guest_id']) {
    auto_login();
}

// using Apache authentication override the above user search
if ($conf['apache_authentication']) {
    $remote_user = null;
    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $server_key) {
        if (isset($_SERVER[$server_key]) and is_string($_SERVER[$server_key])) {
            $remote_user = $_SERVER[$server_key];
            break;
        }
    }

    if (isset($remote_user)) {
        if (! ($user['id'] = get_userid($remote_user))) {
            $user['id'] = register_user($remote_user, '', '', false);
        }
    }
}

// automatic login by authentication key
if (isset($_GET['auth'])) {
    auth_key_login($_GET['auth']);
}

// HTTP_AUTHORIZATION api_key
if (
    defined('IN_WS')
    and isset($_SERVER['HTTP_X_PIWIGO_API'])
    and is_string($_SERVER['HTTP_X_PIWIGO_API'])
    and ! empty($_SERVER['HTTP_X_PIWIGO_API'])
    and isset($_REQUEST['method'])
    and is_string($_REQUEST['method'])
) {
    $auth_header = pwg_db_real_escape_string($_SERVER['HTTP_X_PIWIGO_API']) ?? null;

    if ($auth_header) {
        $authenticate = auth_key_login($auth_header, true);
        if (! $authenticate) {
            include_once PHPWG_ROOT_PATH . 'include/ws_init.inc.php';
            /** @var \PwgServer $service */
            $service->sendResponse(new PwgError(401, 'Invalid api_key'));
            exit;
        }
        define('PWG_API_KEY_REQUEST', true);

        // set pwg_token for api_key request
        $_POST['pwg_token'] = $_GET['pwg_token'] = get_pwg_token();

        // logger
        /** @var \Logger $logger */
        global $logger;
        $logger->info('[api_key][pkid=' . explode(':', (string) $auth_header)[0] . '][method=' . $_REQUEST['method'] . ']');
    }
}

if (
    defined('IN_WS')
    and isset($_REQUEST['method'])
    and $_REQUEST['method'] == 'pwg.images.uploadAsync'
    and is_string($_POST['username'] ?? null)
    and is_string($_POST['password'] ?? null)
) {
    include_once PHPWG_ROOT_PATH . 'include/ws_init.inc.php';
    include_once PHPWG_ROOT_PATH . 'include/ws_functions/pwg.php';

    $credentials = [
        'username' => $_POST['username'],
        'password' => $_POST['password'],
    ];

    /** @var \PwgServer $service */
    $login = ws_session_login($credentials, $service);

    if ($login !== true) {
        $service->sendResponse($login);
        exit();
    }
    $_SESSION['connected_with'] = 'pwg.images.uploadAsync';
}

$user_use_cache = true;
if (defined('IN_ADMIN') and IN_ADMIN) {
    $user_use_cache = false;
} elseif (
    isset($_REQUEST['method'])
    and isset($_SERVER['HTTP_REFERER'])
    and is_string($_SERVER['HTTP_REFERER'])
    and preg_match('/\/admin\.php\?page=/', $_SERVER['HTTP_REFERER'])
) {
    $user_use_cache = false;
}
/** @var array<string, mixed> $page */
$page['user_use_cache'] = $user_use_cache;

// $user['id'] is always numeric here (either $conf['guest_id'], a
// $_SESSION['pwg_uid'] set by a prior login, or the int|false result of
// get_userid()/register_user() coerced above); the is_numeric() check is a
// defensive narrowing to satisfy build_user()'s int $user_id, matching the
// guest_id fallback already used earlier in this file.
$user_id = $user['id'];
$guest_id = $conf['guest_id'];
$guest_id_int = is_numeric($guest_id) ? (int) $guest_id : 2;
$user_id_int = is_numeric($user_id) ? (int) $user_id : $guest_id_int;

$user = build_user($user_id_int, $user_use_cache);

if ($conf['browser_language'] and (is_a_guest() or is_generic()) and $language = get_browser_language()) {
    $user['language'] = $language;
}
trigger_notify('user_init', $user);
