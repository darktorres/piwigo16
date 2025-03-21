<?php

/*
Plugin Name: Take A Tour of Your Piwigo
Version: 14.5.0
Description: Visit your Piwigo to discover its features. This plugin has multiple thematic tours for beginners and advanced users.
Plugin URI: https://piwigo.org/ext/extension_view.php?eid=776
Author:Piwigo Team
Author URI: http://piwigo.org
Has Settings: true
*/

use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\plugins\TakeATour\functions_TakeATour;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

/** Tour sent via $_POST or $_GET**/
if (isset($_REQUEST['submitted_tour_path']) and
    defined('IN_ADMIN') and
    IN_ADMIN
) {
    functions::check_pwg_token();
    functions_session::pwg_set_session_var('tour_to_launch', $_REQUEST['submitted_tour_path']);
    global $TAT_restart;
    $TAT_restart = true;
} elseif (isset($_GET['tour_ended']) and
          defined('IN_ADMIN') and
          IN_ADMIN
) {
    functions_session::pwg_unset_session_var('tour_to_launch');
}

/** Setup the tour **/
/*
 * CHANGE FOR RELEASE
$version_=str_replace('.','_',PHPWG_VERSION);*/
$version_ = '2_8_0';
/***/
if (functions_session::pwg_get_session_var('tour_to_launch') != 'tours/' . $version_ and
    isset($_GET['page']) and
    $_GET['page'] == 'plugin-TakeATour'
) {
    functions_session::pwg_unset_session_var('tour_to_launch');
} elseif (functions_session::pwg_get_session_var('tour_to_launch')) {
    functions_plugins::add_event_handler('init', functions_TakeATour::TAT_tour_setup(...));
}

/** Add link in Help pages **/
functions_plugins::add_event_handler('loc_end_help', functions_TakeATour::TAT_help(...));

/** Add link in no_photo_yet **/
functions_plugins::add_event_handler('loc_end_no_photo_yet', functions_TakeATour::TAT_no_photo_yet(...));
