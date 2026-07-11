<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;

// Bootstrap globals, set by include/common.inc.php.
/** @var array<string, mixed> $page */
global $page;
/** @var \Template $template */
global $template;

$my_base_url = get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('albums');
$page_tab = $page['tab'] ?? null;
$tabsheet->select(is_string($page_tab) ? $page_tab : '');
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
