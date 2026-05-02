<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;

use Piwigo\Admin\Tabsheet;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url().'admin.php?page=';

$tabsheet = new Tabsheet();
$tabsheet->set_id('albums');
$tabsheet->select($page['tab']);
$tabsheet->assign();

$query = '
SELECT COUNT(*)
  FROM '.CATEGORIES_TABLE.'
;';

[$nb_cats] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
$template->assign(
    [
    'nb_cats' => $nb_cats,
  ]
);
