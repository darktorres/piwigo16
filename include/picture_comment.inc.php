<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * This file is included by the picture page to manage user comments
 */

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $conf, $page, $template, $user;
// Set by picture.php, right before this include.
/**
 * @var list<array<string, string|null>> $related_categories
 * @var string $url_self
 */
global $related_categories, $url_self;

$comment_action = null;

// $page['image_id'] is int|numeric-string (include/section_init.inc.php sets
// it from a URL token via is_numeric(), possibly re-resolved by picture.php).
$image_id = $page['image_id'];
$image_id = is_numeric($image_id) ? (int) $image_id : 0;

// the picture is commentable if it belongs at least to one category which
// is commentable
$page['show_comments'] = false;
foreach ($related_categories as $category) {
    if ($category['commentable'] == 'true') {
        $page['show_comments'] = true;
        break;
    }
}

if ($page['show_comments'] and isset($_POST['content'])) {
    if (is_a_guest() and ! (bool) $conf['comments_forall']) {
        die('Session expired');
    }

    $post_author = $_POST['author'] ?? null;
    // isset($_POST['content']) was already checked by the enclosing if().
    $post_content = $_POST['content'];
    $post_website_url = $_POST['website_url'] ?? null;
    $post_email = $_POST['email'] ?? null;

    $comm = [
        'author' => is_string($post_author) && ! empty($post_author) ? trim($post_author) : '',
        'content' => is_string($post_content) && ! empty($post_content) ? trim($post_content) : '',
        'website_url' => is_string($post_website_url) && ! empty($post_website_url) ? trim($post_website_url) : '',
        'email' => is_string($post_email) && ! empty($post_email) ? trim($post_email) : '',
        'image_id' => $image_id,
    ];

    include_once PHPWG_ROOT_PATH . 'include/functions_comment.inc.php';

    $post_key = $_POST['key'] ?? null;
    // insert_user_comment() overwrites $comment_errors unconditionally as its
    // very first statement, so whatever was previously in $page['errors'] is
    // never actually read by it; a fresh array is passed and the result is
    // written back below.
    $comment_errors = [];
    $comment_action = insert_user_comment($comm, is_string($post_key) ? $post_key : '', $comment_errors);
    $page['errors'] = $comment_errors;

    // Narrowed once into local variables and written back after the switch,
    // so the case bodies below don't re-read the $page[...] offsets
    // directly (switch branches lose array-offset narrowing in this
    // codebase, see the other L10 fixes for the same pattern).
    $comment_infos = is_array($page['infos'] ?? null) ? $page['infos'] : [];

    switch ($comment_action) {
        case 'moderate':
            $comment_infos[] = l10n('An administrator must authorize your comment before it is visible.');
            // no break
        case 'validate':
            $comment_infos[] = l10n('Your comment has been registered');
            break;
        case 'reject':
            set_status_header(403);
            $comment_errors[] = l10n('Your comment has NOT been registered because it did not pass the validation rules');
            break;
        default:
            trigger_error('Invalid comment action ' . $comment_action, E_USER_WARNING);
    }

    $page['infos'] = $comment_infos;
    $page['errors'] = $comment_errors;

    // allow plugins to notify what's going on
    trigger_notify(
        'user_comment_insertion',
        array_merge($comm, [
            'action' => $comment_action,
        ])
    );
} elseif (isset($_POST['content'])) {
    set_status_header(403);
    die('ugly spammer');
}

if ($page['show_comments']) {
    if (! is_admin()) {
        $validated_clause = '  AND validated = \'true\'';
    } else {
        $validated_clause = '';
    }

    // number of comments for this picture
    $query = '
SELECT
    COUNT(*) AS nb_comments
  FROM ' . Tables::comments() . '
  WHERE image_id = ' . $image_id
    . $validated_clause . '
;';
    $row = pwg_db_fetch_assoc(pwg_query($query));
    // a COUNT(*) query always returns exactly one row
    assert(is_array($row));
    $nb_comments = is_numeric($row['nb_comments']) ? (int) $row['nb_comments'] : 0;

    // navigation bar creation
    if (! isset($page['start'])) {
        $page['start'] = 0;
    }
    $start = $page['start'];
    $start = is_numeric($start) ? (int) $start : 0;

    $nb_comment_page = $conf['nb_comment_page'];
    $nb_comment_page = is_numeric($nb_comment_page) ? (int) $nb_comment_page : 0;

    $navigation_bar = create_navigation_bar(
        duplicate_picture_url([], ['start']),
        $nb_comments,
        $start,
        $nb_comment_page,
        true // We want a clean URL
    );

    $template->assign(
        [
            'COMMENT_COUNT' => $nb_comments,
            'navbar' => $navigation_bar,
            'comments' => [],
        ]
    );

    if ($nb_comments > 0) {
        // comments order (get, session, conf)
        $get_comments_order = $_GET['comments_order'] ?? null;
        if (is_string($get_comments_order) && ! empty($get_comments_order) && in_array(strtoupper($get_comments_order), ['ASC', 'DESC'])) {
            pwg_set_session_var('comments_order', $get_comments_order);
        }
        $comments_order = pwg_get_session_var('comments_order', $conf['comments_order']);
        $comments_order = is_string($comments_order) ? $comments_order : 'ASC';

        $template->assign([
            'COMMENTS_ORDER_URL' => add_url_params(duplicate_picture_url(), [
                'comments_order' => ($comments_order == 'ASC' ? 'DESC' : 'ASC'),
            ]),
            'COMMENTS_ORDER_TITLE' => $comments_order == 'ASC' ? l10n('Show latest comments first') : l10n('Show oldest comments first'),
        ]);

        // $conf['user_fields'] maps generic field names to actual DB column
        // names; it is always set by config_default.inc.php.
        $user_fields = $conf['user_fields'] ?? null;
        $user_fields = is_array($user_fields) ? $user_fields : [];
        $user_field_email = $user_fields['email'] ?? null;
        $user_field_email = is_string($user_field_email) ? $user_field_email : '';
        $user_field_id = $user_fields['id'] ?? null;
        $user_field_id = is_string($user_field_id) ? $user_field_id : '';

        $query = '
SELECT
    com.id,
    com.author,
    com.author_id,
    u.' . $user_field_email . ' AS user_email,
    com.date,
    com.image_id,
    com.website_url,
    com.email,
    com.content,
    com.validated
  FROM ' . Tables::comments() . ' AS com
  LEFT JOIN ' . Tables::users() . ' AS u
    ON u.' . $user_field_id . ' = author_id
  WHERE com.image_id = ' . $image_id . '
    ' . $validated_clause . '
  ORDER BY com.date ' . $comments_order . '
  LIMIT ' . $nb_comment_page . ' OFFSET ' . $start . '
;';
        $result = pwg_query($query);

        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            if ($row['author'] == 'guest') {
                $row['author'] = l10n('guest');
            }

            $email = null;
            if (! empty($row['user_email'])) {
                $email = $row['user_email'];
            } elseif (! empty($row['email'])) {
                $email = $row['email'];
            }

            // com.date is NOT NULL in the schema (default '1970-01-01 00:00:00'),
            // so a fetched row always carries a real date string.
            assert(is_string($row['date']));

            $tpl_comment =
              [
                  'ID' => $row['id'],
                  'AUTHOR' => trigger_change('render_comment_author', $row['author']),
                  'DATE' => format_date($row['date'], ['day_name', 'day', 'month', 'year', 'time']),
                  'CONTENT' => trigger_change('render_comment_content', $row['content']),
                  'WEBSITE_URL' => $row['website_url'],
              ];

            // com.author_id allows NULL (anonymous/guest comments); no real
            // user id is ever negative, so -1 is a safe "never matches" sentinel.
            $comment_author_id = is_numeric($row['author_id']) ? (int) $row['author_id'] : -1;

            if (can_manage_comment('delete', $comment_author_id)) {
                $tpl_comment['U_DELETE'] = add_url_params(
                    $url_self,
                    [
                        'action' => 'delete_comment',
                        'comment_to_delete' => $row['id'],
                        'pwg_token' => get_pwg_token(),
                    ]
                );
            }
            if (can_manage_comment('edit', $comment_author_id)) {
                $tpl_comment['U_EDIT'] = add_url_params(
                    $url_self,
                    [
                        'action' => 'edit_comment',
                        'comment_to_edit' => $row['id'],
                    ]
                );
                if (isset($edit_comment) and ($row['id'] == $edit_comment)) {
                    $tpl_comment['IN_EDIT'] = true;
                    $key = get_ephemeral_key(2, (string) $image_id);
                    $tpl_comment['KEY'] = $key;
                    $tpl_comment['CONTENT'] = $row['content'];
                    $tpl_comment['PWG_TOKEN'] = get_pwg_token();
                    $tpl_comment['U_CANCEL'] = $url_self;
                }
            }
            if (is_admin()) {
                $tpl_comment['EMAIL'] = $email;

                if ($row['validated'] != 'true') {
                    $tpl_comment['U_VALIDATE'] = add_url_params(
                        $url_self,
                        [
                            'action' => 'validate_comment',
                            'comment_to_validate' => $row['id'],
                            'pwg_token' => get_pwg_token(),
                        ]
                    );
                }
            }
            $template->append('comments', $tpl_comment);
        }
    }

    $show_add_comment_form = true;
    if (isset($edit_comment)) {
        $show_add_comment_form = false;
    }
    if (is_a_guest() and ! (bool) $conf['comments_forall']) {
        $show_add_comment_form = false;
    }

    if ($show_add_comment_form) {
        $key = get_ephemeral_key(3, (string) $image_id);

        $tpl_var = [
            'F_ACTION' => $url_self,
            'KEY' => $key,
            'CONTENT' => '',
            'SHOW_AUTHOR' => ! is_classic_user(),
            'AUTHOR_MANDATORY' => $conf['comments_author_mandatory'],
            'AUTHOR' => '',
            'WEBSITE_URL' => '',
            'SHOW_EMAIL' => ! is_classic_user() or empty($user['email']),
            'EMAIL_MANDATORY' => $conf['comments_email_mandatory'],
            'EMAIL' => '',
            'SHOW_WEBSITE' => $conf['comments_enable_website'],
        ];

        if ($comment_action == 'reject') {
            foreach (['content', 'author', 'website_url', 'email'] as $k) {
                $post_value = $_POST[$k] ?? null;
                $tpl_var[strtoupper($k)] = is_string($post_value) ? htmlspecialchars(stripslashes($post_value)) : '';
            }
        }
        $template->assign('comment_add', $tpl_var);
    }
    $template->set_filenames([
        'comment_list' => 'comment_list.tpl',
    ]);
    $template->assign_var_from_handle('COMMENT_LIST', 'comment_list');
}
