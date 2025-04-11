<?php

declare(strict_types=1);

/**********************************
 * REQUIRED PATH TO THE TPL FILE */

use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;

$TOUR_PATH = PHPWG_PLUGINS_PATH . 'TakeATour/tours/manage_albums/tour.tpl';

/*********************************/

if (defined('IN_ADMIN') &&
    IN_ADMIN
) {
    functions_plugins::add_event_handler('loc_end_cat_modify', TAT_FC_23(...));
}

function TAT_FC_23(): void
{
    global $template;
    $template->set_prefilter('album_properties', TAT_FC_23_prefilter(...));
}

function TAT_FC_23_prefilter(
    array|string $content
): array|string {
    $search = "<strong>{'Lock'|translate}</strong>";
    $replacement = '<span id="TAT_FC_23"><strong>{\'Lock\'|translate}</strong></span>';
    return str_replace($search, $replacement, $content);
}

/**********************
 *    Preparse part   *
 **********************/
//picture id
if (isset($_GET['page']) &&
    preg_match('/^photo-(\d+)(?:-(.*))?$/', $_GET['page'], $matches)
) {
    $_GET['image_id'] = $matches[1];
}

functions::check_input_parameter('image_id', $_GET, false, PATTERN_ID);

if (isset($_GET['image_id']) &&
    functions_session::pwg_get_session_var('TAT_image_id') == null
) {
    $template->assign('TAT_image_id', $_GET['image_id']);
    functions_session::pwg_set_session_var('TAT_image_id', $_GET['image_id']);
} elseif (is_numeric(functions_session::pwg_get_session_var('TAT_image_id'))) {
    $template->assign('TAT_image_id', functions_session::pwg_get_session_var('TAT_image_id'));
} else {
    $random_function = $conf->sql_backend::DB_RANDOM_FUNCTION;
    $query = <<<SQL
        SELECT id
        FROM images
        ORDER BY {$random_function}
        LIMIT 1;
        SQL;
    $row = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));
    $template->assign('TAT_image_id', $row['id']);
}

//album id
if (isset($_GET['page']) &&
    preg_match('/^album-(\d+)(?:-(.*))?$/', $_GET['page'], $matches)
) {
    $_GET['cat_id'] = $matches[1];
}

functions::check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

if (isset($_GET['cat_id']) &&
    functions_session::pwg_get_session_var('TAT_cat_id') == null
) {
    $template->assign('TAT_cat_id', $_GET['cat_id']);
    functions_session::pwg_set_session_var('TAT_cat_id', $_GET['cat_id']);
} elseif (is_numeric(functions_session::pwg_get_session_var('TAT_cat_id'))) {
    $template->assign('TAT_cat_id', functions_session::pwg_get_session_var('TAT_cat_id'));
} else {
    $random_function = $conf->sql_backend::DB_RANDOM_FUNCTION;
    $query = <<<SQL
        SELECT id
        FROM categories
        ORDER BY {$random_function}
        LIMIT 1;
        SQL;
    $row = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));
    $template->assign('TAT_cat_id', $row['id']);
}

global $conf;

if (isset($conf->enable_synchronization)) {
    $template->assign('TAT_FTP', $conf->enable_synchronization);
}
