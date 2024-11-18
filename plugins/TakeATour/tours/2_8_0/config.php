<?php

/**********************************
 * REQUIRED PATH TO THE TPL FILE */

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\dblayer\functions_mysqli;

$TOUR_PATH = PHPWG_PLUGINS_PATH . 'TakeATour/tours/2_8_0/tour.tpl';

/*********************************/

$template->assign('TAT_HAS_ORPHANS', count(functions_admin::get_orphans()) > 0 ? true : false);

// category id for notification new features
if (! isset($_SESSION['TAT_cat_id'])) {
    $query = <<<SQL
        SELECT MAX(id) AS cat_id
        FROM categories;
        SQL;
    $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));
    $_SESSION['TAT_cat_id'] = $row['cat_id'];
}

$template->assign('TAT_cat_id', $_SESSION['TAT_cat_id']);
