<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                          define and include                           |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $user
 */
global $conf, $user;

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_GUEST);

// +-----------------------------------------------------------------------+
// |                     generate random element list                      |
// +-----------------------------------------------------------------------+

// top_number/nb_image_page are DB-backed smallint columns (default 15);
// see include/config_default.inc.php and install/piwigo_structure-mysql.sql.
$top_number = is_numeric($conf['top_number'] ?? null) ? (int) $conf['top_number'] : 15;
$nb_image_page = is_numeric($user['nb_image_page'] ?? null) ? (int) $user['nb_image_page'] : 15;

$query = '
SELECT id
  FROM ' . IMAGES_TABLE . '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id = ic.image_id
' . get_sql_condition_FandF(
    [
        'forbidden_categories' => 'category_id',
        'visible_categories' => 'category_id',
        'visible_images' => 'id',
    ],
    'WHERE'
) . '
  ORDER BY ' . DB_RANDOM_FUNCTION . '()
  LIMIT ' . min(50, $top_number, $nb_image_page) . '
;';

// +-----------------------------------------------------------------------+
// |                                redirect                               |
// +-----------------------------------------------------------------------+

redirect(make_index_url([
    'list' => array_from_query($query, 'id'),
]));
