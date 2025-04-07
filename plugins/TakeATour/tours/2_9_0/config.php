<?php

declare(strict_types=1);

/**********************************
 * REQUIRED PATH TO THE TPL FILE */

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\dblayer\functions_mysqli;

$TOUR_PATH = PHPWG_PLUGINS_PATH . 'TakeATour/tours/2_9_0/tour.tpl';

/*********************************/

$template->assign('TAT_HAS_ORPHANS', count(functions_admin::get_orphans()) > 0 ? true : false);

// category id for example of delete options. To illustrate the new
// features, we need an album with photos.
if (! isset($_SESSION['TAT_tour29_delete_cat_id'])) {
    $query = <<<SQL
        SELECT category_id
        FROM image_category
        LIMIT 1;
        SQL;
    $rows = functions_mysqli::query2array($query);

    if (count($rows) == 0) {
        $_SESSION['TAT_tour29_delete_cat_id'] = -1;
    } else {
        $_SESSION['TAT_tour29_delete_cat_id'] = $rows[0]['category_id'];
    }
}

if ($_SESSION['TAT_tour29_delete_cat_id'] > 0) {
    $template->assign('TAT_tour29_delete_cat_id', $_SESSION['TAT_tour29_delete_cat_id']);
}

if (! isset($_SESSION['TAT_tour29_image_id'])) {
    $query = <<<SQL
        SELECT id
        FROM images
        ORDER BY id DESC
        LIMIT 1;
        SQL;
    $images = functions_mysqli::query2array($query);

    if (count($images) == 0) {
        $_SESSION['TAT_tour29_image_id'] = -1;
    } else {
        $_SESSION['TAT_tour29_image_id'] = $images[0]['id'];
    }
}

if ($_SESSION['TAT_tour29_image_id'] > 0) {
    $template->assign('TAT_tour29_image_id', $_SESSION['TAT_tour29_image_id']);
}

$template->assign('TAT_tour29_history_url', 'admin.php?page=stats&year=' . date('Y') . '&month=' . date('n'));

$query = <<<SQL
    SELECT COUNT(*)
    FROM tags;
    SQL;
[$counter] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

if ($counter > 0) {
    $template->assign('TAT_tour29_has_tags', true);
}
