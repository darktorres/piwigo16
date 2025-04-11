<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SrcImage;

/**
 * Change rank of images inside a category
 */

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

if (! isset($_GET['cat_id']) ||
    ! is_numeric($_GET['cat_id'])
) {
    trigger_error('missing cat_id param', E_USER_ERROR);
}

$page['category_id'] = $_GET['cat_id'];

// +-----------------------------------------------------------------------+
// |                       global mode form submission                     |
// +-----------------------------------------------------------------------+

$image_order_choices = ['default', 'sort_rank', 'user_define'];
$image_order_choice = 'default';

if (isset($_POST['submit'])) {
    if (isset($_POST['rank_of_image'])) {
        asort($_POST['rank_of_image'], SORT_NUMERIC);

        functions_admin::save_images_order(
            $page['category_id'],
            array_keys($_POST['rank_of_image'])
        );

        $page['infos'][] = functions::l10n('Images manual order was saved');
    }

    if (! empty($_POST['image_order_choice']) &&
        in_array($_POST['image_order_choice'], $image_order_choices)
    ) {
        $image_order_choice = $_POST['image_order_choice'];
    }

    $image_order = null;

    if ($image_order_choice == 'user_define') {
        for ($i = 0; $i < 3; $i++) {
            if (! empty($_POST['image_order'][$i])) {
                if (! empty($image_order)) {
                    $image_order .= ',';
                }

                $image_order .= $_POST['image_order'][$i];
            }
        }
    } elseif ($image_order_choice == 'sort_rank') {
        $image_order = 'sort_rank ASC';
    }

    $image_order_value = isset($image_order) ? "'{$image_order}'" : 'NULL';
    $query = <<<SQL
        UPDATE categories
        SET image_order = {$image_order_value}
        WHERE id = {$page['category_id']};
        SQL;
    $conf->sql_backend::pwg_query($query);

    if (isset($_POST['image_order_subcats'])) {
        $cat_info = functions_category::get_cat_info($page['category_id']);

        $image_order_value = isset($image_order) ? "'{$image_order}'" : 'NULL';
        $query = <<<SQL
            UPDATE categories
            SET image_order = {$image_order_value}
            WHERE uppercats LIKE '{$cat_info['uppercats']},%';
            SQL;
        $conf->sql_backend::pwg_query($query);
    }

    $page['infos'][] = functions::l10n('Your configuration settings are saved');
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+
$template->set_filenames(
    [
        'element_set_ranks' => 'element_set_ranks.tpl',
    ]
);

$base_url = functions_url::get_root_url() . 'admin.php';

$query = <<<SQL
    SELECT *
    FROM categories
    WHERE id = {$page['category_id']};
    SQL;
$category = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));

if ($category['image_order'] == 'rank ASC' ||
    $category['image_order'] == 'sort_rank ASC'
) {
    $image_order_choice = 'sort_rank';
} elseif ($category['image_order'] != '') {
    $image_order_choice = 'user_define';
}

// Navigation path
$navigation = functions_html::get_cat_display_name_cache(
    $category['uppercats'],
    functions_url::get_root_url() . 'admin.php?page=album-'
);

$template->assign(
    [
        'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
        'F_ACTION' => $base_url . functions_url::get_query_string_diff(),
    ]
);

// +-----------------------------------------------------------------------+
// |                              thumbnails                               |
// +-----------------------------------------------------------------------+

$query = <<<SQL
    SELECT id, file, path, representative_ext, width, height, rotation, name, sort_rank
    FROM images
    JOIN image_category ON image_id = id
    WHERE category_id = {$page['category_id']}
    ORDER BY sort_rank;
    SQL;
$result = $conf->sql_backend::pwg_query($query);

if ($conf->sql_backend::pwg_db_num_rows($result) > 0) {
    // template thumbnail initialization
    $current_rank = 1;
    $derivativeParams = ImageStdParams::get_by_type(derivative_std_params::IMG_SQUARE);

    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
        $derivative = new DerivativeImage($derivativeParams, new SrcImage($row));

        if (! empty($row['name'])) {
            $thumbnail_name = $row['name'];
        } else {
            $file_wo_ext = functions::get_filename_wo_extension($row['file']);
            $thumbnail_name = str_replace('_', ' ', $file_wo_ext);
        }

        $current_rank++;
        $template->append(
            'thumbnails',
            [
                'ID' => $row['id'],
                'NAME' => $thumbnail_name,
                'TN_SRC' => $derivative->get_url(),
                'RANK' => $current_rank * 10,
                'SIZE' => $derivative->get_size(),
            ]
        );
    }
}

// image order management
$sort_fields = [
    '' => '',
    'file ASC' => functions::l10n('File name, A &rarr; Z'),
    'file DESC' => functions::l10n('File name, Z &rarr; A'),
    'name ASC' => functions::l10n('Photo title, A &rarr; Z'),
    'name DESC' => functions::l10n('Photo title, Z &rarr; A'),
    'date_creation DESC' => functions::l10n('Date created, new &rarr; old'),
    'date_creation ASC' => functions::l10n('Date created, old &rarr; new'),
    'date_available DESC' => functions::l10n('Date posted, new &rarr; old'),
    'date_available ASC' => functions::l10n('Date posted, old &rarr; new'),
    'rating_score DESC' => functions::l10n('Rating score, high &rarr; low'),
    'rating_score ASC' => functions::l10n('Rating score, low &rarr; high'),
    'hit DESC' => functions::l10n('Visits, high &rarr; low'),
    'hit ASC' => functions::l10n('Visits, low &rarr; high'),
    'id ASC' => functions::l10n('Numeric identifier, 1 &rarr; 9'),
    'id DESC' => functions::l10n('Numeric identifier, 9 &rarr; 1'),
    'rank ASC' => functions::l10n('Manual sort order'),
];

$template->assign('image_order_options', $sort_fields);

$image_order = explode(',', $category['image_order'] ?? '');

for ($i = 0; $i < 3; $i++) { // 3 fields
    if (isset($image_order[$i])) {
        $template->append('image_order', $image_order[$i]);
    } else {
        $template->append('image_order', '');
    }
}

$template->assign('image_order_choice', $image_order_choice);

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'element_set_ranks');
