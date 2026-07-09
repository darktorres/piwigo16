<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Bootstrap globals, set by include/common.inc.php.
global $page, $template;

include_once PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php';

$my_base_url = get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('albums');
$tabsheet->select($page['tab']);
$tabsheet->assign();

$query = '
SELECT COUNT(*)
  FROM ' . CATEGORIES_TABLE . '
;';

$row = pwg_db_fetch_row(pwg_query($query));
assert($row !== null);
[$nb_cats] = $row;
$template->assign(
    [
        'nb_cats' => $nb_cats,
    ]
);
