<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// --------------------------------------------------------------------- include
use Piwigo\inc\functions;
use Piwigo\inc\functions_cookie;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\menubar;

const PHPWG_ROOT_PATH = './';
require_once __DIR__.'/inc/common.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_FREE);

// but if the user is already identified, we redirect to gallery home
// instead of displaying the log in form
if (! functions_user::is_a_guest()) {
    functions::redirect(functions_url::get_gallery_home_url());
}

functions_plugins::trigger_notify('loc_begin_identification');

// -------------------------------------------------------------- identification

// security (level 1): the redirect must occur within Piwigo, so the
// redirect param must start with the relative home url
$post_redirect = input_string('redirect', null, $_POST);

if ($post_redirect !== null) {
    $_POST['redirect_decoded'] = urldecode($post_redirect);
}

functions::check_input_parameter('redirect_decoded', $_POST, false, '{^'.preg_quote(functions_cookie::cookie_path()).'}');

$redirect_to = '';
$get_redirect = input_string('redirect', null, $_GET);

if (! empty($get_redirect)) {
    $redirect_to = urldecode($get_redirect);

    if ($conf->guest_access &&
        input_string('hide_redirect_error', null, $_GET) === null
    ) {
        $page['errors'][] = functions::l10n('You are not authorized to access the requested page');
    }
}

if (input_string('login', null, $_POST) !== null) {
    if (! isset($_COOKIE[session_name()])) {
        $page['errors'][] = functions::l10n('Cookies are blocked or not supported by your browser. You must enable cookies to connect.');
    } else {
        $post_username = input_string('username', null, $_POST);

        if ($conf->insensitive_case_logon) {
            $post_username = functions_user::search_case_username($post_username);
        }

        $redirect_to = $post_redirect !== null ? urldecode($post_redirect) : '';
        $post_remember_me = input_string('remember_me', null, $_POST);
        $remember_me = $post_remember_me !== null && $post_remember_me == 1;

        if (functions_user::try_log_user($post_username, input_string('password', null, $_POST), $remember_me)) {
            // security (level 2): force redirect within Piwigo. We redirect to
            // absolute root url, including http(s)://, without the cookie path,
            // concatenated with $_POST['redirect'] param.
            //
            // example:
            // {redirect (raw) = /piwigo/git/admin.php}
            // {get_absolute_root_url = http://localhost/piwigo/git/}
            // {cookie_path = /piwigo/git/}
            // {host = http://localhost}
            // {redirect (final) = http://localhost/piwigo/git/admin.php}
            $root_url = functions_url::get_absolute_root_url();

            functions::redirect(
                empty($redirect_to)
                ? functions_url::get_gallery_home_url()
                : substr($root_url, 0, strlen($root_url) - strlen(functions_cookie::cookie_path())).$redirect_to
            );
        } else {
            $page['errors'][] = functions::l10n('Invalid username or password!');
        }
    }
}

// ----------------------------------------------------- template initialization
//
// Start output of page
//
$title = functions::l10n('Identification');
$page['body_id'] = 'theIdentificationPage';

$template->set_filenames([
    'identification' => 'identification.tpl',
]);

$template->assign(
    [
        'U_REDIRECT' => $redirect_to,

        'F_LOGIN_ACTION' => functions_url::get_root_url().'identification.php',
        'authorize_remembering' => $conf->authorize_remembering,
    ]
);

if (! $conf->gallery_locked &&
    $conf->allow_user_registration
) {
    $template->assign('U_REGISTER', functions_url::get_root_url().'register.php');
}

if (! $conf->gallery_locked) {
    $template->assign('U_LOST_PASSWORD', functions_url::get_root_url().'password.php');
}

// include menubar
$themeconf = $template->get_template_vars('themeconf');

if (! $conf->gallery_locked &&
   (! isset($themeconf['hide_menu_on']) || ! in_array('theIdentificationPage', $themeconf['hide_menu_on']))
) {
    menubar::initialize_menu();
}

// ----------------------------------------------------------- html code display
require __DIR__.'/inc/page_header.php';
functions_plugins::trigger_notify('loc_end_identification');
functions_html::flush_page_messages();
$template->pparse('identification');
require __DIR__.'/inc/page_tail.php';
