<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;

final readonly class CommentService
{
    public function __construct(
        private CommentRepository $repo,
    ) {
    }

    /** @param array<string,mixed> $comment */
    public function userCommentCheck(string $action, array $comment): string
    {
        if ($action == 'reject') {
            return $action;
        }

        $myAction = Config::commentSpamReject() ? 'reject' : 'moderate';

        if ($action == $myAction) {
            return $action;
        }

        if (!PermissionService::get()->isAGuest()) {
            return $action;
        }

        $linkCount = preg_match_all(
            '/https?:\/\//',
            is_scalar($comment['content']) ? (string) $comment['content'] : '',
            $matches
        ) ?: 0;

        if (str_contains(is_scalar($comment['author']) ? (string) $comment['author'] : '', 'http://')) {
            $linkCount++;
        }

        if ($linkCount > Config::commentSpamMaxLinks()) {
            if (!is_array($_POST['cr'] ?? null)) {
                $_POST['cr'] = [];
            }
            $_POST['cr'][] = 'links';
            return $myAction;
        }
        return $action;
    }

    /**
     * Tries to insert a user comment and returns the action to perform.
     *
     * @param array<string,mixed> $comm
     * @param string[]            $infos
     * @return string validate, moderate, or reject
     */
    public function insertUserComment(array &$comm, string $key, array &$infos): string
    {
        $comm = array_merge($comm, [
            'ip'    => $_SERVER['REMOTE_ADDR'],
            'agent' => $_SERVER['HTTP_USER_AGENT'],
        ]);

        $infos = [];
        if (!Config::commentsValidation() or PermissionService::get()->isAdmin()) {
            $commentAction = 'validate';
        } else {
            $commentAction = 'moderate';
        }

        if (!PermissionService::get()->isClassicUser()) {
            if (empty($comm['author'])) {
                if (Config::commentsAuthorMandatory()) {
                    $infos[]       = l10n('Username is mandatory');
                    $commentAction = 'reject';
                }
                $comm['author'] = 'guest';
            }
            $comm['author_id'] = Config::guestId();
            if ($comm['author'] != 'guest') {
                $usernameField = Config::userFields()['username'];
                $authorStr     = is_scalar($comm['author']) ? (string) $comm['author'] : '';
                $count = $this->repo->countByUsername($usernameField, $authorStr);
                if ($count > 0) {
                    $infos[]       = l10n('This login is already used by another user');
                    $commentAction = 'reject';
                }
            }
        } else {
            $currentUser        = CurrentUser::get();
            $comm['author']     = addslashes($currentUser->username);
            $comm['author_id']  = $currentUser->id;
        }

        if (empty($comm['content'])) {
            $commentAction = 'reject';
        }

        if (!verify_ephemeral_key($key, is_scalar($comm['image_id']) ? (string) $comm['image_id'] : '')) {
            $commentAction = 'reject';
            if (!is_array($_POST['cr'] ?? null)) {
                $_POST['cr'] = [];
            }
            $_POST['cr'][] = 'key';
        }

        if (!empty($comm['website_url'])) {
            if (!Config::commentsEnableWebsite()) {
                $commentAction = 'reject';
                if (!is_array($_POST['cr'] ?? null)) {
                    $_POST['cr'] = [];
                }
                $_POST['cr'][] = 'website_url';
            } else {
                $comm['website_url'] = strip_tags(is_scalar($comm['website_url']) ? (string) $comm['website_url'] : '');
                if (!preg_match('/^https?/i', $comm['website_url'])) {
                    $comm['website_url'] = 'http://' . $comm['website_url'];
                }
                if (!url_check_format($comm['website_url'])) {
                    $infos[]       = l10n('Your website URL is invalid');
                    $commentAction = 'reject';
                }
            }
        }

        if (empty($comm['email'])) {
            $currentUserEmail = CurrentUser::get()->email;
            if ($currentUserEmail !== '') {
                $comm['email'] = $currentUserEmail;
            } elseif (Config::commentsEmailMandatory()) {
                $infos[]       = l10n('Email address is missing. Please specify an email address.');
                $commentAction = 'reject';
            }
        } elseif (!email_check_format(is_scalar($comm['email']) ? (string) $comm['email'] : '')) {
            $infos[]       = l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
            $commentAction = 'reject';
        }

        $ipComponents = explode('.', is_scalar($comm['ip']) ? (string) $comm['ip'] : '');
        if (count($ipComponents) > 3) {
            array_pop($ipComponents);
        }
        $anonymousId = implode('.', $ipComponents);

        if ($commentAction != 'reject' and Config::antiFloodTime() > 0 and !PermissionService::get()->isAdmin()) {
            $counter = $this->repo->countRecentByAuthor(
                (int) $comm['author_id'],
                Config::antiFloodTime(),
                PermissionService::get()->isClassicUser() ? '' : $anonymousId
            );
            if ($counter > 0) {
                $infos[]       = l10n('Anti-flood system : please wait for a moment before trying to post another comment');
                $commentAction = 'reject';
                if (!is_array($_POST['cr'] ?? null)) {
                    $_POST['cr'] = [];
                }
                $_POST['cr'][] = 'flood_time';
            }
        }

        $commentAction = (string) trigger_change('user_comment_check', $commentAction, $comm);

        if ($commentAction != 'reject') {
            $comm['id'] = $this->repo->insert([
                'author'       => is_scalar($comm['author']) ? (string) $comm['author'] : '',
                'author_id'    => (int) $comm['author_id'],
                'anonymous_id' => is_scalar($comm['ip']) ? (string) $comm['ip'] : '',
                'content'      => is_scalar($comm['content']) ? (string) $comm['content'] : '',
                'validated'    => $commentAction === 'validate',
                'image_id'     => is_scalar($comm['image_id']) ? (int)    $comm['image_id'] : 0,
                'website_url'  => !empty($comm['website_url']) ? (is_scalar($comm['website_url']) ? (string) $comm['website_url'] : null) : null,
                'email'        => !empty($comm['email']) ? (is_scalar($comm['email']) ? (string) $comm['email'] : null) : null,
            ]);

            $this->invalidateUserCacheNbComments();

            if ((Config::emailAdminOnComment() && 'validate' == $commentAction)
                or (Config::emailAdminOnCommentValidation() and 'moderate' == $commentAction)) {

                $commentUrl = add_url_params(ServiceLocator::get(UrlGenerator::class)->comments(), ['comment_id' => (string) $comm['id']]);

                $keyargsContent = [
                    get_l10n_args('Author: %s', stripslashes(is_scalar($comm['author']) ? (string) $comm['author'] : '')),
                    get_l10n_args('Email: %s', stripslashes(is_scalar($comm['email']) ? (string) $comm['email'] : '')),
                    get_l10n_args('Comment: %s', stripslashes(is_scalar($comm['content']) ? (string) $comm['content'] : '')),
                    get_l10n_args(''),
                    get_l10n_args('Manage this user comment: %s', $commentUrl),
                ];

                if ('moderate' == $commentAction) {
                    $keyargsContent[] = get_l10n_args('(!) This comment requires validation');
                }

                pwg_mail_notification_admins(
                    get_l10n_args('Comment by %s', stripslashes(is_scalar($comm['author']) ? (string) $comm['author'] : '')),
                    $keyargsContent
                );
            }
        }

        return $commentAction;
    }

    /**
     * Tries to delete a (or more) user comment.
     * Only admin can delete any comment; other users can only delete their own.
     *
     * @param int|int[] $commentId
     */
    public function deleteUserComment(int|array $commentId): bool
    {
        $globalUser = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $authorId   = PermissionService::get()->isAdmin() ? null : (is_numeric($globalUser['id'] ?? null) ? (int) $globalUser['id'] : 0);
        $affected   = $this->repo->delete($commentId, $authorId);

        if ($affected > 0) {
            $this->invalidateUserCacheNbComments();

            $this->emailAdmin('delete', [
                'author'     => is_scalar($globalUser['username'] ?? null) ? (string) $globalUser['username'] : '',
                'comment_id' => $commentId,
            ]);
            trigger_notify('user_comment_deletion', $commentId);

            return true;
        }

        return false;
    }

    /**
     * Tries to update a user comment.
     *
     * @param array<string,mixed> $comment
     * @return string validate, moderate, or reject
     */
    public function updateUserComment(array $comment, string $postKey): string
    {
        $commentAction = 'validate';

        if (!verify_ephemeral_key($postKey, is_scalar($comment['image_id']) ? (string) $comment['image_id'] : '')) {
            $commentAction = 'reject';
        } elseif (!Config::commentsValidation() or PermissionService::get()->isAdmin()) {
            $commentAction = 'validate';
        } else {
            $commentAction = 'moderate';
        }

        $globalUser    = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $commentAction = (string) trigger_change(
            'user_comment_check',
            $commentAction,
            array_merge($comment, ['author' => is_scalar($globalUser['username'] ?? null) ? (string) $globalUser['username'] : ''])
        );

        if (!empty($comment['website_url'])) {
            $wUrl              = is_scalar($comment['website_url']) ? (string) $comment['website_url'] : '';
            $comment['website_url'] = strip_tags($wUrl);
            if (!preg_match('/^https?/i', $comment['website_url'])) {
                $comment['website_url'] = 'http://' . $comment['website_url'];
            }
            if (!url_check_format($comment['website_url'])) {
                PageState::current()->addError(l10n('Your website URL is invalid'));
                $commentAction = 'reject';
            }
        }

        if ($commentAction != 'reject') {
            $updateAuthorId = PermissionService::get()->isAdmin() ? null : (is_numeric($globalUser['id'] ?? null) ? (int) $globalUser['id'] : null);
            $result = $this->repo->update(
                (int) (is_scalar($comment['comment_id']) ? $comment['comment_id'] : 0),
                [
                    'content'     => is_scalar($comment['content']) ? (string) $comment['content'] : '',
                    'website_url' => !empty($comment['website_url']) ? (is_scalar($comment['website_url']) ? (string) $comment['website_url'] : null) : null,
                    'validated'   => $commentAction === 'validate',
                ],
                $updateAuthorId
            );

            if ($result and Config::emailAdminOnCommentValidation() and 'moderate' == $commentAction) {

                $commentUrl     = add_url_params(ServiceLocator::get(UrlGenerator::class)->comments(), ['comment_id' => is_scalar($comment['comment_id']) ? (string) $comment['comment_id'] : '0']);
                $keyargsContent = [
                    get_l10n_args('Author: %s', stripslashes(is_scalar($globalUser['username'] ?? null) ? (string) $globalUser['username'] : '')),
                    get_l10n_args('Comment: %s', stripslashes(is_scalar($comment['content']) ? (string) $comment['content'] : '')),
                    get_l10n_args(''),
                    get_l10n_args('Manage this user comment: %s', $commentUrl),
                    get_l10n_args('(!) This comment requires validation'),
                ];

                pwg_mail_notification_admins(
                    get_l10n_args('Comment by %s', stripslashes(is_scalar($globalUser['username'] ?? null) ? (string) $globalUser['username'] : '')),
                    $keyargsContent
                );
            } elseif ($result) {
                $this->emailAdmin('edit', [
                    'author'  => is_scalar($globalUser['username'] ?? null) ? (string) $globalUser['username'] : '',
                    'content' => stripslashes(is_scalar($comment['content']) ? (string) $comment['content'] : ''),
                ]);
            }
        }

        return $commentAction;
    }

    /**
     * Notifies admins about an updated or deleted comment (non-validation path).
     *
     * @param array<string,mixed> $comment
     */
    public function emailAdmin(string $action, array $comment): void
    {
        if (!in_array($action, ['edit', 'delete'])
            or (($action == 'edit')   and !Config::emailAdminOnCommentEdition())
            or (($action == 'delete') and !Config::emailAdminOnCommentDeletion())) {
            return;
        }


        $keyargsContent = [get_l10n_args('Author: %s', $comment['author'])];

        if ($action == 'delete') {
            $keyargsContent[] = get_l10n_args('This author removed the comment with id %d', $comment['comment_id']);
        } else {
            $keyargsContent[] = get_l10n_args('This author modified following comment:');
            $keyargsContent[] = get_l10n_args('Comment: %s', $comment['content']);
        }

        pwg_mail_notification_admins(
            get_l10n_args('Comment by %s', $comment['author']),
            $keyargsContent
        );
    }

    public function getCommentAuthorId(int $commentId, bool $dieOnError = true): int|false
    {
        $authorId = $this->repo->getAuthorId($commentId);

        if ($authorId === null) {
            if ($dieOnError) {
                fatal_error('Unknown comment identifier');
            } else {
                return false;
            }
        }

        return $authorId;
    }

    /** @param int|int[] $commentId */
    public function validateUserComment(int|array $commentId): void
    {
        $this->repo->setValidated($commentId);
        $this->invalidateUserCacheNbComments();
        trigger_notify('user_comment_validation', $commentId);
    }

    public function invalidateUserCacheNbComments(): void
    {
        if (isset($GLOBALS['user']) && is_array($GLOBALS['user'])) {
            unset($GLOBALS['user']['nb_available_comments']);
        }

        $this->repo->clearNbAvailableCommentsCache();
    }
}
