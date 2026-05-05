<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Comment\CommentService;
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
function user_comment_check(string $action, array $comment): string
{
    return ServiceLocator::get(CommentService::class)->userCommentCheck($action, $comment);
}

/**
 * @param array<string,mixed> $comm
 * @param string[]            $infos
 * @return string validate, moderate, or reject
 */
function insert_user_comment(array &$comm, string $key, array &$infos): string
{
    return ServiceLocator::get(CommentService::class)->insertUserComment($comm, $key, $infos);
}

/**
 * @param int|int[] $comment_id
 */
function delete_user_comment(int|array $comment_id): bool
{
    return ServiceLocator::get(CommentService::class)->deleteUserComment($comment_id);
}

/**
 * @param array<string,mixed> $comment
 * @return string validate, moderate, or reject
 */
function update_user_comment(array $comment, string $post_key): string
{
    return ServiceLocator::get(CommentService::class)->updateUserComment($comment, $post_key);
}

/** @param array<string,mixed> $comment */
function email_admin(string $action, array $comment): void
{
    ServiceLocator::get(CommentService::class)->emailAdmin($action, $comment);
}

function get_comment_author_id(int $comment_id, bool $die_on_error = true): int|false
{
    return ServiceLocator::get(CommentService::class)->getCommentAuthorId($comment_id, $die_on_error);
}

/** @param int|int[] $comment_id */
function validate_user_comment(int|array $comment_id): void
{
    ServiceLocator::get(CommentService::class)->validateUserComment($comment_id);
}

function invalidate_user_cache_nb_comments(): void
{
    ServiceLocator::get(CommentService::class)->invalidateUserCacheNbComments();
}
