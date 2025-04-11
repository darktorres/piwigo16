<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions_url;

$my_base_url = functions_url::get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('albums');
$tabsheet->select($page['tab']);
$tabsheet->assign();

$query = <<<SQL
    SELECT COUNT(*) AS "COUNT(*)"
    FROM categories;
    SQL;

[$nb_cats] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));
$template->assign(
    [
        'nb_cats' => $nb_cats,
    ]
);
