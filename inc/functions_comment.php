<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Exception;
use Piwigo\inc\dblayer\functions_mysqli;

functions_plugins::add_event_handler('user_comment_check', functions_comment::user_comment_check(...));

class functions_comment
{
    /**
     * Does basic check on comment and returns action to perform.
     * This method is called by a trigger_change()
     *
     * @param string $action before check
     * @return string validate, moderate, reject
     */
    public static function user_comment_check(
        string $action,
        array $comment
    ): string {
        global $conf,$user;

        if ($action == 'reject') {
            return $action;
        }

        $my_action = $conf['comment_spam_reject'] ? 'reject' : 'moderate';

        if ($action == $my_action) {
            return $action;
        }

        // we do here only BASIC spam check (plugins can do more)
        if (! functions_user::is_a_guest()) {
            return $action;
        }

        $link_count = preg_match_all(
            '/https?:\/\//',
            $comment['content'],
            $matches
        );

        if (strpos($comment['author'], 'http://') !== false) {
            $link_count++;
        }

        if ($link_count > $conf['comment_spam_max_links']) {
            $_POST['cr'][] = 'links';
            return $my_action;
        }

        return $action;
    }

    /**
     * Tries to insert a user comment and returns action to perform.
     *
     * @param string $key secret key sent back to the browser
     * @param array $infos output array of error messages
     * @return string validate, moderate, reject
     * @throws Exception
     */
    public static function insert_user_comment(
        array &$comm,
        string $key,
        array &$infos
    ): string {
        global $conf, $user;

        $comm = array_merge(
            $comm,
            [
                'ip' => $_SERVER['REMOTE_ADDR'],
                'agent' => $_SERVER['HTTP_USER_AGENT'],
            ]
        );

        $infos = [];

        if (! $conf['comments_validation'] or
            functions_user::is_admin()
        ) {
            $comment_action = 'validate'; //one of validate, moderate, reject
        } else {
            $comment_action = 'moderate'; //one of validate, moderate, reject
        }

        // display author field if the user status is guest or generic
        if (! functions_user::is_classic_user()) {
            if (empty($comm['author'])) {
                if ($conf['comments_author_mandatory']) {
                    $infos[] = functions::l10n('Username is mandatory');
                    $comment_action = 'reject';
                }

                $comm['author'] = 'guest';
            }

            $comm['author_id'] = $conf['guest_id'];
            // if a guest try to use the name of an already existing user, he must be
            // rejected
            if ($comm['author'] != 'guest') {
                $author = addslashes($comm['author']);
                $query = <<<SQL
                    SELECT COUNT(*) AS user_exists
                    FROM users
                    WHERE {$conf['user_fields']['username']} = '{$author}';
                    SQL;
                $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

                if ($row['user_exists'] == 1) {
                    $infos[] = functions::l10n('This login is already used by another user');
                    $comment_action = 'reject';
                }
            }
        } else {
            $comm['author'] = addslashes($user['username']);
            $comm['author_id'] = $user['id'];
        }

        if (empty($comm['content'])) { // empty comment content
            $comment_action = 'reject';
        }

        if (! functions::verify_ephemeral_key($key, $comm['image_id'])) {
            $comment_action = 'reject';
            $_POST['cr'][] = 'key'; // rvelices: I use this outside to see how spam robots work
        }

        // website
        if (! empty($comm['website_url'])) {
            if (! $conf['comments_enable_website']) { // honeypot: if the field is disabled, it should be empty !
                $comment_action = 'reject';
                $_POST['cr'][] = 'website_url';
            } else {
                $comm['website_url'] = strip_tags($comm['website_url']);

                if (! preg_match('/^https?/i', $comm['website_url'])) {
                    $comm['website_url'] = 'http://' . $comm['website_url'];
                }

                if (! functions::url_check_format($comm['website_url'])) {
                    $infos[] = functions::l10n('Your website URL is invalid');
                    $comment_action = 'reject';
                }
            }
        }

        // email
        if (empty($comm['email'])) {
            if (! empty($user['email'])) {
                $comm['email'] = $user['email'];
            } elseif ($conf['comments_email_mandatory']) {
                $infos[] = functions::l10n('Email address is missing. Please specify an email address.');
                $comment_action = 'reject';
            }
        } elseif (! functions::email_check_format($comm['email'])) {
            $infos[] = functions::l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
            $comment_action = 'reject';
        }

        // anonymous id = ip address
        $ip_components = explode('.', $comm['ip']);

        if (count($ip_components) > 3) {
            array_pop($ip_components);
        }

        $anonymous_id = implode('.', $ip_components);

        if ($comment_action != 'reject' and
            $conf['anti-flood_time'] > 0 and
            ! functions_user::is_admin()
        ) { // anti-flood system
            $reference_date = functions_mysqli::pwg_db_get_flood_period_expression($conf['anti-flood_time']);

            $query = <<<SQL
                SELECT COUNT(1) FROM comments
                WHERE date > '{$reference_date}'
                    AND author_id = {$comm['author_id']}

                SQL;

            if (! functions_user::is_classic_user()) {
                $query .= <<<SQL
                    AND anonymous_id LIKE "{$anonymous_id}.%"

                    SQL;
            }

            $query = trim($query) . ';';
            list($counter) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            if ($counter > 0) {
                $infos[] = functions::l10n('Anti-flood system : please wait for a moment before trying to post another comment');
                $comment_action = 'reject';
                $_POST['cr'][] = 'flood_time';
            }
        }

        // perform more spam check
        $comment_action = functions_plugins::trigger_change(
            'user_comment_check',
            $comment_action,
            $comm
        );

        if ($comment_action != 'reject') {
            $validated = $comment_action == 'validate' ? 'true' : 'false';
            $validation_date = $comment_action == 'validate' ? 'NOW()' : 'NULL';
            $website_url = ! empty($comm['website_url']) ? "'{$comm['website_url']}'" : 'NULL';
            $email = ! empty($comm['email']) ? "'{$comm['email']}'" : 'NULL';
            $query = <<<SQL
                INSERT INTO comments
                    (author, author_id, anonymous_id, content, date, validated, validation_date, image_id, website_url, email)
                VALUES
                (
                    '{$comm['author']}', {$comm['author_id']}, '{$comm['ip']}', '{$comm['content']}', NOW(),
                    '{$validated}', {$validation_date}, {$comm['image_id']}, {$website_url}, {$email}
                );
                SQL;
            functions_mysqli::pwg_query($query);
            $comm['id'] = functions_mysqli::pwg_db_insert_id('comments');

            self::invalidate_user_cache_nb_comments();

            if (($conf['email_admin_on_comment'] && $comment_action == 'validate') or
                ($conf['email_admin_on_comment_validation'] and $comment_action == 'moderate')
            ) {
                include_once(PHPWG_ROOT_PATH . 'inc/functions_mail.php');

                $comment_url = functions_url::get_absolute_root_url() . 'comments.php?comment_id=' . $comm['id'];

                $keyargs_content = [
                    functions::get_l10n_args('Author: %s', stripslashes($comm['author'])),
                    functions::get_l10n_args('Email: %s', stripslashes($comm['email'])),
                    functions::get_l10n_args('Comment: %s', stripslashes($comm['content'])),
                    functions::get_l10n_args(''),
                    functions::get_l10n_args('Manage this user comment: %s', $comment_url),
                ];

                if ($comment_action == 'moderate') {
                    $keyargs_content[] = functions::get_l10n_args('(!) This comment requires validation');
                }

                functions_mail::pwg_mail_notification_admins(
                    functions::get_l10n_args('Comment by %s', stripslashes($comm['author'])),
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
     * @param int|array<int> $comment_id
     * @return bool false if nothing deleted
     * @throws Exception
     */
    public static function delete_user_comment(
        array|int $comment_id
    ): bool {
        $user_where_clause = '';

        if (! functions_user::is_admin()) {
            $user_where_clause = <<<SQL
                AND author_id = '{$GLOBALS['user']['id']}'

                SQL;
        }

        if (is_array($comment_id)) {
            $ids_list = implode(', ', $comment_id);
            $where_clause = <<<SQL
                id IN ({$ids_list})

                SQL;
        } else {
            $where_clause = <<<SQL
                id = {$comment_id}

                SQL;
        }

        $query = <<<SQL
            DELETE FROM comments
            WHERE {$where_clause}
                {$user_where_clause};
            SQL;
        functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_changes()) {
            self::invalidate_user_cache_nb_comments();

            self::email_admin(
                'delete',
                [
                    'author' => $GLOBALS['user']['username'],
                    'comment_id' => $comment_id,
                ]
            );
            functions_plugins::trigger_notify('user_comment_deletion', $comment_id);

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
     * @throws Exception
     */
    public static function update_user_comment(
        array $comment,
        string $post_key
    ): string {
        global $conf, $page;

        $comment_action = 'validate';

        if (! functions::verify_ephemeral_key($post_key, $comment['image_id'])) {
            $comment_action = 'reject';
        } elseif (! $conf['comments_validation'] or
                  functions_user::is_admin()
        ) { // should the updated comment must be validated
            $comment_action = 'validate'; //one of validate, moderate, reject
        } else {
            $comment_action = 'moderate'; //one of validate, moderate, reject
        }

        // perform more spam check
        $comment_action =
          functions_plugins::trigger_change(
              'user_comment_check',
              $comment_action,
              array_merge(
                  $comment,
                  [
                      'author' => $GLOBALS['user']['username'],
                  ]
              )
          );

        // website
        if (! empty($comment['website_url'])) {
            $comm['website_url'] = strip_tags($comm['website_url']);

            if (! preg_match('/^https?/i', $comment['website_url'])) {
                $comment['website_url'] = 'http://' . $comment['website_url'];
            }

            if (! functions::url_check_format($comment['website_url'])) {
                $page['errors'][] = functions::l10n('Your website URL is invalid');
                $comment_action = 'reject';
            }
        }

        if ($comment_action != 'reject') {
            $user_where_clause = '';

            if (! functions_user::is_admin()) {
                $user_where_clause = " AND author_id = '{$GLOBALS['user']['id']}'";
            }

            $website_url = ! empty($comment['website_url']) ? "'{$comment['website_url']}'" : 'NULL';
            $validated = $comment_action == 'validate' ? 'true' : 'false';
            $validation_date = $comment_action == 'validate' ? 'NOW()' : 'NULL';
            $query = <<<SQL
                UPDATE comments
                SET content = '{$comment['content']}',
                    website_url = {$website_url},
                    validated = '{$validated}',
                    validation_date = {$validation_date}
                WHERE id = {$comment['comment_id']}
                    {$user_where_clause};
                SQL;
            $result = functions_mysqli::pwg_query($query);

            // mail admin and ask to validate the comment
            if ($result and
                $conf['email_admin_on_comment_validation'] and
                $comment_action == 'moderate'
            ) {
                include_once(PHPWG_ROOT_PATH . 'inc/functions_mail.php');

                $comment_url = functions_url::get_absolute_root_url() . 'comments.php?comment_id=' . $comment['comment_id'];

                $keyargs_content = [
                    functions::get_l10n_args('Author: %s', stripslashes($GLOBALS['user']['username'])),
                    functions::get_l10n_args('Comment: %s', stripslashes($comment['content'])),
                    functions::get_l10n_args(''),
                    functions::get_l10n_args('Manage this user comment: %s', $comment_url),
                    functions::get_l10n_args('(!) This comment requires validation'),
                ];

                functions_mail::pwg_mail_notification_admins(
                    functions::get_l10n_args('Comment by %s', stripslashes($GLOBALS['user']['username'])),
                    $keyargs_content
                );
            }
            // just mail admin
            elseif ($result) {
                self::email_admin('edit', [
                    'author' => $GLOBALS['user']['username'],
                    'content' => stripslashes($comment['content']),
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
     * @throws Exception
     */
    public static function email_admin(
        string $action,
        array $comment
    ): void {
        global $conf;

        if (! in_array($action, ['edit', 'delete']) or
          ($action == 'edit' and ! $conf['email_admin_on_comment_edition']) or
          ($action == 'delete' and ! $conf['email_admin_on_comment_deletion'])
        ) {
            return;
        }

        include_once(PHPWG_ROOT_PATH . 'inc/functions_mail.php');

        $keyargs_content = [
            functions::get_l10n_args('Author: %s', $comment['author']),
        ];

        if ($action == 'delete') {
            $keyargs_content[] = functions::get_l10n_args('This author removed the comment with id %d', $comment['comment_id']);
        } else {
            $keyargs_content[] = functions::get_l10n_args('This author modified following comment:');
            $keyargs_content[] = functions::get_l10n_args('Comment: %s', $comment['content']);
        }

        functions_mail::pwg_mail_notification_admins(
            functions::get_l10n_args('Comment by %s', $comment['author']),
            $keyargs_content
        );
    }

    /**
     * Returns the author id of a comment
     */
    public static function get_comment_author_id(
        int $comment_id,
        bool $die_on_error = true
    ): false|int {
        $query = <<<SQL
            SELECT author_id
            FROM comments
            WHERE id = {$comment_id};
            SQL;
        $result = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($result) == 0) {
            if ($die_on_error) {
                functions_html::fatal_error('Unknown comment identifier');
            } else {
                return false;
            }
        }

        list($author_id) = functions_mysqli::pwg_db_fetch_row($result);

        return $author_id;
    }

    /**
     * Tries to validate a user comment.
     *
     * @param int|array<int> $comment_id
     */
    public static function validate_user_comment(
        array|int $comment_id
    ): void {
        if (is_array($comment_id)) {
            $where_clause = 'id IN (' . implode(', ', $comment_id) . ')';
        } else {
            $where_clause = 'id = ' . $comment_id;
        }

        $query = <<<SQL
            UPDATE comments
            SET validated = 'true',
                validation_date = NOW()
            WHERE {$where_clause};
            SQL;
        functions_mysqli::pwg_query($query);

        self::invalidate_user_cache_nb_comments();
        functions_plugins::trigger_notify('user_comment_validation', $comment_id);
    }

    /**
     * Clears cache of nb comments for all users
     */
    public static function invalidate_user_cache_nb_comments(): void
    {
        global $user;

        unset($user['nb_available_comments']);

        $query = <<<SQL
            UPDATE user_cache
            SET nb_available_comments = NULL;
            SQL;
        functions_mysqli::pwg_query($query);
    }
}
