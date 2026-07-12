<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Db\DbConnection;

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
    return new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService())
        ->checkForSpam($action, $comment);
}

/**
 * Tries to insert a user comment and returns action to perform.
 *
 * @param array<string, mixed> $comm
 * @param string $key secret key sent back to the browser
 * @param list<string> $infos output array of error messages
 * @return string validate, moderate, reject
 */
function insert_user_comment(&$comm, $key, &$infos): string
{
    return new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService())
        ->insertComment($comm, $key, $infos);
}

/**
 * Tries to delete a (or more) user comment.
 *    only admin can delete all comments
 *    other users can delete their own comments
 *
 * @param int|array<int, int> $comment_id
 * @return bool false if nothing deleted
 */
function delete_user_comment($comment_id): bool
{
    return new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService())
        ->deleteComment($comment_id);
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
    return new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService())
        ->updateComment($comment, $post_key);
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
    new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService())
        ->emailAdmin($action, $comment);
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
    return new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService())
        ->getCommentAuthorId($comment_id, $die_on_error);
}

/**
 * Tries to validate a user comment.
 *
 * @param int|array<int, int> $comment_id
 */
function validate_user_comment($comment_id): void
{
    new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService())
        ->validateComment($comment_id);
}

/**
 * Clears cache of nb comments for all users
 */
function invalidate_user_cache_nb_comments(): void
{
    new CommentService(new CommentRepository(DbConnection::build()), new EphemeralKeyService())
        ->invalidateNbCommentsCache();
}
