<?php

declare(strict_types=1);

use Piwigo\Category\CategoryRepository;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Change rank of images inside a category
 *
 */

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

$sort_fields = [
  ''                    => '',
  'file ASC'            => l10n('File name, A &rarr; Z'),
  'file DESC'           => l10n('File name, Z &rarr; A'),
  'name ASC'            => l10n('Photo title, A &rarr; Z'),
  'name DESC'           => l10n('Photo title, Z &rarr; A'),
  'date_creation DESC'  => l10n('Date created, new &rarr; old'),
  'date_creation ASC'   => l10n('Date created, old &rarr; new'),
  'date_available DESC' => l10n('Date posted, new &rarr; old'),
  'date_available ASC'  => l10n('Date posted, old &rarr; new'),
  'rating_score DESC'   => l10n('Rating score, high &rarr; low'),
  'rating_score ASC'    => l10n('Rating score, low &rarr; high'),
  'hit DESC'            => l10n('Visits, high &rarr; low'),
  'hit ASC'             => l10n('Visits, low &rarr; high'),
  'id ASC'              => l10n('Numeric identifier, 1 &rarr; 9'),
  'id DESC'             => l10n('Numeric identifier, 9 &rarr; 1'),
  'rank ASC'            => l10n('Manual sort order'),
  ];

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

if (!isset($_GET['cat_id']) or !is_numeric($_GET['cat_id'])) {
    trigger_error('missing cat_id param', E_USER_ERROR);
}

$page['category_id'] = $_GET['cat_id'];

// +-----------------------------------------------------------------------+
// |                       global mode form submission                     |
// +-----------------------------------------------------------------------+

$image_order_choices = ['default', 'rank', 'user_define'];
$image_order_choice = 'default';

if (isset($_POST['submit'])) {
    if (isset($_POST['rank_of_image'])) {
        $rank_of_image_raw = is_array($_POST['rank_of_image']) ? $_POST['rank_of_image'] : [];
        $rank_of_image = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $rank_of_image_raw);
        asort($rank_of_image, SORT_NUMERIC);

        save_images_order(
            (int) $page['category_id'],
            array_keys($rank_of_image)
        );
    }

    if (!empty($_POST['image_order_choice'])
        && in_array($_POST['image_order_choice'], $image_order_choices)) {
        $image_order_choice = $_POST['image_order_choice'];
    }

    $message = l10n('Album updated successfully');

    $image_order = null;
    if ($image_order_choice == 'user_define') {
        $image_order_post = is_array($_POST['image_order'] ?? null) ? $_POST['image_order'] : [];
        for ($i = 0; $i < 3; $i++) {
            $image_order_val = is_string($image_order_post[$i] ?? null) ? $image_order_post[$i] : null;
            if (!empty($image_order_val) and in_array($image_order_val, array_keys($sort_fields))) {
                if (!empty($image_order)) {
                    $image_order .= ',';
                }
                $image_order .= $image_order_val;
            }
        }
    } elseif ($image_order_choice == 'rank') {
        $image_order = '`rank` ASC';

        $message = l10n('Images manual order was saved');
    }
    $category_id_int = (int) $page['category_id'];
    $catRepo = ServiceLocator::get(CategoryRepository::class);
    $catRepo->updateImageOrder($category_id_int, $image_order ?? null);

    if (isset($_POST['image_order_subcats'])) {
        $cat_info = get_cat_info((string) $category_id_int);
        $catRepo->updateImageOrderForSubcats(
            is_scalar($cat_info['uppercats'] ?? null) ? (string) $cat_info['uppercats'] : '',
            $image_order ?? null
        );
    }

    $template->assign(
        [
        'save_success' => $message,
    ]
    );
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+
$template->set_filenames(
    ['element_set_ranks' => 'element_set_ranks.tpl']
);

$base_url = \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlGenerator::class)->admin();

$category = ServiceLocator::get(CategoryRepository::class)
    ->findCategoryById((int) $page['category_id']);

if ($category !== null && ($category['image_order'] == 'rank ASC' or $category['image_order'] == '`rank` ASC')) {
    $image_order_choice = 'rank';
} elseif ($category !== null && $category['image_order'] != '') {
    $image_order_choice = 'user_define';
}

// Navigation path
$navigation = get_cat_display_name_cache(
    is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '',
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlGenerator::class)->admin() . '&page=album-'
);

$template->assign(
    [
    'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
    'F_ACTION' => $base_url.get_query_string_diff([]),
   ]
);

// +-----------------------------------------------------------------------+
// |                              thumbnails                               |
// +-----------------------------------------------------------------------+

$imgRows = ServiceLocator::get(ImageRepository::class)
    ->findByCategoryIdOrdered((int) $page['category_id']);
if (count($imgRows) > 0) {
    // template thumbnail initialization
    $current_rank = 1;
    $derivativeParams = ImageStdParams::get_by_type(IMG_SQUARE);
    foreach ($imgRows as $row) {
        $derivative = new DerivativeImage($derivativeParams, new SrcImage($row));

        if (!empty($row['name'])) {
            $thumbnail_name = $row['name'];
        } else {
            $file_wo_ext = get_filename_wo_extension(is_scalar($row['file']) ? (string)$row['file'] : '');
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
$template->assign('image_order_options', $sort_fields);

$image_order = explode(',', is_scalar($category['image_order'] ?? null) ? (string) $category['image_order'] : '');

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
