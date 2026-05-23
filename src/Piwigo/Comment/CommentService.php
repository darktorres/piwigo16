<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Cache\RequestCache;
use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Event\User\UserCommentCheck;
use Piwigo\Event\User\UserCommentDeletion;
use Piwigo\Event\User\UserCommentValidation;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\LangService;
use Piwigo\Mail\MailService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\User;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class CommentService
{
    public function __construct(
        private CommentRepository $repo,
        private LangService $langService,
        private MailService $mailService,
        private PermissionService $permissionService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private EphemeralKeyService $ephemeralKeyService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Count comments visible to the current user, caching the result per
     * request and persisting it to `user_cache.nb_available_comments`. Was
     * `Util::getNbAvailableComments()` before Phase 5.
     */
    public function getNbAvailable(): int
    {
        $cached = RequestCache::remember('user', 'nb_available_comments', function (): int {
            $where = [];
            if (!$this->permissionService->isAdmin()) {
                $where[] = 'validated=1';
            }
            $perm = $this->permissionService->getSqlConditionFandF(
                ['forbidden_categories' => 'category_id', 'forbidden_images' => 'ic.image_id'],
                '',
                true
            );
            $where[] = $perm->where;
            $nb = $this->repo->countAvailableCommentsForUser($where, $perm->params, $perm->types);
            $this->repo->setNbAvailableCommentsCache(CurrentUser::get()->id, $nb);
            return $nb;
        });
        return $cached;
    }

    /** @param array<string,mixed> $comment */
    public function userCommentCheck(CommentModerationAction $action, array $comment): CommentModerationAction
    {
        if ($action === CommentModerationAction::Reject) {
            return $action;
        }

        $myAction = Config::commentSpamReject() ? CommentModerationAction::Reject : CommentModerationAction::Moderate;

        if ($action === $myAction) {
            return $action;
        }

        if (!$this->permissionService->isAGuest()) {
            return $action;
        }

        $linkCountResult = preg_match_all(
            '/https?:\/\//',
            is_string($comment['content'] ?? null) ? $comment['content'] : '',
            $matches
        );
        $linkCount = $linkCountResult !== false ? $linkCountResult : 0;

        if (str_contains(is_string($comment['author'] ?? null) ? $comment['author'] : '', 'http://')) {
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
     */
    public function insertUserComment(array &$comm, string $key, array &$infos): CommentModerationAction
    {
        $comm = array_merge($comm, [
            'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
            'agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);

        $infos = [];
        if (!Config::commentsValidation() or $this->permissionService->isAdmin()) {
            $commentAction = CommentModerationAction::Validate;
        } else {
            $commentAction = CommentModerationAction::Moderate;
        }

        if (!$this->permissionService->isClassicUser()) {
            if (empty($comm['author'])) {
                if (Config::commentsAuthorMandatory()) {
                    $infos[]       = Lang::t('Username is mandatory');
                    $commentAction = CommentModerationAction::Reject;
                }
                $comm['author'] = 'guest';
            }
            $comm['author_id'] = Config::guestId();
            if ($comm['author'] != 'guest') {
                $usernameField = Config::userFields()->username;
                $authorStr     = is_string($comm['author']) ? $comm['author'] : '';
                $count = $this->repo->countByUsername($usernameField, $authorStr);
                if ($count > 0) {
                    $infos[]       = Lang::t('This login is already used by another user');
                    $commentAction = CommentModerationAction::Reject;
                }
            }
        } else {
            $currentUser        = CurrentUser::get();
            $comm['author']     = addslashes($currentUser->username);
            $comm['author_id']  = $currentUser->id;
        }

        if (empty($comm['content'])) {
            $commentAction = CommentModerationAction::Reject;
        }

        $commImageIdRaw = $comm['image_id'] ?? null;
        if (!$this->ephemeralKeyService->verify($key, is_string($commImageIdRaw) ? $commImageIdRaw : '')) {
            $commentAction = CommentModerationAction::Reject;
            if (!is_array($_POST['cr'] ?? null)) {
                $_POST['cr'] = [];
            }
            $_POST['cr'][] = 'key';
        }

        if (!empty($comm['website_url'])) {
            if (!Config::commentsEnableWebsite()) {
                $commentAction = CommentModerationAction::Reject;
                if (!is_array($_POST['cr'] ?? null)) {
                    $_POST['cr'] = [];
                }
                $_POST['cr'][] = 'website_url';
            } else {
                $comm['website_url'] = strip_tags(is_string($comm['website_url']) ? $comm['website_url'] : '');
                if (!preg_match('/^https?/i', $comm['website_url'])) {
                    $comm['website_url'] = 'http://' . $comm['website_url'];
                }
                if (!StringUtil::urlCheckFormat($comm['website_url'])) {
                    $infos[]       = Lang::t('Your website URL is invalid');
                    $commentAction = CommentModerationAction::Reject;
                }
            }
        }

        if (empty($comm['email'])) {
            $currentUserEmail = CurrentUser::get()->email;
            if ($currentUserEmail !== '') {
                $comm['email'] = $currentUserEmail;
            } elseif (Config::commentsEmailMandatory()) {
                $infos[]       = Lang::t('Email address is missing. Please specify an email address.');
                $commentAction = CommentModerationAction::Reject;
            }
        } elseif (!StringUtil::emailCheckFormat(is_string($comm['email']) ? $comm['email'] : '')) {
            $infos[]       = Lang::t('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
            $commentAction = CommentModerationAction::Reject;
        }

        // $comm['ip'] was set above from $_SERVER['REMOTE_ADDR'] ?? ''
        // (line 120). PHPStan sees `$comm` as array<string, mixed> so the
        // is_string narrow stays; Psalm sees `string` and treats the
        // narrow as redundant — bridge with a single suppress to keep
        // both tools happy without weakening the runtime fallback.
        /** @psalm-suppress RedundantCondition,TypeDoesNotContainType */
        $rawCommIp = is_string($comm['ip']) ? $comm['ip'] : '';
        $ipComponents = explode('.', $rawCommIp);
        if (count($ipComponents) > 3) {
            array_pop($ipComponents);
        }
        $anonymousId = implode('.', $ipComponents);

        if ($commentAction !== CommentModerationAction::Reject and Config::antiFloodTime() > 0 and !$this->permissionService->isAdmin()) {
            $counter = $this->repo->countRecentByAuthor(
                $comm['author_id'],
                Config::antiFloodTime(),
                $this->permissionService->isClassicUser() ? '' : $anonymousId
            );
            if ($counter > 0) {
                $infos[]       = Lang::t('Anti-flood system : please wait for a moment before trying to post another comment');
                $commentAction = CommentModerationAction::Reject;
                if (!is_array($_POST['cr'] ?? null)) {
                    $_POST['cr'] = [];
                }
                $_POST['cr'][] = 'flood_time';
            }
        }

        $checkEvent = new UserCommentCheck($commentAction, $comm);
        $this->dispatcher->dispatch($checkEvent);
        $commentAction = $checkEvent->commentAction;

        if ($commentAction !== CommentModerationAction::Reject) {
            $commAuthorRaw  = $comm['author'] ?? null;
            // PHPStan sees `ip` as always defined on $comm (set on
            // line 120 above), so it flags the defensive ?? null as
            // dead. Psalm sees the offset as optional. Keep the
            // ?? null until row shape narrowing converges.
            /** @phpstan-ignore-next-line nullCoalesce.offset */
            $commIpRaw      = $comm['ip'] ?? null;
            $commContentRaw = $comm['content'] ?? null;
            $commImgIdRaw   = $comm['image_id'] ?? null;
            $commWebsiteRaw = $comm['website_url'] ?? null;
            $commEmailRaw   = $comm['email'] ?? null;
            $comm['id'] = $this->repo->insert(new NewCommentData(
                author:      is_string($commAuthorRaw) ? $commAuthorRaw : '',
                authorId:    $comm['author_id'],
                anonymousId: is_string($commIpRaw) ? $commIpRaw : '',
                content:     is_string($commContentRaw) ? $commContentRaw : '',
                validated:   $commentAction === CommentModerationAction::Validate,
                imageId:     is_scalar($commImgIdRaw) ? (int) $commImgIdRaw : 0,
                websiteUrl:  (is_string($commWebsiteRaw) && $commWebsiteRaw !== '') ? $commWebsiteRaw : null,
                email:       (is_string($commEmailRaw) && $commEmailRaw !== '') ? $commEmailRaw : null,
            ));

            $this->invalidateUserCacheNbComments();

            if ((Config::emailAdminOnComment() && CommentModerationAction::Validate === $commentAction)
                or (Config::emailAdminOnCommentValidation() and CommentModerationAction::Moderate === $commentAction)) {

                $commentUrl = $this->urlService->addUrlParams($this->urlGenerator->comments(), ['comment_id' => (string) $comm['id']]);

                $commAuthorStr  = is_string($commAuthorRaw) ? $commAuthorRaw : '';
                $commEmailStr   = is_string($commEmailRaw) ? $commEmailRaw : '';
                $commContentStr = is_string($commContentRaw) ? $commContentRaw : '';
                $keyargsContent = [
                    $this->langService->getL10nArgs('Author: %s', stripslashes($commAuthorStr)),
                    $this->langService->getL10nArgs('Email: %s', stripslashes($commEmailStr)),
                    $this->langService->getL10nArgs('Comment: %s', stripslashes($commContentStr)),
                    $this->langService->getL10nArgs(''),
                    $this->langService->getL10nArgs('Manage this user comment: %s', $commentUrl),
                ];

                if (CommentModerationAction::Moderate === $commentAction) {
                    $keyargsContent[] = $this->langService->getL10nArgs('(!) This comment requires validation');
                }

                $this->mailService->pwgMailNotificationAdmins(
                    $this->langService->getL10nArgs('Comment by %s', stripslashes($commAuthorStr)),
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
        $globalUser = CurrentUser::isInitialized() ? CurrentUser::get()->rawAttributes : [];
        $authorId   = $this->permissionService->isAdmin() ? null : (is_numeric($globalUser['id'] ?? null) ? (int) $globalUser['id'] : 0);
        $affected   = $this->repo->delete($commentId, $authorId);

        if ($affected > 0) {
            $this->invalidateUserCacheNbComments();

            $this->emailAdmin(CommentManagementAction::Delete, [
                'author'     => is_string($globalUser['username'] ?? null) ? $globalUser['username'] : '',
                'comment_id' => $commentId,
            ]);
            $this->dispatcher->dispatch(new UserCommentDeletion($commentId));

            return true;
        }

        return false;
    }

    /**
     * Tries to update a user comment.
     *
     * @param array<string,mixed> $comment
     */
    public function updateUserComment(array $comment, string $postKey): CommentModerationAction
    {
        if (!$this->ephemeralKeyService->verify($postKey, is_scalar($comment['image_id'] ?? null) ? (string) $comment['image_id'] : '')) {
            $commentAction = CommentModerationAction::Reject;
        } elseif (!Config::commentsValidation() or $this->permissionService->isAdmin()) {
            $commentAction = CommentModerationAction::Validate;
        } else {
            $commentAction = CommentModerationAction::Moderate;
        }

        $globalUser = CurrentUser::isInitialized() ? CurrentUser::get()->rawAttributes : [];
        $checkEvent = new UserCommentCheck(
            $commentAction,
            array_merge($comment, ['author' => is_string($globalUser['username'] ?? null) ? $globalUser['username'] : ''])
        );
        $this->dispatcher->dispatch($checkEvent);
        $commentAction = $checkEvent->commentAction;

        if (!empty($comment['website_url'])) {
            $wUrl              = is_string($comment['website_url']) ? $comment['website_url'] : '';
            $comment['website_url'] = strip_tags($wUrl);
            if (!preg_match('/^https?/i', $comment['website_url'])) {
                $comment['website_url'] = 'http://' . $comment['website_url'];
            }
            if (!StringUtil::urlCheckFormat($comment['website_url'])) {
                PageState::current()->addError(Lang::t('Your website URL is invalid'));
                $commentAction = CommentModerationAction::Reject;
            }
        }

        if ($commentAction !== CommentModerationAction::Reject) {
            $updateAuthorId = $this->permissionService->isAdmin() ? null : (is_numeric($globalUser['id'] ?? null) ? (int) $globalUser['id'] : null);
            $result = $this->repo->update(
                (int) (is_scalar($comment['comment_id']) ? $comment['comment_id'] : 0),
                new CommentUpdateData(
                    content:    is_string($comment['content'] ?? null) ? $comment['content'] : '',
                    validated:  $commentAction === CommentModerationAction::Validate,
                    websiteUrl: !empty($comment['website_url']) ? (is_string($comment['website_url']) ? $comment['website_url'] : null) : null,
                ),
                $updateAuthorId
            );

            if ($result and Config::emailAdminOnCommentValidation() and CommentModerationAction::Moderate === $commentAction) {

                $commentUrl     = $this->urlService->addUrlParams($this->urlGenerator->comments(), ['comment_id' => is_scalar($comment['comment_id'] ?? null) ? (string) $comment['comment_id'] : '0']);
                $keyargsContent = [
                    $this->langService->getL10nArgs('Author: %s', stripslashes(is_string($globalUser['username'] ?? null) ? $globalUser['username'] : '')),
                    $this->langService->getL10nArgs('Comment: %s', stripslashes(is_string($comment['content'] ?? null) ? $comment['content'] : '')),
                    $this->langService->getL10nArgs(''),
                    $this->langService->getL10nArgs('Manage this user comment: %s', $commentUrl),
                    $this->langService->getL10nArgs('(!) This comment requires validation'),
                ];

                $this->mailService->pwgMailNotificationAdmins(
                    $this->langService->getL10nArgs('Comment by %s', stripslashes(is_string($globalUser['username'] ?? null) ? $globalUser['username'] : '')),
                    $keyargsContent
                );
            } elseif ($result) {
                $this->emailAdmin(CommentManagementAction::Edit, [
                    'author'  => is_string($globalUser['username'] ?? null) ? $globalUser['username'] : '',
                    'content' => stripslashes(is_string($comment['content'] ?? null) ? $comment['content'] : ''),
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
    public function emailAdmin(CommentManagementAction $action, array $comment): void
    {
        if ($action === CommentManagementAction::Validate
            or ($action === CommentManagementAction::Edit   and !Config::emailAdminOnCommentEdition())
            or ($action === CommentManagementAction::Delete and !Config::emailAdminOnCommentDeletion())) {
            return;
        }


        $authorRaw    = $comment['author'] ?? null;
        $authorStr    = is_scalar($authorRaw) ? (string) $authorRaw : '';
        $commentIdRaw = $comment['comment_id'] ?? null;
        $commentIdStr = is_scalar($commentIdRaw) ? (string) $commentIdRaw : '';
        $contentRaw   = $comment['content'] ?? null;
        $contentStr   = is_scalar($contentRaw) ? (string) $contentRaw : '';

        $keyargsContent = [$this->langService->getL10nArgs('Author: %s', $authorStr)];

        if ($action === CommentManagementAction::Delete) {
            $keyargsContent[] = $this->langService->getL10nArgs('This author removed the comment with id %d', $commentIdStr);
        } else {
            $keyargsContent[] = $this->langService->getL10nArgs('This author modified following comment:');
            $keyargsContent[] = $this->langService->getL10nArgs('Comment: %s', $contentStr);
        }

        $this->mailService->pwgMailNotificationAdmins(
            $this->langService->getL10nArgs('Comment by %s', $authorStr),
            $keyargsContent
        );
    }

    public function getCommentAuthorId(int $commentId): int
    {
        $authorId = $this->repo->getAuthorId($commentId);
        if ($authorId === null) {
            HtmlService::fatalError('Unknown comment identifier');
        }
        return $authorId;
    }

    /** @param int|int[] $commentId */
    public function validateUserComment(int|array $commentId): void
    {
        $this->repo->setValidated($commentId);
        $this->invalidateUserCacheNbComments();
        $this->dispatcher->dispatch(new UserCommentValidation($commentId));
    }

    public function invalidateUserCacheNbComments(): void
    {
        if (CurrentUser::isInitialized()) {
            CurrentUser::update(static fn (User $u): User => $u->withoutRawAttribute('nb_available_comments'));
        }

        $this->repo->clearNbAvailableCommentsCache();
    }
}
