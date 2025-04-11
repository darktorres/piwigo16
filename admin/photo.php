<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Basic checks                                                          |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
functions::check_input_parameter('image_id', $_GET, false, PATTERN_ID);

$admin_photo_base_url = functions_url::get_root_url() . 'admin.php?page=photo-' . $_GET['image_id'];

// retrieving direct information about picture
$page['image'] = functions_admin::get_image_infos($_GET['image_id'], true);

if (isset($_GET['cat_id'])) {
    $query = <<<SQL
        SELECT *
        FROM categories
        WHERE id = {$_GET['cat_id']};
        SQL;
    $category = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));
}

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'properties';

if (isset($_GET['tab'])) {
    $page['tab'] = $_GET['tab'];
}

$tabsheet = new tabsheet();
$tabsheet->set_id('photo');
$tabsheet->select($page['tab']);
$tabsheet->assign();

$template->assign(
    [
        'ADMIN_PAGE_TITLE' => functions::l10n('Edit photo') . ' <span class="image-id">#' . $_GET['image_id'] . '</span>',
    ]
);

// +-----------------------------------------------------------------------+
// | Load the tab                                                          |
// +-----------------------------------------------------------------------+

if ($page['tab'] == 'properties') {
    require __DIR__ . '/../admin/picture_modify.php';
} elseif ($page['tab'] == 'coi') {
    require __DIR__ . '/../admin/picture_coi.php';
} elseif ($page['tab'] == 'formats' &&
          $conf->enable_formats
) {
    require __DIR__ . '/../admin/picture_formats.php';
} else {
    require __DIR__ . '/../admin/photo_' . $page['tab'] . '.php';
}
