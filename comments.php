<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\derivative_std_params;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_comment;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\menubar;
use Piwigo\inc\SrcImage;

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+
const PHPWG_ROOT_PATH = './';
require_once __DIR__.'/inc/common.php';
require_once __DIR__.'/inc/functions_comment.php';

if (! $conf->activate_comments) {
    functions_html::page_not_found(null);
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_GUEST);

$url_self = './comments.php'.functions_url::get_query_string_diff(['delete', 'edit', 'validate', 'pwg_token']);

$sort_order = [
    'DESC' => functions::l10n('descending'),
    'ASC' => functions::l10n('ascending'),
];

// sort_by : database fields proposed for sorting comments list
$sort_by = [
    'date' => functions::l10n('comment date'),
    'image_id' => functions::l10n('photo'),
];

// items_number : list of number of items to display per page
$items_number = [5, 10, 20, 50, 'all'];

// if the default value is not in the expected values, we add it in the $items_number array
if (! in_array($conf->comments_page_nb_comments, $items_number)) {
    $items_number_new = [];

    $is_inserted = false;

    foreach ($items_number as $number) {
        if ($number > $conf->comments_page_nb_comments ||
           ($number == 'all' && ! $is_inserted)
        ) {
            $items_number_new[] = $conf->comments_page_nb_comments;
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
        'label' => functions::l10n('today'),
        'clause' => 'date > '.$conf->sql_backend::pwg_db_get_recent_period_expression(1),
    ],
    2 => [
        'label' => functions::l10n('last %d days', 7),
        'clause' => 'date > '.$conf->sql_backend::pwg_db_get_recent_period_expression(7),
    ],
    3 => [
        'label' => functions::l10n('last %d days', 30),
        'clause' => 'date > '.$conf->sql_backend::pwg_db_get_recent_period_expression(30),
    ],
    4 => [
        'label' => functions::l10n('the beginning'),
        'clause' => '1 = 1',
    ], // stupid but generic
];

functions_plugins::trigger_notify('loc_begin_comments');

$page['since'] = input_int('since', 4, $_GET);

// on which field sorting
//
$page['sort_by'] = 'date';
// if the form was submitted, it overloads default behaviour
$get_sort_by = input_string('sort_by', null, $_GET);

if ($get_sort_by !== null &&
    isset($sort_by[$get_sort_by])
) {
    $page['sort_by'] = $get_sort_by;
}

// order to sort
//
$page['sort_order'] = 'DESC';
// if the form was submitted, it overloads default behaviour
$get_sort_order = input_string('sort_order', null, $_GET);

if ($get_sort_order !== null &&
    isset($sort_order[$get_sort_order])
) {
    $page['sort_order'] = $get_sort_order;
}

// number of items to display
//
$page['items_number'] = $conf->comments_page_nb_comments;
$get_items_number = input_string('items_number', null, $_GET);

if ($get_items_number !== null) {
    $page['items_number'] = $get_items_number;
}

if (! is_numeric($page['items_number']) &&
    $page['items_number'] != 'all'
) {
    $page['items_number'] = 10;
}

$page['where_clauses'] = [];

// which category to filter on ?
$get_cat = input_int('cat', null, $_GET);

if ($get_cat !== null &&
    $get_cat != 0
) {
    functions::check_input_parameter('cat', $_GET, false, PATTERN_ID);

    $category_ids = functions_category::get_subcat_ids([$get_cat]);

    if ($category_ids === []) {
        $category_ids = [-1];
    }

    $imploded_category_ids = implode(', ', $category_ids);
    $page['where_clauses'][] = "category_id IN ({$imploded_category_ids})";
}

// search a particular author
$get_author = input_string('author', null, $_GET);

if (! empty($get_author)) {
    $page['where_clauses'][] = "(u.{$conf->user_fields['username']} = '{$get_author}' OR author = '{$get_author}')";
}

// search a specific comment (if you're coming directly from an admin
// notification email)
$get_comment_id = input_int('comment_id', null, $_GET);

if (! empty($get_comment_id)) {
    functions::check_input_parameter('comment_id', $_GET, false, PATTERN_ID);

    // currently, the comment_id is only used by admins from email
    // for management purpose (validate/delete)
    if (! functions_user::is_admin()) {
        $login_url = functions_url::get_root_url().'identification.php?redirect='.urlencode(urlencode($_SERVER['REQUEST_URI']));
        functions::redirect($login_url);
    }

    $page['where_clauses'][] = 'com.id = '.$get_comment_id;
}

// search a substring among comments content
$get_keyword = input_string('keyword', null, $_GET);

if (! empty($get_keyword)) {
    $page['where_clauses'][] =
      '('.
      implode(
          ' AND ',
          array_map(
              fn (string $s): string => "content LIKE '%{$s}%'",
              preg_split('/[\s,;]+/', $get_keyword)
          )
      ).
      ')';
}

$page['where_clauses'][] = $since_options[$page['since']]['clause'];

// which status to filter on ?
if (! functions_user::is_admin()) {
    $page['where_clauses'][] = "validated='true'";
}

$page['where_clauses'][] = functions_user::get_sql_condition_FandF(
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
        functions::check_input_parameter($action, $_GET, false, PATTERN_ID);
        $comment_id = input_int($action, null, $_GET);
        break;
    }
}

if (isset($action)) {
    $comment_author_id = functions_comment::get_comment_author_id((int) $comment_id);

    if (functions_user::can_manage_comment($action, $comment_author_id)) {
        $perform_redirect = false;

        if ($action === 'delete') {
            functions::check_pwg_token();
            functions_comment::delete_user_comment($comment_id);
            $perform_redirect = true;
        }

        if ($action === 'validate') {
            functions::check_pwg_token();
            functions_comment::validate_user_comment($comment_id);
            $perform_redirect = true;
        }

        if ($action === 'edit') {
            if (! empty(input_string('content', null, $_POST))) {
                functions::check_pwg_token();
                $comment_action = functions_comment::update_user_comment(
                    [
                        'comment_id' => $comment_id,
                        'image_id' => input_int('image_id', null, $_POST),
                        'content' => input_string('content', null, $_POST),
                        'website_url' => input_string('website_url', null, $_POST),
                    ],
                    input_string('key', null, $_POST)
                );

                switch ($comment_action) {
                    case 'moderate':
                        $_SESSION['page_infos'][] = functions::l10n('An administrator must authorize your comment before it is visible.');
                        // no break

                    case 'validate':
                        $_SESSION['page_infos'][] = functions::l10n('Your comment has been registered');
                        $perform_redirect = true;
                        break;

                    case 'reject':
                        $_SESSION['page_errors'][] = functions::l10n('Your comment has NOT been registered because it did not pass the validation rules');
                        break;

                    default:
                        trigger_error('Invalid comment action '.$comment_action, E_USER_WARNING);
                }
            }

            $edit_comment = $comment_id;
        }

        if ($perform_redirect) {
            functions::redirect($url_self);
        }
    }
}

// +-----------------------------------------------------------------------+
// |                       page header and options                         |
// +-----------------------------------------------------------------------+

$title = functions::l10n('User comments');
$page['body_id'] = 'theCommentsPage';

$template->set_filenames([
    'comments' => 'comments.tpl',
    'comment_list' => 'comment_list.tpl',
]);
$template->assign(
    [
        'F_ACTION' => './comments.php',
        'F_KEYWORD' => $get_keyword !== null ? htmlspecialchars(stripslashes($get_keyword)) : '',
        'F_AUTHOR' => $get_author !== null ? htmlspecialchars(stripslashes($get_author)) : '',
    ]
);

// +-----------------------------------------------------------------------+
// |                          form construction                            |
// +-----------------------------------------------------------------------+

// Search in a particular category
$blockname = 'categories';

$sql_condition = functions_user::get_sql_condition_FandF(
    [
        'forbidden_categories' => 'id',
        'visible_categories' => 'id',
    ],
    'WHERE'
);

$query = <<<SQL
    SELECT id, name, uppercats, global_rank
    FROM categories
    {$sql_condition};
    SQL;
functions_category::display_select_cat_wrapper($query, [$get_cat ?? 0], $blockname);

// Filter on recent comments...

$tpl_var = array_map(fn (array $option): string => $option['label'], $since_options);

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
    $tpl_var[$option] = is_numeric($option) ? $option : functions::l10n($option);
}

$template->assign('item_number_options', $tpl_var);
$template->assign('item_number_options_selected', $page['items_number']);

// +-----------------------------------------------------------------------+
// |                            navigation bar                             |
// +-----------------------------------------------------------------------+

$start = input_int('start', 0, $_GET);

// +-----------------------------------------------------------------------+
// |                        last comments display                          |
// +-----------------------------------------------------------------------+

$comments = [];
$element_ids = [];
$category_ids = [];

$where_clauses = implode(' AND ', $page['where_clauses']);
$query = <<<SQL
    SELECT COUNT(*) OVER() AS total_count, com.id AS comment_id, com.image_id, ic.category_id, com.author, com.author_id,
        u.{$conf->user_fields['email']} AS user_email, com.email, com.date, com.website_url, com.content,
        com.validated
    FROM image_category AS ic
    INNER JOIN comments AS com ON ic.image_id = com.image_id
    LEFT JOIN users As u ON u.{$conf->user_fields['id']} = com.author_id
    WHERE {$where_clauses}
    GROUP BY comment_id, ic.category_id
    ORDER BY {$page['sort_by']} {$page['sort_order']}

    SQL;

if ($page['items_number'] != 'all') {
    $query .= <<<SQL
        LIMIT {$page['items_number']} OFFSET {$start}

        SQL;
}

$query = trim($query).';';
$result = $conf->sql_backend::pwg_query($query);

while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $counter = $row['total_count'];
    $comments[] = $row;
    $element_ids[] = $row['image_id'];
    $category_ids[] = $row['category_id'];
}

$url = './comments.php'.functions_url::get_query_string_diff(['start', 'edit', 'delete', 'validate', 'pwg_token']);

$navbar = functions::create_navigation_bar(
    $url,
    $counter,
    $start,
    $page['items_number'],
    ''
);

$template->assign('navbar', $navbar);

if ($comments !== []) {
    // retrieving element information
    $element_ids_str = implode(', ', $element_ids);
    $query = <<<SQL
        SELECT *
        FROM images
        WHERE id IN ({$element_ids_str});
        SQL;
    $elements = $conf->sql_backend::query2array($query, 'id');

    // retrieving category information
    $category_ids_str = implode(', ', $category_ids);
    $query = <<<SQL
        SELECT id, name, permalink, uppercats
        FROM categories
        WHERE id IN ({$category_ids_str});
        SQL;
    $categories = $conf->sql_backend::query2array($query, 'id');

    foreach ($comments as $comment) {
        if (! empty($elements[$comment['image_id']]['name'])) {
            $name = $elements[$comment['image_id']]['name'];
        } else {
            $name = functions::get_name_from_file($elements[$comment['image_id']]['file']);
        }

        // source of the thumbnail picture
        $src_image = new SrcImage($elements[$comment['image_id']]);

        // link to the full size picture
        $url = functions_url::make_picture_url(
            [
                'category' => $categories[$comment['category_id']],
                'image_id' => $comment['image_id'],
                'image_file' => $elements[$comment['image_id']]['file'],
            ]
        );

        $email = null;

        if (! empty($comment['user_email'])) {
            $email = $comment['user_email'];
        } elseif (! empty($comment['email'])) {
            $email = $comment['email'];
        }

        $tpl_comment = [
            'ID' => $comment['comment_id'],
            'U_PICTURE' => $url,
            'src_image' => $src_image,
            'ALT' => $name,
            'AUTHOR' => functions_plugins::trigger_change('render_comment_author', $comment['author']),
            'WEBSITE_URL' => $comment['website_url'],
            'DATE' => functions::format_date($comment['date'], ['day_name', 'day', 'month', 'year', 'time']),
            'CONTENT' => functions_plugins::trigger_change('render_comment_content', $comment['content']),
        ];

        if (functions_user::is_admin()) {
            $tpl_comment['EMAIL'] = $email;
        }

        if (functions_user::can_manage_comment('delete', $comment['author_id'])) {
            $tpl_comment['U_DELETE'] = functions_url::add_url_params(
                $url_self,
                [
                    'delete' => $comment['comment_id'],
                    'pwg_token' => functions::get_pwg_token(),
                ]
            );
        }

        if (functions_user::can_manage_comment('edit', $comment['author_id'])) {
            $tpl_comment['U_EDIT'] = functions_url::add_url_params(
                $url_self,
                [
                    'edit' => $comment['comment_id'],
                ]
            );

            if (isset($edit_comment) &&
                $comment['comment_id'] == $edit_comment
            ) {
                $tpl_comment['IN_EDIT'] = true;
                $key = functions::get_ephemeral_key(2, $comment['image_id']);
                $tpl_comment['KEY'] = $key;
                $tpl_comment['IMAGE_ID'] = $comment['image_id'];
                $tpl_comment['CONTENT'] = $comment['content'];
                $tpl_comment['PWG_TOKEN'] = functions::get_pwg_token();
                $tpl_comment['U_CANCEL'] = $url_self;
            }
        }

        if (functions_user::can_manage_comment('validate', $comment['author_id']) && $comment['validated'] != 'true') {
            $tpl_comment['U_VALIDATE'] = functions_url::add_url_params(
                $url_self,
                [
                    'validate' => $comment['comment_id'],
                    'pwg_token' => functions::get_pwg_token(),
                ]
            );
        }

        $template->append('comments', $tpl_comment);
    }
}

$derivative_params = functions_plugins::trigger_change('get_comments_derivative_params', ImageStdParams::get_by_type(derivative_std_params::IMG_THUMB));
$template->assign('comment_derivative_params', $derivative_params);

// include menubar
$themeconf = $template->get_template_vars('themeconf');

if (! isset($themeconf['hide_menu_on']) ||
    ! in_array('theCommentsPage', $themeconf['hide_menu_on'])
) {
    menubar::initialize_menu();
}

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+
require __DIR__.'/inc/page_header.php';
functions_plugins::trigger_notify('loc_end_comments');
functions_html::flush_page_messages();

if ($comments !== []) {
    $template->assign_var_from_handle('COMMENT_LIST', 'comment_list');
}

$template->pparse('comments');
require __DIR__.'/inc/page_tail.php';
