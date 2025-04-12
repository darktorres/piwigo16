<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SrcImage;

/**
 * This file is included by the main page to show thumbnails for the default
 * case
 */

$pictures = [];

$selection = array_slice(
    $page['items'],
    (int) $page['start'],
    (int) $page['nb_image_page']
);

$selection = functions_plugins::trigger_change('loc_index_thumbnails_selection', $selection);

if (count($selection) > 0) {
    $rank_of = array_flip($selection);

    $implodedSelection = implode(', ', $selection);
    $query = <<<SQL
        SELECT *
        FROM images
        WHERE id IN ({$implodedSelection});
        SQL;
    $result = functions_mysqli::pwg_query($query);

    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
        $row['rank'] = $rank_of[$row['id']];
        $pictures[] = $row;
    }

    usort($pictures, functions_category::rank_compare(...));
    unset($rank_of);
}

if (count($pictures) > 0) {
    // define category slideshow url
    $row = reset($pictures);
    $page['cat_slideshow_url'] =
      functions_url::add_url_params(
          functions_url::duplicate_picture_url(
              [
                  'image_id' => $row['id'],
                  'image_file' => $row['file'],
              ],
              ['start']
          ),
          [
              'slideshow' =>
                      (isset($_GET['slideshow']) ? $_GET['slideshow']
                                                 : ''),
          ]
      );

    if ($conf->activate_comments &&
        $user['show_nb_comments']
    ) {
        $implodedSelection = implode(', ', $selection);
        $query = <<<SQL
            SELECT image_id, COUNT(*) AS nb_comments
            FROM comments
            WHERE validated = 'true'
                AND image_id IN ({$implodedSelection})
            GROUP BY image_id;
            SQL;
        $nb_comments_of = functions_mysqli::query2array($query, 'image_id', 'nb_comments');
    }
}

// template thumbnail initialization
$template->set_filenames([
    'index_thumbnails' => 'thumbnails.tpl',
]);

functions_plugins::trigger_notify('loc_begin_index_thumbnails', $pictures);
$tpl_thumbnails_var = [];

foreach ($pictures as $row) {
    // link on picture.php page
    $url = functions_url::duplicate_picture_url(
        [
            'image_id' => $row['id'],
            'image_file' => $row['file'],
        ],
        ['start']
    );

    if (isset($nb_comments_of)) {
        $row['NB_COMMENTS'] = $row['nb_comments'] = (int) $nb_comments_of[$row['id']];
    }

    $name = functions_html::render_element_name($row);
    $desc = functions_html::render_element_description($row, 'main_page_element_description');

    $tpl_var = array_merge($row, [
        'TN_ALT' => htmlspecialchars(strip_tags($name)),
        'TN_TITLE' => functions_html::get_thumbnail_title($row, $name, $desc),
        'URL' => $url,
        'DESCRIPTION' => $desc,
        'src_image' => new SrcImage($row),
        'path_ext' => strtolower(functions::get_extension($row['path'])),
        'file_ext' => strtolower(functions::get_extension($row['file'])),
    ]);

    if ($conf->index_new_icon) {
        $tpl_var['icon_ts'] = functions::get_icon($row['date_available']);
    }

    if ($user['show_nb_hits']) {
        $tpl_var['NB_HITS'] = $row['hit'];
    }

    switch ($page['section']) {
        case 'best_rated':
            $name = '(' . $row['rating_score'] . ') ' . $name;
            break;

        case 'most_visited':
            if (! $user['show_nb_hits']) {
                $name = '(' . $row['hit'] . ') ' . $name;
            }

            break;
    }

    $tpl_var['NAME'] = $name;
    $tpl_thumbnails_var[] = $tpl_var;
}

$template->assign([
    'derivative_params' => functions_plugins::trigger_change('get_index_derivative_params', ImageStdParams::get_by_type(functions_session::pwg_get_session_var('index_deriv', derivative_std_params::IMG_THUMB))),
    'maxRequests' => $conf->max_requests,
    'SHOW_THUMBNAIL_CAPTION' => $conf->show_thumbnail_caption,
]);
$tpl_thumbnails_var = functions_plugins::trigger_change('loc_end_index_thumbnails', $tpl_thumbnails_var, $pictures);
$template->assign('thumbnails', $tpl_thumbnails_var);

$template->assign_var_from_handle('THUMBNAILS', 'index_thumbnails');
unset($pictures, $selection, $tpl_thumbnails_var);
$template->clear_assign('thumbnails');
functions::pwg_debug('end inc/category_default.php');
