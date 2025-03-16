<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// customize appearance of the site for a user
// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\menubar;

if (! defined('PHPWG_ROOT_PATH')) {//direct script access
    define('PHPWG_ROOT_PATH', './');
    include_once(PHPWG_ROOT_PATH . 'inc/common.php');

    // +-----------------------------------------------------------------------+
    // | Check Access and exit when user status is not ok                      |
    // +-----------------------------------------------------------------------+
    functions_user::check_status(ACCESS_CLASSIC);

    if (! empty($_POST)) {
        functions::check_pwg_token();
    }

    $userdata = $user;

    functions_plugins::trigger_notify('loc_begin_profile');

    // Reset to default (Guest) custom settings
    if (isset($_POST['reset_to_default'])) {
        $fields = [
            'nb_image_page', 'expand',
            'show_nb_comments', 'show_nb_hits', 'recent_period', 'show_nb_hits',
        ];

        // Get the Guest custom settings
        $query = '
SELECT ' . implode(',', $fields) . '
  FROM ' . USER_INFOS_TABLE . '
  WHERE user_id = ' . $conf['default_user_id'] . '
;';
        $result = functions_mysqli::pwg_query($query);
        $default_user = functions_mysqli::pwg_db_fetch_assoc($result);
        $userdata = array_merge($userdata, $default_user);
    }

    functions::save_profile_from_post($userdata, $page['errors']);

    $title = functions::l10n('Your Gallery Customization');
    $page['body_id'] = 'theProfilePage';
    $template->set_filename('profile', 'profile.tpl');
    $template->set_filename('profile_content', 'profile_content.tpl');

    functions::load_profile_in_template(
        functions_url::get_root_url() . 'profile.php', // action
        functions_url::make_index_url(), // for redirect
        $userdata
    );
    $template->assign_var_from_handle('PROFILE_CONTENT', 'profile_content');

    // include menubar
    $themeconf = $template->get_template_vars('themeconf');
    if (! isset($themeconf['hide_menu_on']) or ! in_array('theProfilePage', $themeconf['hide_menu_on'])) {
        menubar::initialize_menu();
    }

    include(PHPWG_ROOT_PATH . 'inc/page_header.php');
    functions_plugins::trigger_notify('loc_end_profile');
    functions_html::flush_page_messages();
    $template->pparse('profile');
    include(PHPWG_ROOT_PATH . 'inc/page_tail.php');
}
