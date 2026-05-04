<?php

declare(strict_types=1);

global $persistent_cache;

use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This file is included by the main page to show thumbnails for the default
 * case
 *
 */

$template = TemplateRegistry::current();
$page = &$GLOBALS['page'];
if (!is_array($page)) {
    $page = [];
}
$user = CurrentUser::get()->rawAttributes;

$pictures = [];

$pageItems = is_array($page['items'] ?? null) ? $page['items'] : [];
$pageStart = is_numeric($page['start'] ?? null) ? (int) $page['start'] : 0;
$pageNbImagePage = is_numeric($page['nb_image_page'] ?? null) ? (int) $page['nb_image_page'] : null;
$selection = array_values(array_filter(
    trigger_change(
        'loc_index_thumbnails_selection',
        array_slice($pageItems, $pageStart, $pageNbImagePage)
    ),
    fn ($v) => is_int($v) || is_string($v)
));

if (count($selection) > 0) {
    $rank_of = array_flip($selection);

    foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)
        ->findByIds(array_map('intval', $selection)) as $row) {
        $row['rank'] = $rank_of[ (int)$row['id'] ];
        $pictures[] = $row;
    }

    usort($pictures, rank_compare(...));
    unset($rank_of);
}

if (count($pictures) > 0) {
    // define category slideshow url
    $row = reset($pictures);
    $page['cat_slideshow_url'] =
      add_url_params(
          duplicate_picture_url(
              [
          'image_id' => $row['id'],
          'image_file' => $row['file'],
        ],
              ['start']
          ),
          ['slideshow' =>
          ($_GET['slideshow'] ?? '')]
      );

    if (\Piwigo\Config\Config::activateComments() and $user['show_nb_comments']) {
        $query = '
SELECT image_id, COUNT(*) AS nb_comments
  FROM '.COMMENTS_TABLE.'
  WHERE validated = \'true\'
    AND image_id IN ('.implode(',', array_map(strval(...), $selection)).')
  GROUP BY image_id
;';
        $nb_comments_of = query2array($query, 'image_id', 'nb_comments');
    }
}

// template thumbnail initialization
$template->set_filenames([ 'index_thumbnails' => 'thumbnails.tpl',]);

trigger_notify('loc_begin_index_thumbnails', $pictures);
$tpl_thumbnails_var = [];

foreach ($pictures as $row) {
    // link on picture.php page
    $url = duplicate_picture_url(
        [
            'image_id' => $row['id'],
            'image_file' => $row['file'],
          ],
        ['start']
    );

    if (isset($nb_comments_of)) {
        $row['NB_COMMENTS'] = $row['nb_comments'] = (int) ($nb_comments_of[$row['id']] ?? 0);
    }

    $name = render_element_name($row);
    $desc = render_element_description($row, 'main_page_element_description');

    $tpl_var = array_merge($row, [
      'TN_ALT' => htmlspecialchars(strip_tags($name)),
      'TN_TITLE' => get_thumbnail_title($row, $name, $desc),
      'URL' => $url,
      'DESCRIPTION' => $desc,
      'src_image' => new SrcImage($row),
      'path_ext' => strtolower(get_extension(is_string($row['path'] ?? null) ? $row['path'] : '')),
      'file_ext' => strtolower(get_extension(is_string($row['file'] ?? null) ? $row['file'] : '')),
      ]);

    if (\Piwigo\Config\Config::indexNewIcon()) {
        $tpl_var['icon_ts'] = get_icon(is_string($row['date_available'] ?? null) ? $row['date_available'] : null);
    }

    if ($user['show_nb_hits']) {
        $tpl_var['NB_HITS'] = $row['hit'];
    }

    switch ($page['section']) {
        case 'best_rated':
            {
                $name = '('.$row['rating_score'].') '.$name;
                break;
            }
        case 'most_visited':
            {
                if (!$user['show_nb_hits']) {
                    $name = '('.$row['hit'].') '.$name;
                }
                break;
            }
    }
    $tpl_var['NAME'] = $name;
    $tpl_thumbnails_var[] = $tpl_var;
}

$template->assign([
  'derivative_params' => trigger_change('get_index_derivative_params', ImageStdParams::get_by_type(pwg_get_session_var('index_deriv', IMG_THUMB))),
  'maxRequests' => \Piwigo\Config\Config::maxRequests(),
  'SHOW_THUMBNAIL_CAPTION' => \Piwigo\Config\Config::showThumbnailCaption(),
    ]);
$tpl_thumbnails_var = trigger_change('loc_end_index_thumbnails', $tpl_thumbnails_var, $pictures);
$template->assign('thumbnails', $tpl_thumbnails_var);

$template->assign_var_from_handle('THUMBNAILS', 'index_thumbnails');
unset($pictures, $selection, $tpl_thumbnails_var);
$template->clear_assign('thumbnails');
pwg_debug('end include/category_default.inc.php');
