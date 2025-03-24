<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\SrcImage;

/**
 * Management of elements set. Elements can belong to a category or to the
 * user caddie.
 */

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

functions_plugins::trigger_notify('loc_begin_element_set_unit');

// +-----------------------------------------------------------------------+
// |                        unit mode form submission                      |
// +-----------------------------------------------------------------------+

if (isset($_POST['submit'])) {
    functions::check_pwg_token();
    functions::check_input_parameter('element_ids', $_POST, false, '/^\d+(,\d+)*$/');
    $collection = explode(',', $_POST['element_ids']);

    $datas = [];

    $imploded_collection = implode(', ', $collection);
    $query = <<<SQL
        SELECT id, date_creation
        FROM images
        WHERE id IN ({$imploded_collection});
        SQL;
    $result = functions_mysqli::pwg_query($query);

    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
        $data = [];

        $data['id'] = $row['id'];
        $data['name'] = $_POST['name-' . $row['id']];
        $data['author'] = $_POST['author-' . $row['id']];
        $data['level'] = $_POST['level-' . $row['id']];

        if ($conf['allow_html_descriptions']) {
            $data['comment'] = $_POST['description-' . $row['id']];
        } else {
            $data['comment'] = strip_tags($_POST['description-' . $row['id']]);
        }

        if (! empty($_POST['date_creation-' . $row['id']])) {
            $data['date_creation'] = $_POST['date_creation-' . $row['id']];
        } else {
            $data['date_creation'] = null;
        }

        $datas[] = $data;

        // tags management
        $tag_ids = [];

        if (! empty($_POST['tags-' . $row['id']])) {
            $tag_ids = functions_admin::get_tag_ids($_POST['tags-' . $row['id']]);
        }

        functions_admin::set_tags($tag_ids, $row['id']);
    }

    functions_mysqli::mass_updates(
        'images',
        [
            'primary' => ['id'],
            'update' => ['name', 'author', 'level', 'comment', 'date_creation'],
        ],
        $datas
    );

    $page['infos'][] = functions::l10n('Photo information updated');
    functions_admin::invalidate_user_cache();
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
    [
        'batch_manager_unit' => 'batch_manager_unit.tpl',
    ]
);

$base_url = './admin.php';

$template->assign(
    [
        'U_ELEMENTS_PAGE' => $base_url . functions_url::get_query_string_diff(['display', 'start']),
        'F_ACTION' => $base_url . functions_url::get_query_string_diff([]),
        'level_options' => functions::get_privacy_level_options(),
        'ADMIN_PAGE_TITLE' => functions::l10n('Batch Manager'),
        'PWG_TOKEN' => functions::get_pwg_token(),
    ]
);

// +-----------------------------------------------------------------------+
// |                        global mode thumbnails                         |
// +-----------------------------------------------------------------------+

// how many items to display on this page
if (! empty($_GET['display'])) {
    $page['nb_images'] = intval($_GET['display']);
} elseif (in_array($conf['batch_manager_images_per_page_unit'], [5, 10, 50])) {
    $page['nb_images'] = $conf['batch_manager_images_per_page_unit'];
} else {
    $page['nb_images'] = 5;
}

if (count($page['cat_elements_id']) > 0) {
    $nav_bar = functions::create_navigation_bar(
        $base_url . functions_url::get_query_string_diff(['start']),
        count($page['cat_elements_id']),
        $page['start'],
        $page['nb_images']
    );
    $template->assign([
        'navbar' => $nav_bar,
    ]);

    $element_ids = [];

    $is_category = false;

    if (isset($_SESSION['bulk_manager_filter']['category']) &&
        ! isset($_SESSION['bulk_manager_filter']['category_recursive'])
    ) {
        $is_category = true;
    }

    if (isset($_SESSION['bulk_manager_filter']['prefilter']) &&
        $_SESSION['bulk_manager_filter']['prefilter'] == 'duplicates'
    ) {
        $conf['order_by'] = ' ORDER BY file, id';
    }

    $query = <<<SQL
        SELECT *
        FROM images

        SQL;

    if ($is_category) {
        $category_info = functions_category::get_cat_info($_SESSION['bulk_manager_filter']['category']);

        $conf['order_by'] = $conf['order_by_inside_category'];

        if (! empty($category_info['image_order'])) {
            $conf['order_by'] = ' ORDER BY ' . $category_info['image_order'];
        }

        $query .= <<<SQL
            JOIN image_category ON id = image_id

            SQL;
    }

    $ids = implode(', ', $page['cat_elements_id']);
    $query .= <<<SQL
        WHERE id IN ({$ids})

        SQL;

    if ($is_category) {
        $query .= <<<SQL
            AND category_id = {$_SESSION['bulk_manager_filter']['category']}

            SQL;
    }

    $query .= <<<SQL
        {$conf['order_by']}
        LIMIT {$page['nb_images']} OFFSET {$page['start']};
        SQL;
    $result = functions_mysqli::pwg_query($query);

    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
        $element_ids[] = $row['id'];

        $src_image = new SrcImage($row);

        $query = <<<SQL
            SELECT id, name
            FROM image_tag AS it
            JOIN tags AS t ON t.id = it.tag_id
            WHERE image_id = {$row['id']};
            SQL;
        $tag_selection = functions_admin::get_taglist($query);

        $legend = functions_html::render_element_name($row);

        if ($legend != functions::get_name_from_file($row['file'])) {
            $legend .= ' (' . $row['file'] . ')';
        }

        $extTab = explode('.', $row['path']);

        $template->append(
            'elements',
            array_merge(
                $row,
                [
                    'ID' => $row['id'],
                    'TN_SRC' => DerivativeImage::url(derivative_std_params::IMG_THUMB, $src_image),
                    'FILE_SRC' => DerivativeImage::url(derivative_std_params::IMG_LARGE, $src_image),
                    'LEGEND' => $legend,
                    'U_EDIT' => functions_url::get_root_url() . 'admin.php?page=photo-' . $row['id'],
                    'NAME' => htmlspecialchars(isset($row['name']) ? $row['name'] : ''),
                    'AUTHOR' => htmlspecialchars(isset($row['author']) ? $row['author'] : ''),
                    'LEVEL' => ! empty($row['level']) ? $row['level'] : '0',
                    'DESCRIPTION' => htmlspecialchars(isset($row['comment']) ? $row['comment'] : ''),
                    'DATE_CREATION' => $row['date_creation'],
                    'TAGS' => $tag_selection,
                    'is_svg' => (strtoupper(end($extTab)) == 'SVG'),
                ]
            )
        );
    }

    $template->assign([
        'ELEMENT_IDS' => implode(',', $element_ids),
        'CACHE_KEYS' => functions_admin::get_admin_client_cache_keys(['tags']),
    ]);
}

functions_plugins::trigger_notify('loc_end_element_set_unit');

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_unit');
