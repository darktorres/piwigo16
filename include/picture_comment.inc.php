<?php

declare(strict_types=1);

global $persistent_cache, $url_self, $picture, $related_categories, $comment_action;

use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This file is included by the picture page to manage user comments
 *
 */

$template = TemplateRegistry::current();
$page = &$GLOBALS['page'];
if (!is_array($page)) {
    $page = [];
}
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
    if (is_a_guest() and !\Piwigo\Config\Config::commentsForall()) {
        throw new \Piwigo\Exception\AuthException('Session expired');
    }

    $comm = [
      'author' => empty($_POST['author'] ?? null) ? '' : trim(is_scalar($_POST['author']) ? (string) $_POST['author'] : ''),
      'content' => empty($_POST['content']) ? '' : trim(is_scalar($_POST['content']) ? (string) $_POST['content'] : ''),
      'website_url' => empty($_POST['website_url'] ?? null) ? '' : trim(is_scalar($_POST['website_url']) ? (string) $_POST['website_url'] : ''),
      'email' => empty($_POST['email'] ?? null) ? '' : trim(is_scalar($_POST['email']) ? (string) $_POST['email'] : ''),
      'image_id' => $page['image_id'],
     ];

    include_once(PHPWG_ROOT_PATH.'include/functions_comment.inc.php');

    $post_key = $_POST['key'] ?? '';
    $pageStateErrors = &\Piwigo\Core\PageState::current()->errors;
    $comment_action = insert_user_comment($comm, is_scalar($post_key) ? (string) $post_key : '', $pageStateErrors);

    switch ($comment_action) {
        case 'moderate':
            \Piwigo\Core\PageState::current()->addInfo(l10n('An administrator must authorize your comment before it is visible.'));
            // no break
        case 'validate':
            \Piwigo\Core\PageState::current()->addInfo(l10n('Your comment has been registered'));
            break;
        case 'reject':
            set_status_header(403);
            \Piwigo\Core\PageState::current()->addError(l10n('Your comment has NOT been registered because it did not pass the validation rules'));
            break;
        default:
            trigger_error('Invalid comment action '.$comment_action, E_USER_WARNING);
    }

    // allow plugins to notify what's going on
    trigger_notify(
        'user_comment_insertion',
        array_merge($comm, ['action' => $comment_action])
    );
} elseif (isset($_POST['content'])) {
    set_status_header(403);
    throw new \Piwigo\Exception\AuthException('ugly spammer');
}

if ($page['show_comments']) {
    if (!is_admin()) {
        $validated_clause = '  AND validated = \'true\'';
    } else {
        $validated_clause = '';
    }

    $imageId = is_numeric($page['image_id'] ?? null) ? (int) $page['image_id'] : 0;
    // number of comments for this picture
    $row = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery(
            'SELECT COUNT(*) AS nb_comments FROM ' . COMMENTS_TABLE .
            ' WHERE image_id = ?' . ($validated_clause !== '' ? " AND validated = 'true'" : ''),
            [$imageId]
        )
        ->fetchAssociative() ?: [];

    // navigation bar creation
    if (!isset($page['start']) || !is_numeric($page['start'])) {
        $page['start'] = 0;
    }
    $startOffset = (int) $page['start'];

    $nb_comments = is_numeric($row['nb_comments'] ?? null) ? (int) $row['nb_comments'] : 0;

    $navigation_bar = create_navigation_bar(
        duplicate_picture_url([], ['start']),
        $nb_comments,
        $startOffset,
        \Piwigo\Config\Config::nbCommentPage(),
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
        if (!empty($get_comments_order) && in_array(strtoupper(is_scalar($get_comments_order) ? (string) $get_comments_order : ''), ['ASC', 'DESC'])) {
            pwg_set_session_var('comments_order', $get_comments_order);
        }
        $comments_order = pwg_get_session_var('comments_order', \Piwigo\Config\Config::commentsOrder());

        $template->assign([
          'COMMENTS_ORDER_URL' => add_url_params(duplicate_picture_url(), ['comments_order' => ($comments_order == 'ASC' ? 'DESC' : 'ASC') ]),
          'COMMENTS_ORDER_TITLE' => $comments_order == 'ASC' ? l10n('Show latest comments first') : l10n('Show oldest comments first'),
          ]);

        $query = '
SELECT
    com.id,
    com.author,
    com.author_id,
    u.'.\Piwigo\Config\Config::userFields()['email'].' AS user_email,
    com.date,
    com.image_id,
    com.website_url,
    com.email,
    com.content,
    com.validated
  FROM '.COMMENTS_TABLE.' AS com
  LEFT JOIN '.USERS_TABLE.' AS u
    ON u.'.\Piwigo\Config\Config::userFields()['id'].' = author_id
  WHERE com.image_id = '.$imageId.'
    '.$validated_clause.'
  ORDER BY com.date '.$comments_order.'
  LIMIT '.\Piwigo\Config\Config::nbCommentPage().' OFFSET '.$startOffset.'
;';
        $commentRows = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
            ->executeQuery($query)
            ->fetchAllAssociative();

        foreach ($commentRows as $row) {
            if ($row['author'] == 'guest') {
                $row['author'] = l10n('guest');
            }

            $email = null;
            if (!empty($row['user_email'])) {
                $email = $row['user_email'];
            } elseif (!empty($row['email'])) {
                $email = $row['email'];
            }

            $tpl_comment =
              [
                'ID' => $row['id'],
                'AUTHOR' => trigger_change('render_comment_author', $row['author']),
                'DATE' => format_date(is_scalar($row['date']) ? (string) $row['date'] : '', ['day_name','day','month','year','time']),
                'CONTENT' => trigger_change('render_comment_content', $row['content']),
                'WEBSITE_URL' => $row['website_url'],
              ];

            if (can_manage_comment('delete', is_numeric($row['author_id']) ? (int) $row['author_id'] : 0)) {
                $tpl_comment['U_DELETE'] = add_url_params(
                    $url_self,
                    [
                    'action' => 'delete_comment',
                    'comment_to_delete' => $row['id'],
                    'pwg_token' => get_pwg_token(),
                    ]
                );
            }
            if (can_manage_comment('edit', is_numeric($row['author_id']) ? (int) $row['author_id'] : 0)) {
                $tpl_comment['U_EDIT'] = add_url_params(
                    $url_self,
                    [
                    'action' => 'edit_comment',
                    'comment_to_edit' => $row['id'],
                    ]
                );
                if (isset($edit_comment) and ($row['id'] == $edit_comment)) {
                    $tpl_comment['IN_EDIT'] = true;
                    $key = get_ephemeral_key(2, (string) $imageId);
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
    if (is_a_guest() and !\Piwigo\Config\Config::commentsForall()) {
        $show_add_comment_form = false;
    }

    if ($show_add_comment_form) {
        $key = get_ephemeral_key(3, (string) $imageId);

        $tpl_var =  [
            'F_ACTION' =>         $url_self,
            'KEY' =>              $key,
            'CONTENT' =>          '',
            'SHOW_AUTHOR' =>      !is_classic_user(),
            'AUTHOR_MANDATORY' => \Piwigo\Config\Config::commentsAuthorMandatory(),
            'AUTHOR' =>           '',
            'WEBSITE_URL' =>      '',
            'SHOW_EMAIL' =>       !is_classic_user() or empty(CurrentUser::get()->email),
            'EMAIL_MANDATORY' =>  \Piwigo\Config\Config::commentsEmailMandatory(),
            'EMAIL' =>            '',
            'SHOW_WEBSITE' =>     \Piwigo\Config\Config::commentsEnableWebsite(),
          ];

        if ('reject' == ($comment_action ?? null)) {
            foreach (['content', 'author', 'website_url', 'email'] as $k) {
                $post_val = $_POST[$k] ?? null;
                $tpl_var[strtoupper($k)] = isset($post_val) ? htmlspecialchars(stripslashes(is_scalar($post_val) ? (string) $post_val : '')) : '';
            }
        }
        $template->assign('comment_add', $tpl_var);
    }
    $template->set_filenames(['comment_list' => 'comment_list.tpl']);
    $template->assign_var_from_handle('COMMENT_LIST', 'comment_list');
}
