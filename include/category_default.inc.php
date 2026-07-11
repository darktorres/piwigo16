<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Db\Tables;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\Template;

/**
 * This file is included by the main page to show thumbnails for the default
 * case
 */

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $conf, $page, $template, $user;

$pictures = [];

// $page's own values are only known as mixed -- narrow each one for real
// before handing it to array_slice(), same pattern as
// include/ws_functions/pwg.images.php's get_quick_search_results() caller.
$page_items = $page['items'];
if (! is_array($page_items)) {
    $page_items = [];
}
$page_start = is_numeric($page['start'] ?? null) ? (int) $page['start'] : 0;
$page_nb_image_page = is_numeric($page['nb_image_page'] ?? null) ? (int) $page['nb_image_page'] : 0;

$selection = array_slice(
    $page_items,
    $page_start,
    $page_nb_image_page
);

$selection = trigger_change('loc_index_thumbnails_selection', $selection);
if (! is_array($selection)) {
    // A misbehaving plugin handler could return something else; count()
    // on a non-array/non-Countable is a fatal TypeError in PHP 8, so this
    // also guards against a real runtime crash, not just the static type.
    $selection = [];
}
/** @var list<int|string> $selection */
$selection = array_values(array_filter(
    $selection,
    static fn ($item): bool => is_int($item) || is_string($item)
));

if (count($selection) > 0) {
    $rank_of = array_flip($selection);

    $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $selection) . ')
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        // 'id' is the images table's NOT NULL primary key -- the '' fallback
        // only satisfies the array-key type, it never changes real behavior.
        $image_id = $row['id'] ?? '';
        $row['rank'] = $rank_of[$image_id] ?? 0;
        $pictures[] = $row;
    }

    usort($pictures, rank_compare(...));
    unset($rank_of);
}

// Only conditionally populated below (activate_comments + show_nb_comments
// both truthy AND at least one picture) -- declared up front (rather than
// relying on isset() to gate a maybe-undefined variable) so PHPStan can
// prove its real type -- null, or query2array()'s actual inferred return
// type -- at every later read.
$nb_comments_of = null;

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
          [
              'slideshow' => ($_GET['slideshow']
                                                 ?? ''),
          ]
      );

    if ((bool) $conf['activate_comments'] and (bool) $user['show_nb_comments']) {
        $query = '
SELECT image_id, COUNT(*) AS nb_comments
  FROM ' . Tables::comments() . '
  WHERE validated = \'true\'
    AND image_id IN (' . implode(',', $selection) . ')
  GROUP BY image_id
;';
        $nb_comments_of = query2array($query, 'image_id', 'nb_comments');
    }
}

// template thumbnail initialization
$template->set_filenames([
    'index_thumbnails' => 'thumbnails.tpl',
]);

trigger_notify('loc_begin_index_thumbnails', $pictures);
$tpl_thumbnails_var = [];

foreach ($pictures as $row) {
    // 'id' is the images table's NOT NULL primary key -- the '' fallback
    // only satisfies the array-key type, it never changes real behavior.
    $image_id = $row['id'] ?? '';

    // link on picture.php page
    $url = duplicate_picture_url(
        [
            'image_id' => $image_id,
            'image_file' => $row['file'],
        ],
        ['start']
    );

    if ($nb_comments_of !== null) {
        $row['NB_COMMENTS'] = $row['nb_comments'] = (int) @$nb_comments_of[$image_id];
    }

    $name = render_element_name($row);
    $desc = render_element_description($row, 'main_page_element_description');

    // 'path'/'file' are non-nullable text columns in practice, but $row is a
    // dynamically-fetched DB row (SELECT *), so PHPStan only knows them as
    // possibly-non-string -- narrow for real before get_extension().
    $row_path = is_string($row['path']) ? $row['path'] : null;
    $row_file = is_string($row['file']) ? $row['file'] : null;

    $tpl_var = array_merge($row, [
        'TN_ALT' => htmlspecialchars(strip_tags($name)),
        'TN_TITLE' => get_thumbnail_title($row, $name, $desc),
        'URL' => $url,
        'DESCRIPTION' => $desc,
        'src_image' => new SrcImage($row),
        'path_ext' => strtolower(get_extension($row_path)),
        'file_ext' => strtolower(get_extension($row_file)),
    ]);

    if ((bool) $conf['index_new_icon']) {
        // '' falls through get_icon()'s own empty($date) guard exactly like
        // a non-string/null column value would, so behavior is unchanged.
        $date_available = is_string($row['date_available']) ? $row['date_available'] : '';
        $tpl_var['icon_ts'] = get_icon($date_available);
    }

    if ((bool) $user['show_nb_hits']) {
        $tpl_var['NB_HITS'] = $row['hit'];
    }

    switch ($page['section']) {
        case 'best_rated':

            $rating_score = $row['rating_score'];
            $name = '(' . (is_string($rating_score) || is_int($rating_score) ? $rating_score : '') . ') ' . $name;
            break;

        case 'most_visited':

            if (! (bool) $user['show_nb_hits']) {
                $hit = $row['hit'];
                $name = '(' . (is_string($hit) || is_int($hit) ? $hit : '') . ') ' . $name;
            }
            break;

    }
    $tpl_var['NAME'] = $name;
    $tpl_thumbnails_var[] = $tpl_var;
}

$index_deriv = pwg_get_session_var('index_deriv', IMG_THUMB);
$index_deriv = is_string($index_deriv) ? $index_deriv : IMG_THUMB;

$template->assign([
    'derivative_params' => trigger_change('get_index_derivative_params', ImageStdParams::get_by_type($index_deriv)),
    'maxRequests' => $conf['max_requests'],
    'SHOW_THUMBNAIL_CAPTION' => $conf['show_thumbnail_caption'],
]);
$tpl_thumbnails_var = trigger_change('loc_end_index_thumbnails', $tpl_thumbnails_var, $pictures);
$template->assign('thumbnails', $tpl_thumbnails_var);

$template->assign_var_from_handle('THUMBNAILS', 'index_thumbnails');
unset($pictures, $selection, $tpl_thumbnails_var);
$template->clear_assign('thumbnails');
pwg_debug('end include/category_default.inc.php');
