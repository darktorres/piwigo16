<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions_url;

$my_base_url = functions_url::get_root_url().'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('albums');
$tabsheet->select($page['tab']);
$tabsheet->assign();

$query = '
SELECT COUNT(*)
  FROM '.CATEGORIES_TABLE.'
;';

list($nb_cats) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));
$template->assign(
  array(
    'nb_cats' => $nb_cats,
  )
);

?>