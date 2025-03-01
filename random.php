<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                          define and include                           |
// +-----------------------------------------------------------------------+

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

define('PHPWG_ROOT_PATH', './');
include_once(PHPWG_ROOT_PATH . 'inc/common.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_GUEST);

// +-----------------------------------------------------------------------+
// |                     generate random element list                      |
// +-----------------------------------------------------------------------+

$query = '
SELECT id
  FROM images
    INNER JOIN image_category AS ic ON id = ic.image_id
' . functions_user::get_sql_condition_FandF(
    [
        'forbidden_categories' => 'category_id',
        'visible_categories' => 'category_id',
        'visible_images' => 'id',
    ],
    'WHERE'
) . '
  ORDER BY ' . functions_mysqli::DB_RANDOM_FUNCTION . '()
  LIMIT ' . min(50, $conf['top_number'], $user['nb_image_page']) . '
;';

// +-----------------------------------------------------------------------+
// |                                redirect                               |
// +-----------------------------------------------------------------------+

functions::redirect(functions_url::make_index_url([
    'list' => functions::array_from_query($query, 'id'),
]));
