<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Db\Tables;

add_event_handler('user_comment_check', 'user_comment_check');

/**
 * Does basic check on comment and returns action to perform.
 * This method is called by a trigger_change()
 *
 * @param string $action before check
 * @param array<string, mixed> $comment
 * @return string validate, moderate, reject
 */
function user_comment_check($action, array $comment)
{
    /** @var array<string, mixed> $conf */
    global $conf,$user;

    if ($action == 'reject') {
        return $action;
    }

    $my_action = ((bool) $conf['comment_spam_reject']) ? 'reject' : 'moderate';

    if ($action == $my_action) {
        return $action;
    }

    // we do here only BASIC spam check (plugins can do more)
    if (! is_a_guest()) {
        return $action;
    }

    $comment_content = is_string($comment['content'] ?? null) ? $comment['content'] : '';
    $link_count = preg_match_all(
        '/https?:\/\//',
        $comment_content,
        $matches
    );
    // the pattern above is a hardcoded, always-valid regex
    assert($link_count !== false);

    $comment_author = is_string($comment['author'] ?? null) ? $comment['author'] : '';
    if (str_contains($comment_author, 'http://')) {
        $link_count++;
    }

    if ($link_count > $conf['comment_spam_max_links']) {
        if (! isset($_POST['cr']) or ! is_array($_POST['cr'])) {
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
 * @param array<string, mixed> $comm
 * @param string $key secret key sent back to the browser
 * @param array<int, string> $infos output array of error messages
 * @return string validate, moderate, reject
 */
function insert_user_comment(&$comm, $key, &$infos): string
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     */
    global $conf, $user;

    $comm = array_merge(
        $comm,
        [
            'ip' => $_SERVER['REMOTE_ADDR'],
            'agent' => $_SERVER['HTTP_USER_AGENT'],
        ]
    );

    $infos = [];
    if (! (bool) $conf['comments_validation'] or is_admin()) {
        $comment_action = 'validate'; // one of validate, moderate, reject
    } else {
        $comment_action = 'moderate'; // one of validate, moderate, reject
    }

    // display author field if the user status is guest or generic
    if (! is_classic_user()) {
        if (empty($comm['author'])) {
            if ((bool) $conf['comments_author_mandatory']) {
                $infos[] = l10n('Username is mandatory');
                $comment_action = 'reject';
            }
            $comm['author'] = 'guest';
        }
        $comm['author_id'] = $conf['guest_id'];
        // if a guest try to use the name of an already existing user, he must be
        // rejected
        if ($comm['author'] != 'guest') {
            $comment_author_name = is_string($comm['author']) ? $comm['author'] : '';
            // $conf['user_fields'] maps generic field names to actual DB
            // column names; it is always set by config_default.inc.php.
            $user_fields = $conf['user_fields'] ?? null;
            $user_fields = is_array($user_fields) ? $user_fields : [];
            $username_field = is_string($user_fields['username'] ?? null) ? $user_fields['username'] : 'username';
            $query = '
SELECT COUNT(*) AS user_exists
  FROM ' . Tables::users() . '
  WHERE ' . $username_field . " = '" . addslashes($comment_author_name) . "'";
            $row = pwg_db_fetch_assoc(pwg_query($query));
            // a COUNT(*) query always returns exactly one row
            assert(is_array($row));
            if ($row['user_exists'] == 1) {
                $infos[] = l10n('This login is already used by another user');
                $comment_action = 'reject';
            }
        }
    } else {
        $user_username = $user['username'] ?? null;
        $user_username = is_string($user_username) ? $user_username : '';
        $comm['author'] = addslashes($user_username);
        $comm['author_id'] = $user['id'];
    }

    if (empty($comm['content'])) { // empty comment content
        $comment_action = 'reject';
    }

    $comm_image_id = is_scalar($comm['image_id'] ?? null) ? (string) $comm['image_id'] : '';
    if (! verify_ephemeral_key(@$key, $comm_image_id)) {
        $comment_action = 'reject';
        if (! isset($_POST['cr']) or ! is_array($_POST['cr'])) {
            $_POST['cr'] = [];
        }
        $_POST['cr'][] = 'key'; // rvelices: I use this outside to see how spam robots work
    }

    // website
    if (! empty($comm['website_url'])) {
        if (! (bool) $conf['comments_enable_website']) { // honeypot: if the field is disabled, it should be empty !
            $comment_action = 'reject';
            if (! isset($_POST['cr']) or ! is_array($_POST['cr'])) {
                $_POST['cr'] = [];
            }
            $_POST['cr'][] = 'website_url';
        } else {
            $website_url = is_string($comm['website_url']) ? $comm['website_url'] : '';
            $website_url = strip_tags($website_url);
            if (! (bool) preg_match('/^https?/i', $website_url)) {
                $website_url = 'http://' . $website_url;
            }
            $comm['website_url'] = $website_url;
            if (! url_check_format($website_url)) {
                $infos[] = l10n('Your website URL is invalid');
                $comment_action = 'reject';
            }
        }
    }

    // email
    if (empty($comm['email'])) {
        if (! empty($user['email'])) {
            $comm['email'] = $user['email'];
        } elseif ((bool) $conf['comments_email_mandatory']) {
            $infos[] = l10n('Email address is missing. Please specify an email address.');
            $comment_action = 'reject';
        }
    } else {
        $comm_email = is_string($comm['email']) ? $comm['email'] : null;
        if (! email_check_format($comm_email)) {
            $infos[] = l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
            $comment_action = 'reject';
        }
    }

    // anonymous id = ip address
    $comment_ip = is_string($comm['ip']) ? $comm['ip'] : '';
    $ip_components = explode('.', $comment_ip);
    if (count($ip_components) > 3) {
        array_pop($ip_components);
    }
    $anonymous_id = implode('.', $ip_components);

    // $comm['author_id'] was set above to either $conf['guest_id'] or
    // $user['id'], both of which are always scalar.
    $comm_author_id = is_scalar($comm['author_id'] ?? null) ? (string) $comm['author_id'] : '0';

    $anti_flood_time = $conf['anti-flood_time'] ?? 0;
    $anti_flood_time = is_int($anti_flood_time) || is_string($anti_flood_time) ? $anti_flood_time : 0;

    if ($comment_action != 'reject' and $anti_flood_time > 0 and ! is_admin()) { // anti-flood system
        $reference_date = pwg_db_get_flood_period_expression($anti_flood_time);

        $query = '
SELECT count(1) FROM ' . Tables::comments() . '
  WHERE date > ' . $reference_date . '
    AND author_id = ' . $comm_author_id;
        if (! is_classic_user()) {
            $query .= '
      AND anonymous_id LIKE "' . $anonymous_id . '.%"';
        }
        $query .= '
;';

        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$counter] = $row;
        if ($counter > 0) {
            $infos[] = l10n('Anti-flood system : please wait for a moment before trying to post another comment');
            $comment_action = 'reject';
            if (! isset($_POST['cr']) or ! is_array($_POST['cr'])) {
                $_POST['cr'] = [];
            }
            $_POST['cr'][] = 'flood_time';
        }
    }

    // perform more spam check
    $comment_action_result = trigger_change(
        'user_comment_check',
        $comment_action,
        $comm
    );
    // handlers of the user_comment_check event contract MUST return a string
    // (validate/moderate/reject); fail closed if a handler misbehaves
    $comment_action = is_string($comment_action_result) ? $comment_action_result : 'reject';

    if ($comment_action != 'reject') {
        $comment_author = is_string($comm['author']) ? $comm['author'] : '';
        $comment_ip = is_string($comm['ip']) ? $comm['ip'] : '';
        $comment_content = is_string($comm['content']) ? $comm['content'] : '';
        $comment_website_url = is_string($comm['website_url'] ?? null) ? $comm['website_url'] : '';
        $comment_email = is_string($comm['email'] ?? null) ? $comm['email'] : '';

        $query = '
INSERT INTO ' . Tables::comments() . '
  (author, author_id, anonymous_id, content, date, validated, validation_date, image_id, website_url, email)
  VALUES (
    \'' . $comment_author . '\',
    ' . $comm_author_id . ',
    \'' . $comment_ip . '\',
    \'' . $comment_content . '\',
    NOW(),
    \'' . ($comment_action == 'validate' ? 'true' : 'false') . '\',
    ' . ($comment_action == 'validate' ? 'NOW()' : 'NULL') . ',
    ' . $comm_image_id . ',
    ' . (! empty($comment_website_url) ? '\'' . $comment_website_url . '\'' : 'NULL') . ',
    ' . (! empty($comment_email) ? '\'' . $comment_email . '\'' : 'NULL') . '
  )
';
        pwg_query($query);
        $comm['id'] = pwg_db_insert_id();

        invalidate_user_cache_nb_comments();

        if (((bool) $conf['email_admin_on_comment'] && $comment_action == 'validate')
            or ((bool) $conf['email_admin_on_comment_validation'] and $comment_action == 'moderate')) {
            include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';

            $comment_url = get_absolute_root_url() . 'comments.php?comment_id=' . $comm['id'];

            $keyargs_content = [
                get_l10n_args('Author: %s', stripslashes($comment_author)),
                get_l10n_args('Email: %s', stripslashes($comment_email)),
                get_l10n_args('Comment: %s', stripslashes($comment_content)),
                get_l10n_args(''),
                get_l10n_args('Manage this user comment: %s', $comment_url),
            ];

            if ($comment_action == 'moderate') {
                $keyargs_content[] = get_l10n_args('(!) This comment requires validation');
            }

            pwg_mail_notification_admins(
                get_l10n_args('Comment by %s', stripslashes($comment_author)),
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
    /** @var array<string, mixed> $user */
    global $user;

    $user_where_clause = '';
    if (! is_admin()) {
        $user_id_value = $user['id'] ?? null;
        $user_id_str = is_scalar($user_id_value) ? (string) $user_id_value : '';
        $user_where_clause = '   AND author_id = \'' . $user_id_str . '\'';
    }

    if (is_array($comment_id)) {
        $where_clause = 'id IN(' . implode(',', $comment_id) . ')';
    } else {
        $where_clause = 'id = ' . $comment_id;
    }

    $query = '
DELETE FROM ' . Tables::comments() . '
  WHERE ' . $where_clause .
$user_where_clause . '
;';
    pwg_query($query);

    if ((bool) pwg_db_changes()) {
        invalidate_user_cache_nb_comments();

        email_admin(
            'delete',
            [
                'author' => $user['username'],
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
 * @param array<string, mixed> $comment
 * @param string $post_key secret key sent back to the browser
 * @return string validate, moderate, reject
 */
function update_user_comment(array $comment, $post_key): string
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $page
     * @var array<string, mixed> $user
     */
    global $conf, $page, $user;

    $user_username = $user['username'] ?? null;
    $user_username = is_string($user_username) ? $user_username : '';

    $comment_action = 'validate';

    $comment_image_id = is_scalar($comment['image_id']) ? (string) $comment['image_id'] : '';

    if (! verify_ephemeral_key($post_key, $comment_image_id)) {
        $comment_action = 'reject';
    } elseif (! (bool) $conf['comments_validation'] or is_admin()) { // should the updated comment must be validated
        $comment_action = 'validate'; // one of validate, moderate, reject
    } else {
        $comment_action = 'moderate'; // one of validate, moderate, reject
    }

    // perform more spam check
    $comment_action_result =
      trigger_change(
          'user_comment_check',
          $comment_action,
          array_merge(
              $comment,
              [
                  'author' => $user_username,
              ]
          )
      );
    // handlers of the user_comment_check event contract MUST return a string
    // (validate/moderate/reject); fail closed if a handler misbehaves
    $comment_action = is_string($comment_action_result) ? $comment_action_result : 'reject';

    // website
    if (! empty($comment['website_url'])) {
        $website_url = is_string($comment['website_url']) ? $comment['website_url'] : '';
        $website_url = strip_tags($website_url);
        if (! (bool) preg_match('/^https?/i', $website_url)) {
            $website_url = 'http://' . $website_url;
        }
        $comment['website_url'] = $website_url;
        if (! url_check_format($website_url)) {
            if (! is_array($page['errors'] ?? null)) {
                $page['errors'] = [];
            }
            $page['errors'][] = l10n('Your website URL is invalid');
            $comment_action = 'reject';
        }
    }

    if ($comment_action != 'reject') {
        $user_where_clause = '';
        if (! is_admin()) {
            $user_id_value = $user['id'] ?? null;
            $user_id_str = is_scalar($user_id_value) ? (string) $user_id_value : '';
            $user_where_clause = '   AND author_id = \'' .
    $user_id_str . '\'';
        }

        $comment_content = is_string($comment['content']) ? $comment['content'] : '';
        $comment_website_url = is_string($comment['website_url'] ?? null) ? $comment['website_url'] : '';
        $comment_id_value = is_scalar($comment['comment_id']) ? (string) $comment['comment_id'] : '0';

        $query = '
UPDATE ' . Tables::comments() . '
  SET content = \'' . $comment_content . '\',
      website_url = ' . (! empty($comment_website_url) ? '\'' . $comment_website_url . '\'' : 'NULL') . ',
      validated = \'' . ($comment_action == 'validate' ? 'true' : 'false') . '\',
      validation_date = ' . ($comment_action == 'validate' ? 'NOW()' : 'NULL') . '
  WHERE id = ' . $comment_id_value .
$user_where_clause . '
;';
        $result = pwg_query($query);

        // mail admin and ask to validate the comment
        if ((bool) $result and (bool) $conf['email_admin_on_comment_validation'] and $comment_action == 'moderate') {
            include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';

            $comment_url = get_absolute_root_url() . 'comments.php?comment_id=' . $comment_id_value;

            $keyargs_content = [
                get_l10n_args('Author: %s', stripslashes($user_username)),
                get_l10n_args('Comment: %s', stripslashes($comment_content)),
                get_l10n_args(''),
                get_l10n_args('Manage this user comment: %s', $comment_url),
                get_l10n_args('(!) This comment requires validation'),
            ];

            pwg_mail_notification_admins(
                get_l10n_args('Comment by %s', stripslashes($user_username)),
                $keyargs_content
            );
        }
        // just mail admin
        elseif ((bool) $result) {
            email_admin('edit', [
                'author' => $user['username'],
                'content' => stripslashes($comment_content),
            ]);
        }
    }

    return $comment_action;
}

/**
 * Notifies admins about updated or deleted comment.
 * Only used when no validation is needed, otherwise pwg_mail_notification_admins() is used.
 *
 * @param string $action edit, delete
 * @param array<string, mixed> $comment
 */
function email_admin($action, array $comment): void
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (! in_array($action, ['edit', 'delete'])
        or (($action == 'edit') and ! (bool) $conf['email_admin_on_comment_edition'])
        or (($action == 'delete') and ! (bool) $conf['email_admin_on_comment_deletion'])) {
        return;
    }

    include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';

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
 * @return int|false false if $die_on_error is false and the comment doesn't exist
 */
function get_comment_author_id($comment_id, $die_on_error = true): false|int
{
    $query = '
SELECT
    author_id
  FROM ' . Tables::comments() . '
  WHERE id = ' . $comment_id . '
;';
    $result = pwg_query($query);
    if (pwg_db_num_rows($result) == 0) {
        if ($die_on_error) {
            fatal_error('Unknown comment identifier');
        } else {
            return false;
        }
    }

    $row = pwg_db_fetch_row($result);
    assert($row !== null);
    [$author_id] = $row;

    return is_numeric($author_id) ? (int) $author_id : false;
}

/**
 * Tries to validate a user comment.
 *
 * @param int|int[] $comment_id
 */
function validate_user_comment($comment_id): void
{
    if (is_array($comment_id)) {
        $where_clause = 'id IN(' . implode(',', $comment_id) . ')';
    } else {
        $where_clause = 'id = ' . $comment_id;
    }

    $query = '
UPDATE ' . Tables::comments() . '
  SET validated = \'true\'
    , validation_date = NOW()
  WHERE ' . $where_clause . '
;';
    pwg_query($query);

    invalidate_user_cache_nb_comments();
    trigger_notify('user_comment_validation', $comment_id);
}

/**
 * Clears cache of nb comments for all users
 */
function invalidate_user_cache_nb_comments(): void
{
    /** @var array<string, mixed> $user */
    global $user;

    unset($user['nb_available_comments']);

    $query = '
UPDATE ' . Tables::userCache() . '
  SET nb_available_comments = NULL
;';
    pwg_query($query);
}
