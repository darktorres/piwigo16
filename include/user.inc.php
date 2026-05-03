<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang, $service;

use Piwigo\Ws\PwgError;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// by default we start with guest
$user['id'] = \Piwigo\Config\Config::guestId();

if (isset($_COOKIE[session_name()])) {
    if (isset($_GET['act']) and $_GET['act'] == 'logout') { // logout
        logout_user();
        redirect(get_gallery_home_url());
    } elseif (!empty($_SESSION['pwg_uid'])) {
        $user['id'] = $_SESSION['pwg_uid'];
    }
}

// Now check the auto-login
if ($user['id'] == \Piwigo\Config\Config::guestId()) {
    auto_login();
}

// using Apache authentication override the above user search
if (\Piwigo\Config\Config::apacheAuthentication()) {
    $remote_user = null;
    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $server_key) {
        if (isset($_SERVER[$server_key])) {
            $remote_user = $_SERVER[$server_key];
            break;
        }
    }

    if (isset($remote_user)) {
        $remote_user_str = is_scalar($remote_user) ? (string) $remote_user : '';
        if (!($user['id'] = get_userid($remote_user_str))) {
            $user['id'] = register_user($remote_user_str, '', '', false);
        }
    }
}

// automatic login by authentication key
if (isset($_GET['auth'])) {
    auth_key_login(is_scalar($_GET['auth']) ? (string) $_GET['auth'] : '');
}

// HTTP_AUTHORIZATION api_key
if (
    defined('IN_WS')
    and isset($_SERVER['HTTP_X_PIWIGO_API'])
    and !empty($_SERVER['HTTP_X_PIWIGO_API'])
    and isset($_REQUEST['method'])
) {
    $auth_header = pwg_db_real_escape_string(is_scalar($_SERVER['HTTP_X_PIWIGO_API']) ? (string) $_SERVER['HTTP_X_PIWIGO_API'] : '');

    if ($auth_header) {
        $authenticate = auth_key_login($auth_header, true);
        if (!$authenticate) {
            include_once(PHPWG_ROOT_PATH.'include/ws_init.inc.php');
            $service->sendResponse(new PwgError(401, 'Invalid api_key'));
            exit;
        }
        define('PWG_API_KEY_REQUEST', true);

        // set pwg_token for api_key request
        $_POST['pwg_token'] = $_GET['pwg_token'] = get_pwg_token();

        // logger
        global $logger;
        $logger->info('[api_key][pkid='.explode(':', (string) $auth_header)[0].'][method='.(is_scalar($_REQUEST['method']) ? (string) $_REQUEST['method'] : '').']');
    }
}

if (
    defined('IN_WS')
    and isset($_REQUEST['method'])
    and 'pwg.images.uploadAsync' == $_REQUEST['method']
    and isset($_POST['username'])
    and isset($_POST['password'])
) {
    include_once(PHPWG_ROOT_PATH.'include/ws_init.inc.php');
    include_once(PHPWG_ROOT_PATH.'include/ws_functions/pwg.php');

    $credentials = [
      'username' => $_POST['username'],
      'password' => $_POST['password'],
    ];

    $login = ws_session_login($credentials, $service);

    if (true !== $login) {
        $service->sendResponse($login);
        exit();
    }
    $_SESSION['connected_with'] = 'pwg.images.uploadAsync';
}

$page['user_use_cache'] = true;
if (defined('IN_ADMIN') ? constant('IN_ADMIN') : false) {
    $page['user_use_cache'] = false;
} elseif (
    isset($_REQUEST['method'])
    and isset($_SERVER['HTTP_REFERER'])
    and preg_match('/\/admin\.php\?page=/', is_scalar($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '')
) {
    $page['user_use_cache'] = false;
}

$user = build_user(is_numeric($user['id']) ? (int) $user['id'] : 0, $page['user_use_cache']);

if (\Piwigo\Config\Config::browserLanguage() and (is_a_guest() or is_generic()) and $language = get_browser_language()) {
    $user['language'] = $language;
}
trigger_notify('user_init', $user);
