<?php

declare(strict_types=1);

/**********************************
 * REQUIRED PATH TO THE TPL FILE */

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;

$TOUR_PATH = PHPWG_PLUGINS_PATH . 'TakeATour/tours/edit_photos/tour.tpl';

/*********************************/

if (defined('IN_ADMIN') &&
    IN_ADMIN
) {
    functions_plugins::add_event_handler('loc_end_element_set_global', TAT_FC_14(...));
    functions_plugins::add_event_handler('loc_end_picture_modify', TAT_FC_16(...));
    functions_plugins::add_event_handler('loc_end_picture_modify', TAT_FC_17(...));
}

function TAT_FC_14(): void
{
    global $template;
    $template->set_prefilter('batch_manager_global', TAT_FC_14_prefilter(...));
}

function TAT_FC_14_prefilter(
    string $content
): array|string {
    $search = '<span class="wrap2';
    $replacement = '{counter print=false assign=TAT_FC_14}<span {if $TAT_FC_14==1}id="TAT_FC_14"{/if} class="wrap2';
    $content = str_replace($search, $replacement, $content);
    $search = 'target="_blank">{\'Edit\'';
    $replacement = ">{'Edit'";
    return str_replace($search, $replacement, $content);
}

function TAT_FC_16(): void
{
    global $template;
    $template->set_prefilter('picture_modify', TAT_FC_16_prefilter(...));
}

function TAT_FC_16_prefilter(
    string $content
): array|string {
    $search = "<strong>{'Linked albums'|translate}</strong>";
    $replacement = '<span id="TAT_FC_16"><strong>{\'Linked albums\'|translate}</strong></span>';
    return str_replace($search, $replacement, $content);
}

function TAT_FC_17(): void
{
    global $template;
    $template->set_prefilter('picture_modify', TAT_FC_17_prefilter(...));
}

function TAT_FC_17_prefilter(
    string $content
): array|string {
    $search = "<strong>{'Representation of albums'|translate}</strong>";
    $replacement = '<span id="TAT_FC_17"><strong>{\'Representation of albums\'|translate}</strong></span>';
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
    $query = <<<SQL
        SELECT id
        FROM images
        ORDER BY RAND()
        LIMIT 1;
        SQL;
    $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));
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
    $query = <<<SQL
        SELECT id
        FROM categories
        ORDER BY RAND()
        LIMIT 1;
        SQL;
    $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));
    $template->assign('TAT_cat_id', $row['id']);
}

global $conf;

if (isset($conf->enable_synchronization)) {
    $template->assign('TAT_FTP', $conf->enable_synchronization);
}
