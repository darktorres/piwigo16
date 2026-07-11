<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\Template;

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+
define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
include_once PHPWG_ROOT_PATH . 'include/functions_comment.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var Template $template
 */
global $conf, $template;

if (! (bool) $conf['activate_comments']) {
    page_not_found(null);
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_GUEST);

$url_self = PHPWG_ROOT_PATH . 'comments.php'
  . get_query_string_diff(['delete', 'edit', 'validate', 'pwg_token']);

$sort_order = [
    'DESC' => l10n('descending'),
    'ASC' => l10n('ascending'),
];

// sort_by : database fields proposed for sorting comments list
$sort_by = [
    'date' => l10n('comment date'),
    'image_id' => l10n('photo'),
];

// items_number : list of number of items to display per page
$items_number = [5, 10, 20, 50, 'all'];

// $conf['comments_page_nb_comments'] is a plain int setting (see
// include/config_default.inc.php); $conf itself is only known as
// array<string, mixed>, so narrow with a fallback matching the shipped
// default rather than trust the shape blindly.
$comments_page_nb_comments = $conf['comments_page_nb_comments'];
$comments_page_nb_comments = is_numeric($comments_page_nb_comments) ? (int) $comments_page_nb_comments : 10;

// if the default value is not in the expected values, we add it in the $items_number array
if (! in_array($comments_page_nb_comments, $items_number)) {
    $items_number_new = [];

    $is_inserted = false;

    foreach ($items_number as $number) {
        if ($number > $comments_page_nb_comments or ($number == 'all' and ! $is_inserted)) {
            $items_number_new[] = $comments_page_nb_comments;
            $is_inserted = true;
        }

        $items_number_new[] = $number;
    }

    $items_number = $items_number_new;
}

// since when display comments ?
//
$since_options = [
    1 => [
        'label' => l10n('today'),
        'clause' => 'date > ' . pwg_db_get_recent_period_expression(1),
    ],
    2 => [
        'label' => l10n('last %d days', 7),
        'clause' => 'date > ' . pwg_db_get_recent_period_expression(7),
    ],
    3 => [
        'label' => l10n('last %d days', 30),
        'clause' => 'date > ' . pwg_db_get_recent_period_expression(30),
    ],
    4 => [
        'label' => l10n('the beginning'),
        'clause' => '1=1',
    ], // stupid but generic
];

trigger_notify('loc_begin_comments');

/** @var array<string, mixed> $page */
if (! empty($_GET['since']) and is_scalar($_GET['since'])) {
    $page['since'] = intval($_GET['since']);
} else {
    $page['since'] = 4;
}

// on which field sorting
//
$page['sort_by'] = 'date';
// if the form was submitted, it overloads default behaviour
if (isset($_GET['sort_by']) and is_string($_GET['sort_by']) and isset($sort_by[$_GET['sort_by']])) {
    $page['sort_by'] = $_GET['sort_by'];
}
// $page['sort_by'] is always a string by construction above (literal
// 'date' or a validated $_GET string).
$sort_by_value = $page['sort_by'];

// order to sort
//
$page['sort_order'] = 'DESC';
// if the form was submitted, it overloads default behaviour
if (isset($_GET['sort_order']) and is_string($_GET['sort_order']) and isset($sort_order[$_GET['sort_order']])) {
    $page['sort_order'] = $_GET['sort_order'];
}
// $page['sort_order'] is always a string by construction above (literal
// 'DESC' or a validated $_GET string).
$sort_order_value = $page['sort_order'];

// number of items to display
//
$page['items_number'] = $comments_page_nb_comments;
if (isset($_GET['items_number'])) {
    $page['items_number'] = $_GET['items_number'];
}
if (! is_numeric($page['items_number']) and $page['items_number'] != 'all') {
    $page['items_number'] = 10;
}
// after the checks above, items_number is guaranteed to be either numeric
// or the literal string 'all'.
$selected_items_number = is_numeric($page['items_number']) ? $page['items_number'] : 'all';

$page['where_clauses'] = [];

// which category to filter on ?
if (isset($_GET['cat']) and $_GET['cat'] != 0) {
    check_input_parameter('cat', $_GET, false, PATTERN_ID);

    $cat_id = $_GET['cat'];
    $cat_id = is_scalar($cat_id) ? (string) $cat_id : '0';

    $category_ids = get_subcat_ids([$cat_id]);
    if (empty($category_ids)) {
        $category_ids = [-1];
    }

    $page['where_clauses'][] =
      'category_id IN (' . implode(',', $category_ids) . ')';
}

// $conf['user_fields'] maps generic field names to actual DB column names
// (see include/config_default.inc.php, always a string=>string map);
// $conf itself is only known as array<string, mixed>, so narrow with
// fallbacks matching the shipped defaults rather than trust the shape
// blindly.
$user_fields = $conf['user_fields'] ?? null;
$user_fields = is_array($user_fields) ? $user_fields : [];
$username_field = is_string($user_fields['username'] ?? null) ? $user_fields['username'] : 'username';
$email_field = is_string($user_fields['email'] ?? null) ? $user_fields['email'] : 'mail_address';
$id_field = is_string($user_fields['id'] ?? null) ? $user_fields['id'] : 'id';

// search a particular author
if (! empty($_GET['author']) and is_scalar($_GET['author'])) {
    $author_search = (string) $_GET['author'];
    $page['where_clauses'][] =
      '(u.' . $username_field . ' = \'' . $author_search . '\' OR author = \'' . $author_search . '\')';
}

// search a specific comment (if you're coming directly from an admin
// notification email)
if (! empty($_GET['comment_id'])) {
    check_input_parameter('comment_id', $_GET, false, PATTERN_ID);
    // check_input_parameter() validated this against PATTERN_ID (/^\d+$/)
    // above -- it would have called fatal_error() otherwise.
    assert(is_numeric($_GET['comment_id']));
    $get_comment_id = (string) $_GET['comment_id'];

    // currently, the $_GET['comment_id'] is only used by admins from email
    // for management purpose (validate/delete)
    if (! is_admin()) {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $request_uri = is_string($request_uri) ? $request_uri : '';
        $login_url =
          get_root_url() . 'identification.php?redirect='
          . urlencode(urlencode($request_uri))
        ;
        redirect($login_url);
    }

    $page['where_clauses'][] = 'com.id = ' . $get_comment_id;
}

// search a substring among comments content
if (! empty($_GET['keyword']) and is_scalar($_GET['keyword'])) {
    $keyword_search = (string) $_GET['keyword'];
    $keywords = preg_split('/[\s,;]+/', $keyword_search);
    // the pattern above is a hardcoded, always-valid regex
    assert($keywords !== false);
    $page['where_clauses'][] =
      '(' .
      implode(
          ' AND ',
          array_map(
              fn ($s): string => "content LIKE '%{$s}%'",
              $keywords
          )
      ) .
      ')';
}

// $page['since'] is always an int by construction above (intval() result
// or the literal 4).
$since_id = $page['since'];
$page['where_clauses'][] = $since_options[$since_id]['clause'];

// which status to filter on ?
if (! is_admin()) {
    $page['where_clauses'][] = 'validated=\'true\'';
}

$page['where_clauses'][] = get_sql_condition_FandF(
    [
        'forbidden_categories' => 'category_id',
        'visible_categories' => 'category_id',
        'visible_images' => 'ic.image_id',
    ],
    '',
    true
);

// +-----------------------------------------------------------------------+
// |                         comments management                           |
// +-----------------------------------------------------------------------+

$comment_id = null;
$action = null;

$actions = ['delete', 'validate', 'edit'];
foreach ($actions as $loop_action) {
    if (isset($_GET[$loop_action])) {
        $action = $loop_action;
        check_input_parameter($action, $_GET, false, PATTERN_ID);
        // check_input_parameter() validated this against PATTERN_ID
        // (/^\d+$/) above -- it would have called fatal_error() otherwise.
        assert(is_numeric($_GET[$action]));
        $comment_id = (int) $_GET[$action];
        break;
    }
}

if (isset($action) and $comment_id !== null) {
    $comment_author_id = get_comment_author_id($comment_id);
    // die_on_error defaults to true, so false is unreachable here
    assert($comment_author_id !== false);

    if (can_manage_comment($action, $comment_author_id)) {
        $perform_redirect = false;

        if ($action == 'delete') {
            check_pwg_token();
            delete_user_comment($comment_id);
            $perform_redirect = true;
        }

        if ($action == 'validate') {
            check_pwg_token();
            validate_user_comment($comment_id);
            $perform_redirect = true;
        }

        if ($action == 'edit') {
            if (! empty($_POST['content'])) {
                check_pwg_token();
                $post_key = $_POST['key'] ?? null;
                if (! is_string($post_key)) {
                    $post_key = '';
                }
                $comment_action = update_user_comment(
                    [
                        'comment_id' => $_GET['edit'],
                        'image_id' => $_POST['image_id'],
                        'content' => $_POST['content'],
                        'website_url' => @$_POST['website_url'],
                    ],
                    $post_key
                );

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
            }

            $edit_comment = $_GET['edit'];
        }

        if ($perform_redirect) {
            redirect($url_self);
        }
    }
}

// +-----------------------------------------------------------------------+
// |                       page header and options                         |
// +-----------------------------------------------------------------------+

$title = l10n('User comments');
$page['body_id'] = 'theCommentsPage';

$template->set_filenames([
    'comments' => 'comments.tpl',
    'comment_list' => 'comment_list.tpl',
]);
$keyword_param = (isset($_GET['keyword']) && is_scalar($_GET['keyword'])) ? (string) $_GET['keyword'] : null;
$author_param = (isset($_GET['author']) && is_scalar($_GET['author'])) ? (string) $_GET['author'] : null;

$template->assign(
    [
        'F_ACTION' => PHPWG_ROOT_PATH . 'comments.php',
        'F_KEYWORD' => $keyword_param !== null ? htmlspecialchars(stripslashes($keyword_param)) : '',
        'F_AUTHOR' => $author_param !== null ? htmlspecialchars(stripslashes($author_param)) : '',
    ]
);

// +-----------------------------------------------------------------------+
// |                          form construction                            |
// +-----------------------------------------------------------------------+

// Search in a particular category
$blockname = 'categories';

$query = '
SELECT id, name, uppercats, global_rank
  FROM ' . CATEGORIES_TABLE . '
' . get_sql_condition_FandF(
    [
        'forbidden_categories' => 'id',
        'visible_categories' => 'id',
    ],
    'WHERE'
) . '
;';
display_select_cat_wrapper($query, [@$_GET['cat']], $blockname, true);

// Filter on recent comments...
$tpl_var = [];
foreach ($since_options as $id => $option) {
    $tpl_var[$id] = $option['label'];
}
$template->assign('since_options', $tpl_var);
$template->assign('since_options_selected', $page['since']);

// Sort by
$template->assign('sort_by_options', $sort_by);
$template->assign('sort_by_options_selected', $page['sort_by']);

// Sorting order
$template->assign('sort_order_options', $sort_order);
$template->assign('sort_order_options_selected', $page['sort_order']);

// Number of items
$blockname = 'items_number_option';
$tpl_var = [];
foreach ($items_number as $option) {
    $tpl_var[$option] = is_numeric($option) ? $option : l10n($option);
}
$template->assign('item_number_options', $tpl_var);
$template->assign('item_number_options_selected', $page['items_number']);

// +-----------------------------------------------------------------------+
// |                            navigation bar                             |
// +-----------------------------------------------------------------------+

if (isset($_GET['start']) and is_scalar($_GET['start'])) {
    $start = intval($_GET['start']);
} else {
    $start = 0;
}

// +-----------------------------------------------------------------------+
// |                        last comments display                          |
// +-----------------------------------------------------------------------+

$comments = [];
$element_ids = [];
$category_ids = [];

// $page['where_clauses'] only ever receives string pushes above; narrow it
// once here for implode().
$where_clauses = array_filter($page['where_clauses'], is_string(...));

$query = '
SELECT SQL_CALC_FOUND_ROWS com.id AS comment_id,
       com.image_id,
       ic.category_id,
       com.author,
       com.author_id,
       u.' . $email_field . ' AS user_email,
       com.email,
       com.date,
       com.website_url,
       com.content,
       com.validated
  FROM ' . IMAGE_CATEGORY_TABLE . ' AS ic
    INNER JOIN ' . COMMENTS_TABLE . ' AS com
    ON ic.image_id = com.image_id
    LEFT JOIN ' . USERS_TABLE . ' As u
    ON u.' . $id_field . ' = com.author_id
  WHERE ' . implode('
    AND ', $where_clauses) . '
  GROUP BY comment_id
  ORDER BY ' . $sort_by_value . ' ' . $sort_order_value;
if ($selected_items_number !== 'all') {
    $query .= '
  LIMIT ' . $selected_items_number . ' OFFSET ' . $start;
}
$query .= '
;';
$result = pwg_query($query);
while ((bool) ($row = pwg_db_fetch_assoc($result))) {
    $comments[] = $row;
    $element_ids[] = $row['image_id'];
    $category_ids[] = $row['category_id'];
}
$count_row = pwg_db_fetch_row(pwg_query('SELECT FOUND_ROWS()'));
assert($count_row !== null);
[$counter] = $count_row;
// FOUND_ROWS() always returns a single non-null numeric value
assert($counter !== null);

$url = PHPWG_ROOT_PATH . 'comments.php'
  . get_query_string_diff(['start', 'edit', 'delete', 'validate', 'pwg_token']);

// when 'all' items are shown there is no real page size; PHP_INT_MAX makes
// create_navigation_bar's own "more than one page" check always false, so
// no pagination controls are rendered (matching the 'all' UX intent).
$items_number_for_navbar = is_numeric($selected_items_number) ? (int) $selected_items_number : PHP_INT_MAX;

$navbar = create_navigation_bar(
    $url,
    $counter,
    $start,
    $items_number_for_navbar
);

$template->assign('navbar', $navbar);

if (count($comments) > 0) {
    // retrieving element informations
    $query = '
SELECT *
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', $element_ids) . ')
;';
    $elements = query2array($query, 'id');

    // retrieving category informations
    $query = 'SELECT id, name, permalink, uppercats
  FROM ' . CATEGORIES_TABLE . '
  WHERE id IN (' . implode(',', $category_ids) . ')';
    $categories = query2array($query, 'id');

    foreach ($comments as $comment) {
        $image_id = $comment['image_id'];
        // comments.image_id / image_category.image_id are both NOT NULL
        // columns; the driver still returns every value as string|null.
        assert($image_id !== null);

        $category_id = $comment['category_id'];
        // image_category.category_id is a NOT NULL column
        assert($category_id !== null);

        if (! empty($elements[$image_id]['name'])) {
            $name = $elements[$image_id]['name'];
        } else {
            $file = $elements[$image_id]['file'];
            // images.file is a NOT NULL column
            assert($file !== null);
            $name = get_name_from_file($file);
        }

        // source of the thumbnail picture
        $src_image = new SrcImage($elements[$image_id]);

        // link to the full size picture
        $url = make_picture_url(
            [
                'category' => $categories[$category_id],
                'image_id' => $image_id,
                'image_file' => $elements[$image_id]['file'],
            ]
        );

        $email = null;
        if (! empty($comment['user_email'])) {
            $email = $comment['user_email'];
        } elseif (! empty($comment['email'])) {
            $email = $comment['email'];
        }

        $date = $comment['date'];
        // comments.date is a NOT NULL column
        assert($date !== null);

        $author_id = $comment['author_id'];
        // comments.author_id is nullable in schema; a NULL author can never
        // match a real user id, so treat it as unowned rather than casting
        // blindly.
        $author_id = is_numeric($author_id) ? (int) $author_id : -1;

        $tpl_comment = [
            'ID' => $comment['comment_id'],
            'U_PICTURE' => $url,
            'src_image' => $src_image,
            'ALT' => $name,
            'AUTHOR' => trigger_change('render_comment_author', $comment['author']),
            'WEBSITE_URL' => $comment['website_url'],
            'DATE' => format_date($date, ['day_name', 'day', 'month', 'year', 'time']),
            'CONTENT' => trigger_change('render_comment_content', $comment['content']),
        ];

        if (is_admin()) {
            $tpl_comment['EMAIL'] = $email;
        }

        if (can_manage_comment('delete', $author_id)) {
            $tpl_comment['U_DELETE'] = add_url_params(
                $url_self,
                [
                    'delete' => $comment['comment_id'],
                    'pwg_token' => get_pwg_token(),
                ]
            );
        }

        if (can_manage_comment('edit', $author_id)) {
            $tpl_comment['U_EDIT'] = add_url_params(
                $url_self,
                [
                    'edit' => $comment['comment_id'],
                ]
            );

            if (isset($edit_comment) and ($comment['comment_id'] == $edit_comment)) {
                $tpl_comment['IN_EDIT'] = true;
                $key = get_ephemeral_key(2, $image_id);
                $tpl_comment['KEY'] = $key;
                $tpl_comment['IMAGE_ID'] = $image_id;
                $tpl_comment['CONTENT'] = $comment['content'];
                $tpl_comment['PWG_TOKEN'] = get_pwg_token();
                $tpl_comment['U_CANCEL'] = $url_self;
            }
        }

        if (can_manage_comment('validate', $author_id)) {
            if ($comment['validated'] != 'true') {
                $tpl_comment['U_VALIDATE'] = add_url_params(
                    $url_self,
                    [
                        'validate' => $comment['comment_id'],
                        'pwg_token' => get_pwg_token(),
                    ]
                );
            }
        }
        $template->append('comments', $tpl_comment);
    }
}

$derivative_params = trigger_change('get_comments_derivative_params', ImageStdParams::get_by_type(IMG_THUMB));
$template->assign('comment_derivative_params', $derivative_params);

// include menubar
$themeconf = $template->get_template_vars('themeconf');
$themeconf = is_array($themeconf) ? $themeconf : [];
if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theCommentsPage', $themeconf['hide_menu_on'])) {
    include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
}

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+
include PHPWG_ROOT_PATH . 'include/page_header.php';
trigger_notify('loc_end_comments');
flush_page_messages();
if (count($comments) > 0) {
    $template->assign_var_from_handle('COMMENT_LIST', 'comment_list');
}
$template->pparse('comments');
include PHPWG_ROOT_PATH . 'include/page_tail.php';
