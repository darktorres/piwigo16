<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\comment
 */


add_event_handler('user_comment_check', 'user_comment_check');

/**
 * Does basic check on comment and returns action to perform.
 * This method is called by a trigger_change()
 *
 * @param string $action before check
 * @return string validate, moderate, reject
 */
/** @param array<string,mixed> $comment */
/** @param array<string,mixed> $comment */
function user_comment_check(string $action, array $comment): string
{
    if ($action == 'reject') {
        return $action;
    }

    $my_action = \Piwigo\Config\Config::commentSpamReject() ? 'reject' : 'moderate';

    if ($action == $my_action) {
        return $action;
    }

    // we do here only BASIC spam check (plugins can do more)
    if (!is_a_guest()) {
        return $action;
    }

    $link_count = preg_match_all(
        '/https?:\/\//',
        is_scalar($comment['content']) ? (string) $comment['content'] : '',
        $matches
    ) ?: 0;

    if (str_contains(is_scalar($comment['author']) ? (string) $comment['author'] : '', 'http://')) {
        $link_count++;
    }

    if ($link_count > \Piwigo\Config\Config::commentSpamMaxLinks()) {
        if (!is_array($_POST['cr'] ?? null)) {
            $_POST['cr'] = [];
        }
        $_POST['cr'][] = 'links';
        return $my_action;
    }
    return $action;
}

/**
 * Tries to insert a user comment and returns action to perform.
 *
 * @param array &$comm
 * @param string $key secret key sent back to the browser
 * @param array &$infos output array of error messages
 * @return string validate, moderate, reject
 */
/**
 * @param array<string,mixed> $comm
 * @param string[] $infos
 */
function insert_user_comment(array &$comm, string $key, array &$infos): string
{
    $comm = array_merge(
        $comm,
        [
        'ip' => $_SERVER['REMOTE_ADDR'],
        'agent' => $_SERVER['HTTP_USER_AGENT'],
    ]
    );

    $infos = [];
    if (!\Piwigo\Config\Config::commentsValidation() or is_admin()) {
        $comment_action = 'validate'; //one of validate, moderate, reject
    } else {
        $comment_action = 'moderate'; //one of validate, moderate, reject
    }

    // display author field if the user status is guest or generic
    if (!is_classic_user()) {
        if (empty($comm['author'])) {
            if (\Piwigo\Config\Config::commentsAuthorMandatory()) {
                $infos[] = l10n('Username is mandatory');
                $comment_action = 'reject';
            }
            $comm['author'] = 'guest';
        }
        $comm['author_id'] = \Piwigo\Config\Config::guestId();
        // if a guest try to use the name of an already existing user, he must be
        // rejected
        if ($comm['author'] != 'guest') {
            $usernameField = \Piwigo\Config\Config::userFields()['username'];
            $authorStr     = is_scalar($comm['author']) ? (string) $comm['author'] : '';
            $count = \Piwigo\Core\ServiceLocator::get(\Piwigo\Comment\CommentRepository::class)
                ->countByUsername($usernameField, $authorStr);
            if ($count > 0) {
                $infos[] = l10n('This login is already used by another user');
                $comment_action = 'reject';
            }
        }
    } else {
        $currentUser = \Piwigo\Users\CurrentUser::get();
        $comm['author'] = addslashes($currentUser->username);
        $comm['author_id'] = $currentUser->id;
    }

    if (empty($comm['content'])) { // empty comment content
        $comment_action = 'reject';
    }

    if (!verify_ephemeral_key($key, is_scalar($comm['image_id']) ? (string) $comm['image_id'] : '')) {
        $comment_action = 'reject';
        if (!is_array($_POST['cr'] ?? null)) {
            $_POST['cr'] = [];
        }
        $_POST['cr'][] = 'key'; // rvelices: I use this outside to see how spam robots work
    }

    // website
    if (!empty($comm['website_url'])) {
        if (!\Piwigo\Config\Config::commentsEnableWebsite()) { // honeypot: if the field is disabled, it should be empty !
            $comment_action = 'reject';
            if (!is_array($_POST['cr'] ?? null)) {
                $_POST['cr'] = [];
            }
            $_POST['cr'][] = 'website_url';
        } else {
            $comm['website_url'] = strip_tags(is_scalar($comm['website_url']) ? (string) $comm['website_url'] : '');
            if (!preg_match('/^https?/i', $comm['website_url'])) {
                $comm['website_url'] = 'http://'.$comm['website_url'];
            }
            if (!url_check_format($comm['website_url'])) {
                $infos[] = l10n('Your website URL is invalid');
                $comment_action = 'reject';
            }
        }
    }

    // email
    if (empty($comm['email'])) {
        $currentUserEmail = \Piwigo\Users\CurrentUser::get()->email;
        if ($currentUserEmail !== '') {
            $comm['email'] = $currentUserEmail;
        } elseif (\Piwigo\Config\Config::commentsEmailMandatory()) {
            $infos[] = l10n('Email address is missing. Please specify an email address.');
            $comment_action = 'reject';
        }
    } elseif (!email_check_format(is_scalar($comm['email']) ? (string) $comm['email'] : '')) {
        $infos[] = l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        $comment_action = 'reject';
    }

    // anonymous id = ip address
    $ip_components = explode('.', is_scalar($comm['ip']) ? (string) $comm['ip'] : '');
    if (count($ip_components) > 3) {
        array_pop($ip_components);
    }
    $anonymous_id = implode('.', $ip_components);

    if ($comment_action != 'reject' and \Piwigo\Config\Config::antiFloodTime() > 0 and !is_admin()) { // anti-flood system
        $counter = \Piwigo\Core\ServiceLocator::get(\Piwigo\Comment\CommentRepository::class)
            ->countRecentByAuthor(
                (int) $comm['author_id'],
                \Piwigo\Config\Config::antiFloodTime(),
                is_classic_user() ? '' : $anonymous_id
            );
        if ($counter > 0) {
            $infos[] = l10n('Anti-flood system : please wait for a moment before trying to post another comment');
            $comment_action = 'reject';
            if (!is_array($_POST['cr'] ?? null)) {
                $_POST['cr'] = [];
            }
            $_POST['cr'][] = 'flood_time';
        }
    }

    // perform more spam check
    $comment_action = (string) trigger_change(
        'user_comment_check',
        $comment_action,
        $comm
    );

    if ($comment_action != 'reject') {
        $comm['id'] = \Piwigo\Core\ServiceLocator::get(\Piwigo\Comment\CommentRepository::class)
            ->insert([
                'author'      => is_scalar($comm['author']) ? (string) $comm['author'] : '',
                'author_id'   => (int) $comm['author_id'],
                'anonymous_id' => is_scalar($comm['ip']) ? (string) $comm['ip'] : '',
                'content'     => is_scalar($comm['content']) ? (string) $comm['content'] : '',
                'validated'   => $comment_action === 'validate',
                'image_id'    => is_scalar($comm['image_id']) ? (int) $comm['image_id'] : 0,
                'website_url' => !empty($comm['website_url']) ? (is_scalar($comm['website_url']) ? (string) $comm['website_url'] : null) : null,
                'email'       => !empty($comm['email']) ? (is_scalar($comm['email']) ? (string) $comm['email'] : null) : null,
            ]);

        invalidate_user_cache_nb_comments();

        if ((\Piwigo\Config\Config::emailAdminOnComment() && 'validate' == $comment_action)
            or (\Piwigo\Config\Config::emailAdminOnCommentValidation() and 'moderate' == $comment_action)) {
            include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');

            $comment_url = get_absolute_root_url().'comments.php?comment_id='.$comm['id'];

            $keyargs_content = [
              get_l10n_args('Author: %s', stripslashes(is_scalar($comm['author']) ? (string) $comm['author'] : '')),
              get_l10n_args('Email: %s', stripslashes(is_scalar($comm['email']) ? (string) $comm['email'] : '')),
              get_l10n_args('Comment: %s', stripslashes(is_scalar($comm['content']) ? (string) $comm['content'] : '')),
              get_l10n_args(''),
              get_l10n_args('Manage this user comment: %s', $comment_url),
            ];

            if ('moderate' == $comment_action) {
                $keyargs_content[] = get_l10n_args('(!) This comment requires validation');
            }

            pwg_mail_notification_admins(
                get_l10n_args('Comment by %s', stripslashes(is_scalar($comm['author']) ? (string) $comm['author'] : '')),
                $keyargs_content
            );
        }
    }

    return $comment_action;
}

/**
 * Tries to delete a (or more) user comment.
 *    only admin can delete all comments
 *    other users can delete their own comments
 *
 * @param int|int[] $comment_id
 * @return bool false if nothing deleted
 */
function delete_user_comment($comment_id): bool
{
    $globalUser = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
    $user_where_clause = '';
    if (!is_admin()) {
        $user_where_clause = '   AND author_id = \''. (is_scalar($globalUser['id'] ?? null) ? (string) $globalUser['id'] : '0') .'\'';
    }

    if (is_array($comment_id)) {
        $where_clause = 'id IN('.implode(',', $comment_id).')';
    } else {
        $where_clause = 'id = '.$comment_id;
    }

    $authorId = is_admin() ? null : (is_numeric($globalUser['id'] ?? null) ? (int) $globalUser['id'] : 0);
    $affected = \Piwigo\Core\ServiceLocator::get(\Piwigo\Comment\CommentRepository::class)
        ->delete($comment_id, $authorId);

    if ($affected > 0) {
        invalidate_user_cache_nb_comments();

        email_admin(
            'delete',
            ['author' => is_scalar($globalUser['username'] ?? null) ? (string) $globalUser['username'] : '',
                          'comment_id' => $comment_id,
                      ]
        );
        trigger_notify('user_comment_deletion', $comment_id);

        return true;
    }

    return false;
}

/**
 * Tries to update a user comment
 *    only admin can update all comments
 *    users can edit their own comments if admin allow them
 *
 * @param string $post_key secret key sent back to the browser
 * @return string validate, moderate, reject
 */
/** @param array<string,mixed> $comment */
/** @param array<string,mixed> $comment */
function update_user_comment(array $comment, string $post_key): string
{
    $comment_action = 'validate';

    if (!verify_ephemeral_key($post_key, is_scalar($comment['image_id']) ? (string) $comment['image_id'] : '')) {
        $comment_action = 'reject';
    } elseif (!\Piwigo\Config\Config::commentsValidation() or is_admin()) { // should the updated comment must be validated
        $comment_action = 'validate'; //one of validate, moderate, reject
    } else {
        $comment_action = 'moderate'; //one of validate, moderate, reject
    }

    $globalUser2 = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
    // perform more spam check
    $comment_action = (string)
      trigger_change(
          'user_comment_check',
          $comment_action,
          array_merge(
              $comment,
              ['author' => is_scalar($globalUser2['username'] ?? null) ? (string) $globalUser2['username'] : '']
          )
      );

    // website
    if (!empty($comment['website_url'])) {
        $wUrl = is_scalar($comment['website_url']) ? (string) $comment['website_url'] : '';
        $comment['website_url'] = strip_tags($wUrl);
        if (!preg_match('/^https?/i', $comment['website_url'])) {
            $comment['website_url'] = 'http://'.$comment['website_url'];
        }
        if (!url_check_format($comment['website_url'])) {
            \Piwigo\Core\PageState::current()->addError(l10n('Your website URL is invalid'));
            $comment_action = 'reject';
        }
    }

    if ($comment_action != 'reject') {
        $updateAuthorId = is_admin() ? null : (is_numeric($globalUser2['id'] ?? null) ? (int) $globalUser2['id'] : null);
        $result = \Piwigo\Core\ServiceLocator::get(\Piwigo\Comment\CommentRepository::class)
            ->update(
                (int) (is_scalar($comment['comment_id']) ? $comment['comment_id'] : 0),
                [
                    'content'     => is_scalar($comment['content']) ? (string) $comment['content'] : '',
                    'website_url' => !empty($comment['website_url']) ? (is_scalar($comment['website_url']) ? (string) $comment['website_url'] : null) : null,
                    'validated'   => $comment_action === 'validate',
                ],
                $updateAuthorId
            );

        // mail admin and ask to validate the comment
        if ($result and \Piwigo\Config\Config::emailAdminOnCommentValidation() and 'moderate' == $comment_action) {
            include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');

            $comment_url = get_absolute_root_url().'comments.php?comment_id='. (is_scalar($comment['comment_id']) ? (string) $comment['comment_id'] : '0');

            $keyargs_content = [
              get_l10n_args('Author: %s', stripslashes(is_scalar($globalUser2['username'] ?? null) ? (string) $globalUser2['username'] : '')),
              get_l10n_args('Comment: %s', stripslashes(is_scalar($comment['content']) ? (string) $comment['content'] : '')),
              get_l10n_args(''),
              get_l10n_args('Manage this user comment: %s', $comment_url),
              get_l10n_args('(!) This comment requires validation'),
            ];

            pwg_mail_notification_admins(
                get_l10n_args('Comment by %s', stripslashes(is_scalar($globalUser2['username'] ?? null) ? (string) $globalUser2['username'] : '')),
                $keyargs_content
            );
        }
        // just mail admin
        elseif ($result) {
            email_admin('edit', ['author' => is_scalar($globalUser2['username'] ?? null) ? (string) $globalUser2['username'] : '',
                      'content' => stripslashes(is_scalar($comment['content']) ? (string) $comment['content'] : '')]);
        }
    }

    return $comment_action;
}

/**
 * Notifies admins about updated or deleted comment.
 * Only used when no validation is needed, otherwise pwg_mail_notification_admins() is used.
 *
 * @param string $action edit, delete
 */
/** @param array<string,mixed> $comment */
function email_admin(string $action, array $comment): void
{
    if (!in_array($action, ['edit', 'delete'])
        or (($action == 'edit') and !\Piwigo\Config\Config::emailAdminOnCommentEdition())
        or (($action == 'delete') and !\Piwigo\Config\Config::emailAdminOnCommentDeletion())) {
        return;
    }

    include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');

    $keyargs_content = [
      get_l10n_args('Author: %s', $comment['author']),
      ];

    if ($action == 'delete') {
        $keyargs_content[] = get_l10n_args('This author removed the comment with id %d', $comment['comment_id']);
    } else {
        $keyargs_content[] = get_l10n_args('This author modified following comment:');
        $keyargs_content[] = get_l10n_args('Comment: %s', $comment['content']);
    }

    pwg_mail_notification_admins(
        get_l10n_args('Comment by %s', $comment['author']),
        $keyargs_content
    );
}

/**
 * Returns the author id of a comment
 *
 * @param int $comment_id
 * @param bool $die_on_error
 * @return int|false
 */
function get_comment_author_id($comment_id, $die_on_error = true)
{
    $authorId = \Piwigo\Core\ServiceLocator::get(\Piwigo\Comment\CommentRepository::class)
        ->getAuthorId((int) $comment_id);

    if ($authorId === null) {
        if ($die_on_error) {
            fatal_error('Unknown comment identifier');
        } else {
            return false;
        }
    }

    return $authorId;
}

/**
 * Tries to validate a user comment.
 *
 * @param int|int[] $comment_id
 */
function validate_user_comment($comment_id): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Comment\CommentRepository::class)
        ->setValidated($comment_id);

    invalidate_user_cache_nb_comments();
    trigger_notify('user_comment_validation', $comment_id);
}

/**
 * Clears cache of nb comments for all users
 */
function invalidate_user_cache_nb_comments(): void
{
    if (isset($GLOBALS['user']) && is_array($GLOBALS['user'])) {
        unset($GLOBALS['user']['nb_available_comments']);
    }

    \Piwigo\Core\ServiceLocator::get(\Piwigo\Comment\CommentRepository::class)
        ->clearNbAvailableCommentsCache();
}
