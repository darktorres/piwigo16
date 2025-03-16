<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\PwgError;

// by default we start with guest
$user['id'] = $conf['guest_id'];

if (isset($_COOKIE[session_name()])) {
    if (isset($_GET['act']) and $_GET['act'] == 'logout') { // logout
        functions_user::logout_user();
        functions::redirect(functions_url::get_gallery_home_url());
    } elseif (! empty($_SESSION['pwg_uid'])) {
        $user['id'] = $_SESSION['pwg_uid'];
    }
}

// Now check the auto-login
if ($user['id'] == $conf['guest_id']) {
    functions_user::auto_login();
}

// using Apache authentication override the above user search
if ($conf['apache_authentication']) {
    $remote_user = null;
    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $server_key) {
        if (isset($_SERVER[$server_key])) {
            $remote_user = $_SERVER[$server_key];
            break;
        }
    }

    if (isset($remote_user)) {
        if (! ($user['id'] = functions_user::get_userid($remote_user))) {
            $user['id'] = functions_user::register_user($remote_user, '', '', false);
        }
    }
}

// automatic login by authentication key
if (isset($_GET['auth'])) {
    functions_user::auth_key_login($_GET['auth']);
}

if (
    defined('IN_WS')
    and isset($_REQUEST['method'])
    and $_REQUEST['method'] == 'pwg.images.uploadAsync'
    and isset($_POST['username'])
    and isset($_POST['password'])
) {
    if (! functions_user::try_log_user($_POST['username'], $_POST['password'], false)) {
        include_once(PHPWG_ROOT_PATH . 'inc/ws_init.php');
        $service->sendResponse(new PwgError(999, 'Invalid username/password'));
        exit();
    }
}

$user = functions_user::build_user(
    $user['id'],
    (defined('IN_ADMIN') and IN_ADMIN) ? false : true // use cache ?
);
if ($conf['browser_language'] and (functions_user::is_a_guest() or functions_user::is_generic()) and $language = functions_user::get_browser_language()) {
    $user['language'] = $language;
}
functions_plugins::trigger_notify('user_init', $user);
