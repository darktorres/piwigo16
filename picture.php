<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
include PHPWG_ROOT_PATH . 'include/section_init.inc.php';
include_once PHPWG_ROOT_PATH . 'include/functions_picture.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var \Template $template
 * @var array<string, mixed> $user
 */
global $conf, $page, $template, $user;

save_edit_context();

// Check Access and exit when user status is not ok
check_status(ACCESS_GUEST);

// $page['category'] (see include/section_init.inc.php / get_cat_info())
// is set at most once, before this script runs, and is never reassigned
// below -- snapshot it locally once so every isset($page['category'])
// check below can be replaced with a real is_array() narrowing.
$page_category = (isset($page['category']) and is_array($page['category'])) ? $page['category'] : null;

// access authorization check
if ($page_category !== null) {
    $category_id = $page_category['id'] ?? null;
    check_restrictions(is_numeric($category_id) ? (int) $category_id : 0);
}

// $page['items'] (see include/section_init.inc.php) is always a list of
// image ids (int or numeric string); mutated in place below (best_rated
// fallback) so it is kept as a local variable synced back into $page.
$page_items = $page['items'];
$items = [];
if (is_array($page_items)) {
    foreach ($page_items as $page_item) {
        if (is_int($page_item) || is_string($page_item)) {
            $items[] = $page_item;
        }
    }
}
$page['items'] = $items;
$rank_of = array_flip($items);
$page['rank_of'] = $rank_of;

$image_id = $page['image_id'];
$image_id = is_numeric($image_id) ? (int) $image_id : 0;

// $user['id'] (the logged in / guest user id) is never reassigned below.
$user_id = $user['id'];
$user_id = is_numeric($user_id) ? (int) $user_id : 0;

// if this image_id doesn't correspond to this category, an error message is
// displayed, and execution is stopped
if (! isset($rank_of[$image_id])) {
    $query = '
SELECT id, file, level
  FROM ' . IMAGES_TABLE . '
  WHERE ';
    if ($image_id > 0) {
        $query .= 'id = ' . $image_id;
    } else {// url given by file name
        assert(! empty($page['image_file']));
        $page_image_file = $page['image_file'];
        $query .= 'file LIKE \'' .
          str_replace(['_', '%'], ['/_', '/%'], is_string($page_image_file) ? $page_image_file : '') .
          '.%\' ESCAPE \'/\' LIMIT 1';
    }
    if (! ($row = pwg_db_fetch_assoc(pwg_query($query)))) {// element does not exist
        page_not_found(
            'The requested image does not exist',
            duplicate_index_url()
        );
    }
    $row_level = $row['level'];
    $user_level = $user['level'];
    if ((is_numeric($row_level) ? (int) $row_level : 0) > (is_numeric($user_level) ? (int) $user_level : 0)) {
        access_denied();
    }

    $row_id = $row['id'];
    assert(is_string($row_id)); // images.id is the NOT NULL primary key
    $image_id = (int) $row_id;
    $page['image_id'] = $image_id;
    $page['image_file'] = $row['file'];
    if (! isset($rank_of[$image_id])) {// the image can still be non accessible (filter/cat perm) and/or not in the set
        /** @var array<string, mixed> $filter */
        global $filter;
        $visible_images = $filter['visible_images'] ?? null;
        if (! empty($visible_images) and
          ! in_array($image_id, explode(',', is_scalar($visible_images) ? (string) $visible_images : ''))) {
            page_not_found(
                'The requested image is filtered',
                duplicate_index_url()
            );
        }
        $page_section = $page['section'];
        if ($page_section == 'categories' and $page_category === null) {// flat view - all items
            access_denied();
        } else {// try to see if we can access it differently
            $query = '
SELECT id
  FROM ' . IMAGES_TABLE . ' INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id=image_id
  WHERE id=' . $image_id
              . get_sql_condition_FandF(
                  [
                      'forbidden_categories' => 'category_id',
                  ],
                  ' AND'
              ) . '
  LIMIT 1';
            if (pwg_db_num_rows(pwg_query($query)) == 0) {
                access_denied();
            } else {
                if ($page_section == 'best_rated') {
                    $rank_of[$image_id] = count($items);
                    $page['rank_of'] = $rank_of;
                    $items[] = $image_id;
                    $page['items'] = $items;
                } else {
                    $url = make_picture_url(
                        [
                            'image_id' => $image_id,
                            'image_file' => $page['image_file'],
                            'section' => 'categories',
                            'flat' => true,
                        ]
                    );
                    set_status_header($page_section == 'recent_pics' ? 301 : 302);
                    redirect_http($url);
                }
            }
        }
    }
}

// There is cookie, so we must handle it at the beginning
if (isset($_GET['metadata'])) {
    if (pwg_get_session_var('show_metadata') == null) {
        pwg_set_session_var('show_metadata', 1);
    } else {
        pwg_unset_session_var('show_metadata');
    }
}

// add default event handler for rendering element content
add_event_handler('render_element_content', 'default_picture_content');
// add default event handler for rendering element description
add_event_handler('render_element_description', 'pwg_nl2br');

trigger_notify('loc_begin_picture');

// this is the default handler that generates the display for the element
/**
 * $element_info is always $picture['current'] (see the trigger_change() call
 * near the end of this file) — 'derivatives' is populated by
 * DerivativeImage::get_all() (include/derivative.inc.php), keyed by the
 * IMG_* type strings.
 *
 * @param array{
 *     file: string,
 *     derivatives: array<string, DerivativeImage>,
 *     element_url?: string,
 *     ...
 * } $element_info
 */
function default_picture_content(string $content, array $element_info): string
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (! empty($content)) {// someone hooked us - so we skip;
        return $content;
    }

    if (isset($_COOKIE['picture_deriv'])) {
        if (is_string($_COOKIE['picture_deriv'])
            and array_key_exists($_COOKIE['picture_deriv'], ImageStdParams::get_defined_type_map())) {
            pwg_set_session_var('picture_deriv', $_COOKIE['picture_deriv']);
        }
        setcookie('picture_deriv', '', [
            'expires' => 0,
            'path' => cookie_path(),
        ]);
    }
    $deriv_type = pwg_get_session_var('picture_deriv', $conf['derivative_default_size']);
    if (! is_string($deriv_type)) {
        $deriv_type = IMG_MEDIUM;
    }
    $selected_derivative = $element_info['derivatives'][$deriv_type];

    $unique_derivatives = [];
    $show_original = isset($element_info['element_url']);
    $added = [];
    foreach ($element_info['derivatives'] as $type => $derivative) {
        if ($type == IMG_SQUARE || $type == IMG_THUMB) {
            continue;
        }
        if (! array_key_exists($type, ImageStdParams::get_defined_type_map())) {
            continue;
        }
        $url = $derivative->get_url();
        if (isset($added[$url])) {
            continue;
        }
        $added[$url] = 1;
        $show_original &= ! ($derivative->same_as_source());

        // in case we do not display the sizes icon, we only add the selected size to unique_derivatives
        if ($conf['picture_sizes_icon'] or $type == $deriv_type) {
            $unique_derivatives[$type] = $derivative;
        }
    }

    /**
     * @var array<string, mixed> $page
     * @var \Template $template
     */
    global $page, $template;

    if ($show_original and isset($element_info['element_url'])) {
        $template->assign('U_ORIGINAL', $element_info['element_url']);
    }

    $template->append('current', [
        'selected_derivative' => $selected_derivative,
        'unique_derivatives' => $unique_derivatives,
    ], true);

    $template->set_filenames(
        [
            'default_content' => 'picture_content.tpl',
        ]
    );

    $template->assign(
        [
            'ALT_IMG' => $element_info['file'],
            'COOKIE_PATH' => cookie_path(),
        ]
    );
    return $template->parse('default_content', true);
}

// +-----------------------------------------------------------------------+
// |                            initialization                             |
// +-----------------------------------------------------------------------+

// caching first_rank, last_rank, current_rank in the displayed
// section. This should also help in readability.
$first_rank = 0;
$last_rank = count($items) - 1;
// the isset($rank_of[$image_id]) checks above (falling through to
// page_not_found()/access_denied() otherwise) already guarantee this key
// exists by this point.
$current_rank = $rank_of[$image_id];
$page['first_rank'] = $first_rank;
$page['last_rank'] = $last_rank;
$page['current_rank'] = $current_rank;

// caching current item : readability purpose
$page['current_item'] = $image_id;

$previous_item = null;
$first_item = null;
if ($current_rank != $first_rank) {
    // caching first & previous item : readability purpose
    $previous_item = $items[$current_rank - 1];
    $first_item = $items[$first_rank];
    $page['previous_item'] = $previous_item;
    $page['first_item'] = $first_item;
}

$next_item = null;
$last_item = null;
if ($current_rank != $last_rank) {
    // caching next & last item : readability purpose
    $next_item = $items[$current_rank + 1];
    $last_item = $items[$last_rank];
    $page['next_item'] = $next_item;
    $page['last_item'] = $last_item;
}

$nb_image_page = $page['nb_image_page'];
$nb_image_page = is_numeric($nb_image_page) ? (int) $nb_image_page : 0;
$url_up = duplicate_index_url(
    [
        'start' => floor($current_rank / $nb_image_page)
          * $nb_image_page,
    ],
    [
        'start',
    ]
);

$url_self = duplicate_picture_url();

// +-----------------------------------------------------------------------+
// |                                actions                                |
// +-----------------------------------------------------------------------+

/**
 * Actions are favorite adding, user comment deletion, setting the picture
 * as representative of the current category...
 *
 * Actions finish by a redirection
 */
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'add_to_favorites':

            $query = '
INSERT INTO ' . FAVORITES_TABLE . '
  (image_id,user_id)
  VALUES
  (' . $image_id . ',' . $user_id . ')
;';
            pwg_query($query);

            redirect($url_self);

            // no break
        case 'remove_from_favorites':

            $query = '
DELETE FROM ' . FAVORITES_TABLE . '
  WHERE user_id = ' . $user_id . '
    AND image_id = ' . $image_id . '
;';
            pwg_query($query);

            if ($page['section'] == 'favorites') {
                redirect($url_up);
            } else {
                redirect($url_self);
            }

            // no break
        case 'set_as_representative':

            if (is_admin() and $page_category !== null) {
                $representative_category_id = $page_category['id'] ?? null;
                $representative_category_id = is_numeric($representative_category_id) ? (int) $representative_category_id : 0;
                $query = '
UPDATE ' . CATEGORIES_TABLE . '
  SET representative_picture_id = ' . $image_id . '
  WHERE id = ' . $representative_category_id . '
;';
                pwg_query($query);
                pwg_activity('album', $representative_category_id, 'edit', [
                    'action' => $_GET['action'],
                    'image_id' => $image_id,
                ]);

                include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
                invalidate_user_cache();
            }

            redirect($url_self);

            // no break
        case 'add_to_caddie':

            fill_caddie([$image_id]);
            redirect($url_self);

            // no break
        case 'rate':

            include_once PHPWG_ROOT_PATH . 'include/functions_rate.inc.php';
            $rate = $_POST['rate'] ?? null;
            if (! is_int($rate) and ! is_string($rate)) {
                $rate = null;
            }
            rate_picture($image_id, $rate);
            redirect($url_self);

            // no break
        case 'edit_comment':

            include_once PHPWG_ROOT_PATH . 'include/functions_comment.inc.php';
            check_input_parameter('comment_to_edit', $_GET, false, PATTERN_ID);
            // check_input_parameter() validated this against PATTERN_ID
            // (/^\d+$/) above -- it would have called fatal_error() otherwise.
            assert(is_numeric($_GET['comment_to_edit']));
            // false is unreachable here: $die_on_error defaults to true,
            // and get_comment_author_id() calls fatal_error() (never) in
            // that case instead of returning
            $author_id = get_comment_author_id((int) $_GET['comment_to_edit']);
            assert($author_id !== false);

            if (can_manage_comment('edit', $author_id)) {
                if (! empty($_POST['content'])) {
                    check_pwg_token();
                    $post_key = $_POST['key'] ?? null;
                    if (! is_string($post_key)) {
                        $post_key = '';
                    }
                    $comment_action = update_user_comment(
                        [
                            'comment_id' => $_GET['comment_to_edit'],
                            'image_id' => $page['image_id'],
                            'content' => $_POST['content'],
                            'website_url' => @$_POST['website_url'],
                        ],
                        $post_key
                    );

                    $perform_redirect = false;
                    switch ($comment_action) {
                        case 'moderate':
                            if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                                $_SESSION['page_infos'] = [];
                            }
                            $_SESSION['page_infos'][] = l10n('An administrator must authorize your comment before it is visible.');
                            // no break
                        case 'validate':
                            if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                                $_SESSION['page_infos'] = [];
                            }
                            $_SESSION['page_infos'][] = l10n('Your comment has been registered');
                            $perform_redirect = true;
                            break;
                        case 'reject':
                            if (! isset($_SESSION['page_errors']) or ! is_array($_SESSION['page_errors'])) {
                                $_SESSION['page_errors'] = [];
                            }
                            $_SESSION['page_errors'][] = l10n('Your comment has NOT been registered because it did not pass the validation rules');
                            break;
                        default:
                            trigger_error('Invalid comment action ' . $comment_action, E_USER_WARNING);
                    }

                    if ($perform_redirect) {
                        redirect($url_self);
                    }
                    unset($_POST['content']);
                }

                $edit_comment = $_GET['comment_to_edit'];
            }
            break;

        case 'delete_comment':

            check_pwg_token();

            include_once PHPWG_ROOT_PATH . 'include/functions_comment.inc.php';

            check_input_parameter('comment_to_delete', $_GET, false, PATTERN_ID);
            // check_input_parameter() validated this against PATTERN_ID
            // (/^\d+$/) above -- it would have called fatal_error() otherwise.
            assert(is_numeric($_GET['comment_to_delete']));

            // false is unreachable here: see the edit_comment case above
            $author_id = get_comment_author_id((int) $_GET['comment_to_delete']);
            assert($author_id !== false);

            if (can_manage_comment('delete', $author_id)) {
                delete_user_comment((int) $_GET['comment_to_delete']);
            }

            redirect($url_self);

            // no break
        case 'validate_comment':

            check_pwg_token();

            include_once PHPWG_ROOT_PATH . 'include/functions_comment.inc.php';

            check_input_parameter('comment_to_validate', $_GET, false, PATTERN_ID);
            // check_input_parameter() validated this against PATTERN_ID
            // (/^\d+$/) above -- it would have called fatal_error() otherwise.
            assert(is_numeric($_GET['comment_to_validate']));

            // false is unreachable here: see the edit_comment case above
            $author_id = get_comment_author_id((int) $_GET['comment_to_validate']);
            assert($author_id !== false);

            if (can_manage_comment('validate', $author_id)) {
                validate_user_comment((int) $_GET['comment_to_validate']);
            }

            redirect($url_self);

    }
}

// ---------- incrementation of the number of hits
$inc_hit_count = ! isset($_POST['content']);
// don't increment counter if in the Mozilla Firefox prefetch
if (isset($_SERVER['HTTP_X_MOZ']) and $_SERVER['HTTP_X_MOZ'] == 'prefetch') {
    $inc_hit_count = false;
} else {
    // don't increment counter if comming from the same picture (actions)
    if (pwg_get_session_var('referer_image_id', 0) == $image_id) {
        $inc_hit_count = false;
    }
    pwg_set_session_var('referer_image_id', $image_id);
}

// don't increment if adding a comment
if (trigger_change('allow_increment_element_hit_count', $inc_hit_count, $image_id)) {
    increase_image_visit_counter($image_id);
}

// ---------------------------------------------------------- related categories
$query = '
SELECT id,uppercats,commentable,visible,status,global_rank
  FROM ' . IMAGE_CATEGORY_TABLE . '
    INNER JOIN ' . CATEGORIES_TABLE . ' ON category_id = id
  WHERE image_id = ' . $image_id . '
' . get_sql_condition_FandF(
    [
        'forbidden_categories' => 'id',
        'visible_categories' => 'id',
    ],
    'AND'
) . '
;';
$related_categories = array_from_query($query);
// array_from_query() with no $fieldname argument delegates straight to
// query2array($query) -- its own @return docblock (array<int|string, mixed>)
// is looser than query2array()'s precise conditional return type.
/** @var list<array<string, string|null>> $related_categories */
usort($related_categories, global_rank_compare(...));
// -------------------------first, prev, current, next & last picture management
$picture = [];

$ids = [(string) $image_id];
if ($previous_item !== null) {
    $ids[] = (string) $previous_item;
    $ids[] = (string) $first_item;
}
if ($next_item !== null) {
    $ids[] = (string) $next_item;
    $ids[] = (string) $last_item;
}

$query = '
SELECT *
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', $ids) . ')
;';

$result = pwg_query($query);

while ($row = pwg_db_fetch_assoc($result)) {
    if ($previous_item !== null and $row['id'] == $previous_item) {
        $i = 'previous';
    } elseif ($next_item !== null and $row['id'] == $next_item) {
        $i = 'next';
    } elseif ($first_item !== null and $row['id'] == $first_item) {
        $i = 'first';
    } elseif ($last_item !== null and $row['id'] == $last_item) {
        $i = 'last';
    } else {
        $i = 'current';
    }

    $row['src_image'] = new SrcImage($row);
    $row['derivatives'] = DerivativeImage::get_all($row['src_image']);

    // Writing computed keys (src_image, derivatives, ...) back into $row
    // widens PHPStan's inferred value type for every key in this array (it
    // was array<string, string|null> from pwg_db_fetch_assoc()) to a shared
    // union across all of them -- narrow explicitly at each still-scalar
    // column read below instead of casting the widened union directly.
    $row_path = $row['path'];
    assert(is_string($row_path)); // images.path is NOT NULL
    $extTab = explode('.', $row_path);
    $row['path_ext'] = strtolower(get_extension($row_path));

    $row_file = $row['file'];
    assert(is_string($row_file)); // images.file is NOT NULL
    $row['file_ext'] = strtolower(get_extension($row_file));

    if ($i == 'current') {
        $row['element_path'] = get_element_path($row);

        $row_id = $row['id'];
        assert(is_string($row_id)); // images.id is the NOT NULL primary key

        if ($row['src_image']->is_original()) {// we have a photo
            if ($user['enabled_high'] == 'true') {
                $row['element_url'] = $row['src_image']->get_url();
                $row['download_url'] = get_action_url($row_id, 'e', true);
            }
        } else { // not a pic - need download link
            $row['element_url'] = get_element_url($row);
            $row['download_url'] = get_action_url($row_id, 'e', true);
        }
    }

    $row['url'] = duplicate_picture_url(
        [
            'image_id' => $row['id'],
            'image_file' => $row['file'],
        ],
        [
            'start',
        ]
    );

    $picture[$i] = $row;
    $picture[$i]['TITLE'] = render_element_name($row);
    $picture[$i]['TITLE_ESC'] = str_replace('"', '&quot;', $picture[$i]['TITLE']);

    if ($i == 'previous' and $previous_item == $first_item) {
        $picture['first'] = $picture[$i];
    }
    if ($i == 'next' and $next_item == $last_item) {
        // $picture[$i] (== $picture['next']) was set a few lines above,
        // this same iteration ($i doesn't change within one iteration)
        assert(isset($picture[$i]));
        $picture['last'] = $picture[$i];
    }
}

$slideshow_params = [];
$slideshow_url_params = [];

if (isset($_GET['slideshow'])) {
    $page['slideshow'] = true;
    $page['meta_robots'] = [
        'noindex' => 1,
        'nofollow' => 1,
    ];

    $get_slideshow = $_GET['slideshow'];
    $slideshow_params = decode_slideshow_params(is_string($get_slideshow) ? $get_slideshow : null);
    $slideshow_url_params['slideshow'] = encode_slideshow_params($slideshow_params);

    if ($slideshow_params['play']) {
        $id_pict_redirect = '';
        if ($next_item !== null) {
            $id_pict_redirect = 'next';
        } else {
            if ($slideshow_params['repeat'] and $first_item !== null) {
                $id_pict_redirect = 'first';
            }
        }

        if (! empty($id_pict_redirect) and isset($picture[$id_pict_redirect])) {
            // $refresh, $url_link and $title are required for creating
            // an automated refresh page in header.tpl
            $refresh = $slideshow_params['period'];
            $url_link = add_url_params(
                $picture[$id_pict_redirect]['url'],
                $slideshow_url_params
            );
        }
    }
} else {
    $page['slideshow'] = false;
}
if ($page['slideshow'] and $conf['light_slideshow']) {
    $template->set_filenames([
        'slideshow' => 'slideshow.tpl',
    ]);
} else {
    $template->set_filenames([
        'picture' => 'picture.tpl',
    ]);
}

// $page['image_id'] is always in $ids (see the query above) and always
// hits the while loop's final `else { $i = 'current'; }` branch
assert(isset($picture['current']));
$title = $picture['current']['TITLE'];
$title_nb = ($current_rank + 1) . '/' . count($items);

// metadata
$url_metadata = duplicate_picture_url();
$url_metadata = add_url_params($url_metadata, [
    'metadata' => null,
]);

// do we have a plugin that can show metadata for something else than images?
$metadata_showable = trigger_change(
    'get_element_metadata_available',
    (
        ($conf['show_exif'] or $conf['show_iptc'])
    and ! $picture['current']['src_image']->is_mimetype()
    ),
    $picture['current']
);

if (isset($_GET['metadata'])) {
    $page['meta_robots'] = [
        'noindex' => 1,
        'nofollow' => 1,
    ];
}

$page['body_id'] = 'thePicturePage';

// allow plugins to change what we computed before passing data to template
/**
 * trigger_change() (include/functions_plugins.inc.php) is only typed to
 * return mixed -- restate the shape plugins are expected to preserve: one
 * images-table row (pwg_db_fetch_assoc(), string|null columns) per
 * navigation slot, plus the computed fields set on $row above.
 *
 * @var array<string, array{
 *     id: string,
 *     file: string,
 *     path: string,
 *     comment: string|null,
 *     author: string|null,
 *     date_creation: string|null,
 *     date_available: string,
 *     width: string|null,
 *     height: string|null,
 *     hit: string,
 *     filesize: string|null,
 *     src_image: SrcImage,
 *     derivatives: array<string, DerivativeImage>,
 *     url: string,
 *     download_url?: string,
 *     TITLE: string,
 *     TITLE_ESC: string,
 *     ...
 * }> $picture
 */
$picture = trigger_change('picture_pictures_data', $picture);

// ------------------------------------------------------- navigation management
foreach (['first', 'previous', 'next', 'last', 'current'] as $which_image) {
    if (isset($picture[$which_image])) {
        $template->assign(
            $which_image,
            array_merge(
                $picture[$which_image],
                [
                    // Params slideshow was transmit to navigation buttons
                    'U_IMG' => add_url_params(
                        $picture[$which_image]['url'],
                        $slideshow_url_params
                    ),
                ]
            )
        );
    }
}
if ($conf['picture_download_icon'] and ! empty($picture['current']['download_url']) and $user['enabled_high'] == 'true') {
    $template->append('current', [
        'U_DOWNLOAD' => $picture['current']['download_url'],
    ], true);

    if ($conf['enable_formats']) {
        $query = '
SELECT *
  FROM ' . IMAGE_FORMAT_TABLE . '
  WHERE image_id = ' . $picture['current']['id'] . '
;';
        $formats = query2array($query);

        // let's add the original as a format among others. It will just have a
        // specific download URL
        array_unshift(
            $formats,
            [
                'download_url' => $picture['current']['download_url'],
                'ext' => get_extension($picture['current']['file']),
                'filesize' => $picture['current']['filesize'],
            ]
        );

        foreach ($formats as &$format) {
            // array_unshift() above prepends a differently-shaped literal
            // (only download_url/ext/filesize) onto the query2array() rows
            // (format_id/image_id/ext/filesize), so PHPStan can no longer
            // track a precise per-key type for $format -- narrow explicitly.
            if (! isset($format['download_url'])) {
                $format_id = $format['format_id'];
                $format['download_url'] = 'action.php?format=' . (is_scalar($format_id) ? $format_id : '') . '&amp;download';
            }

            $format_ext = $format['ext'];
            $format_ext = is_string($format_ext) ? $format_ext : '';
            $format['label'] = strtoupper($format_ext);
            $lang_key = 'format ' . strtoupper($format_ext);
            /** @var array<string, mixed> $lang */
            if (isset($lang[$lang_key])) {
                $format['label'] = $lang[$lang_key];
            }

            $format_filesize = $format['filesize'];
            $format_filesize = is_numeric($format_filesize) ? (float) $format_filesize : 0.0;
            $format['filesize'] = sprintf('%.1fMB', $format_filesize / 1024);
        }
        $template->append('current', [
            'formats' => $formats,
        ], true);
    }
}

if ($page['slideshow']) {
    $tpl_slideshow = [];

    // slideshow end
    $template->assign(
        [
            'U_SLIDESHOW_STOP' => $picture['current']['url'],
        ]
    );

    foreach (['repeat', 'play'] as $p) {
        $var_name =
          'U_'
          . ($slideshow_params[$p] ? 'STOP_' : 'START_')
          . strtoupper($p);

        $tpl_slideshow[$var_name] =
              add_url_params(
                  $picture['current']['url'],
                  [
                      'slideshow' => encode_slideshow_params(
                          array_merge(
                              $slideshow_params,
                              [
                                  $p => ! $slideshow_params[$p],
                              ]
                          )
                      ),
                  ]
              );
    }

    foreach (['dec', 'inc'] as $op) {
        // decode_slideshow_params()/correct_slideshow_params() only declare
        // @return array<string, mixed>; 'period' is always numeric in
        // practice (int default, or a preg-captured \d+ string).
        $current_period = $slideshow_params['period'];
        $current_period = is_numeric($current_period) ? (int) $current_period : 0;
        $slideshow_period_step = $conf['slideshow_period_step'];
        $slideshow_period_step = is_numeric($slideshow_period_step) ? (int) $slideshow_period_step : 0;
        $new_period = $current_period + ((($op == 'dec') ? -1 : 1) * $slideshow_period_step);
        $new_slideshow_params =
          correct_slideshow_params(
              array_merge(
                  $slideshow_params,
                  [
                      'period' => $new_period,
                  ]
              )
          );

        if ($new_slideshow_params['period'] === $new_period) {
            $var_name = 'U_' . strtoupper($op) . '_PERIOD';
            $tpl_slideshow[$var_name] =
                  add_url_params(
                      $picture['current']['url'],
                      [
                          'slideshow' => encode_slideshow_params($new_slideshow_params),
                      ]
                  );
        }
    }
    $template->assign('slideshow', $tpl_slideshow);
} elseif ($conf['picture_slideshow_icon']) {
    $template->assign(
        [
            'U_SLIDESHOW_START' => add_url_params(
                $picture['current']['url'],
                [
                    'slideshow' => '',
                ]
            ),
        ]
    );
}

$template->assign(
    [
        'SECTION_TITLE' => $page['section_title'],
        'PHOTO' => $title_nb,
        'IS_HOME' => ($page['section'] == 'categories' and $page_category === null),

        'LEVEL_SEPARATOR' => $conf['level_separator'],

        'U_UP' => $url_up,
        'DISPLAY_NAV_BUTTONS' => $conf['picture_navigation_icons'],
        'DISPLAY_NAV_THUMB' => $conf['picture_navigation_thumb'],
    ]
);

if ($conf['picture_metadata_icon']) {
    $template->assign('U_METADATA', $url_metadata);
}

// ------------------------------------------------------- upper menu management

// admin links
if (is_admin()) {
    if ($page_category !== null and $conf['picture_representative_icon']) {
        $template->assign(
            [
                'U_SET_AS_REPRESENTATIVE' => add_url_params(
                    $url_self,
                    [
                        'action' => 'set_as_representative',
                    ]
                ),
            ]
        );
    }

    if ($conf['picture_edit_icon']) {
        $template->assign('U_PHOTO_ADMIN', get_root_url() . 'admin.php?page=photo-' . $image_id);
    }

    if ($conf['picture_caddie_icon']) {
        $template->assign(
            'U_CADDIE',
            add_url_params($url_self, [
                'action' => 'add_to_caddie',
            ])
        );
    }

}

// favorite manipulation
if (! is_a_guest() and $conf['picture_favorite_icon']) {
    // verify if the picture is already in the favorite of the user
    $query = '
SELECT COUNT(*) AS nb_fav
  FROM ' . FAVORITES_TABLE . '
  WHERE image_id = ' . $image_id . '
    AND user_id = ' . $user_id . '
;';
    $row = pwg_db_fetch_assoc(pwg_query($query));
    if ($row === false || $row === null) {
        throw new Exception('picture.php: favorite-count aggregate query returned no row');
    }
    $is_favorite = $row['nb_fav'] != 0;

    $template->assign(
        'favorite',
        [
            'IS_FAVORITE' => $is_favorite,
            'U_FAVORITE' => add_url_params(
                $url_self,
                [
                    'action' => ! $is_favorite ? 'add_to_favorites' : 'remove_from_favorites',
                ]
            ),
        ]
    );
}

// --------------------------------------------------------- picture information
// $infos was previously used without initialization (relying on PHP's
// implicit array creation on first offset write) -- make it explicit.
$infos = [];

// legend
if (isset($picture['current']['comment'])
    and ! empty($picture['current']['comment'])) {
    $template->assign(
        'COMMENT_IMG',
        trigger_change(
            'render_element_description',
            $picture['current']['comment'],
            'picture_page_element_description'
        )
    );
}

// author
if (! empty($picture['current']['author'])) {
    $infos['INFO_AUTHOR'] = $picture['current']['author'];
}

// creation date
if (! empty($picture['current']['date_creation'])) {
    $date_creation = $picture['current']['date_creation'];
    $val = format_date($date_creation);
    $url = make_index_url(
        [
            'chronology_field' => 'created',
            'chronology_style' => 'monthly',
            'chronology_view' => 'list',
            'chronology_date' => explode('-', substr($date_creation, 0, 10)),
        ]
    );
    $infos['INFO_CREATION_DATE'] =
      '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';
}

// date of availability
$val = format_date($picture['current']['date_available']);
$url = make_index_url(
    [
        'chronology_field' => 'posted',
        'chronology_style' => 'monthly',
        'chronology_view' => 'list',
        'chronology_date' => explode(
            '-',
            substr($picture['current']['date_available'], 0, 10)
        ),
    ]
);
$infos['INFO_POSTED_DATE'] = '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';

// size in pixels
if ($picture['current']['src_image']->is_original() and isset($picture['current']['width'])) {
    $infos['INFO_DIMENSIONS'] =
      $picture['current']['width'] . '*' . $picture['current']['height'];
}

// filesize
if (! empty($picture['current']['filesize'])) {
    $infos['INFO_FILESIZE'] = l10n('%d Kb', $picture['current']['filesize']);
}

// number of visits
$infos['INFO_VISITS'] = $picture['current']['hit'];

// file
$infos['INFO_FILE'] = $picture['current']['file'];

$template->assign($infos);
$picture_informations = $conf['picture_informations'];
$template->assign('display_info', unserialize(is_string($picture_informations) ? $picture_informations : ''));

// related tags
$tags = get_common_tags([$image_id], -1);
if (count($tags)) {
    foreach ($tags as $tag) {
        $template->append(
            'related_tags',
            array_merge(
                $tag,
                [
                    'URL' => make_index_url(
                        [
                            'tags' => [$tag],
                        ]
                    ),
                    'U_TAG_IMAGE' => duplicate_picture_url(
                        [
                            'section' => 'tags',
                            'tags' => [$tag],
                        ]
                    ),
                ]
            )
        );
    }
}

// related categories
if (count($related_categories) == 1 and
    $page_category !== null and
    $related_categories[0]['id'] == ($page_category['id'] ?? null)) { // no need to go to db, we have all the info
    // Mirrors the narrowing in include/functions_html.inc.php
    // (get_cat_display_name_from_id()) for the same 'upper_names' shape.
    $upper_names = $page_category['upper_names'] ?? [];
    $upper_names = is_array($upper_names) ? $upper_names : [];
    /** @var array<int, array<string, mixed>> $upper_names */
    $template->append(
        'related_categories',
        get_cat_display_name($upper_names)
    );
} else { // use only 1 sql query to get names for all related categories
    $ids = [];
    foreach ($related_categories as $category) {// add all uppercats to $ids
        $ids = array_merge($ids, explode(',', (string) $category['uppercats']));
    }
    $ids = array_unique($ids);
    $query = '
SELECT id, name, permalink
  FROM ' . CATEGORIES_TABLE . '
  WHERE id IN (' . implode(',', $ids) . ')';
    // hash_from_query()'s own @return docblock (array<int|string, mixed>) is
    // looser than query2array()'s precise conditional return type, which it
    // delegates to with a non-null $keyname.
    /** @var array<int|string, array<string, string|null>> $cat_map */
    $cat_map = hash_from_query($query, 'id');
    foreach ($related_categories as $category) {
        $cats = [];
        foreach (explode(',', (string) $category['uppercats']) as $id) {
            $cats[] = $cat_map[$id];
        }
        $template->append('related_categories', get_cat_display_name($cats));
    }
}

if (in_array(strtolower(get_extension($picture['current']['file'])), ['pdf'])) {
    $pdf_viewer_filesize_threshold = $conf['pdf_viewer_filesize_threshold'];
    $pdf_viewer_filesize_threshold = is_numeric($pdf_viewer_filesize_threshold) ? (int) $pdf_viewer_filesize_threshold : 0;
    $template->assign(
        [
            'PDF_VIEWER_FILESIZE_THRESHOLD' => $pdf_viewer_filesize_threshold * 1024,
            'PDF_NB_PAGES' => count_pdf_pages($picture['current']['path']),
        ]
    );
}

// maybe someone wants a special display (call it before page_header so that
// they can add stylesheets)
$element_content = trigger_change(
    'render_element_content',
    '',
    $picture['current']
);
$template->assign('ELEMENT_CONTENT', $element_content);

$http_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$http_user_agent = is_string($http_user_agent) ? $http_user_agent : '';
if (isset($picture['next'])
    and $picture['next']['src_image']->is_original()
    and $template->get_template_vars('U_PREFETCH') == null
    and ! str_contains($http_user_agent, 'Chrome/')) {
    $prefetch_deriv_type = pwg_get_session_var('picture_deriv', $conf['derivative_default_size']);
    if (! is_string($prefetch_deriv_type)) {
        $prefetch_deriv_type = IMG_MEDIUM;
    }
    $template->assign(
        'U_PREFETCH',
        $picture['next']['derivatives'][$prefetch_deriv_type]->get_url()
    );
}

$template->assign(
    'U_CANONICAL',
    make_picture_url(
        [
            'image_id' => $picture['current']['id'],
            'image_file' => $picture['current']['file'],
        ]
    )
);

// +-----------------------------------------------------------------------+
// |                               sub pages                               |
// +-----------------------------------------------------------------------+

include PHPWG_ROOT_PATH . 'include/picture_rate.inc.php';
if ($conf['activate_comments']) {
    include PHPWG_ROOT_PATH . 'include/picture_comment.inc.php';
}
if ($metadata_showable and pwg_get_session_var('show_metadata') != null) {
    include PHPWG_ROOT_PATH . 'include/picture_metadata.inc.php';
}

// include menubar
$themeconf = $template->get_template_vars('themeconf');
$themeconf = is_array($themeconf) ? $themeconf : [];
if ($conf['picture_menu'] and (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('thePicturePage', $themeconf['hide_menu_on']))) {
    if (! isset($page['start'])) {
        $page['start'] = 0;
    }
    include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
}

include PHPWG_ROOT_PATH . 'include/page_header.php';
trigger_notify('loc_end_picture');
flush_page_messages();
if ($page['slideshow'] and $conf['light_slideshow']) {
    $template->pparse('slideshow');
} else {
    $template->parse_picture_buttons();
    $template->pparse('picture');
}
// ------------------------------------------------------------ log informations
$current_image_id = $picture['current']['id'];
pwg_log(is_numeric($current_image_id) ? (int) $current_image_id : null, 'picture');
include PHPWG_ROOT_PATH . 'include/page_tail.php';
