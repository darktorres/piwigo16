<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;

/**
 * Comment domain business logic: spam/flood checks, insert/update/delete/
 * validate, admin notification emails. Constructor-injects
 * CommentRepository + EphemeralKeyService (anti-spam key on anonymous
 * posts) -- Auth lives in L2aCoreDomain, Comment in L2bExtendedDomain, so a
 * real class-to-class dependency there is allowed (unlike Mail, see below).
 *
 * P23 batch 8c: constructor-injects MailerInterface (Piwigo\Core) for
 * `mailNotificationAdmins()` rather than depending on
 * Piwigo\Mail\MailService directly -- same L2b-may-not-depend-on-L3
 * constraint as UserService, see deptrac.yaml's own comment on the Mail
 * namespace entry and MailerInterface's own docblock. P23 batch 8f-3:
 * `validateComment()`'s unknown-comment-id `fatal_error()` call is now
 * routed through the same-shaped HtmlRenderingInterface (Piwigo\Core)
 * instead of staying a bare free-function call.
 *
 * is_admin()/is_a_guest()/is_classic_user() and the `$user`/`$conf`
 * globals they and this class read are called exactly as the original
 * functions_comment.inc.php did -- the entire access-level-check family is
 * explicitly out of scope for this phase (see task #343), too widely used
 * app-wide to safely wrap here.
 */
final readonly class CommentService
{
    public function __construct(
        private CommentRepository $repo,
        private EphemeralKeyService $ephemeralKeys,
        private MailerInterface $mailer,
        private HtmlRenderingInterface $htmlRenderer,
    ) {}

    /**
     * returns the number of available comments for the connected user
     *
     * P23 batch 8d: relocated from include/functions.inc.php's
     * get_nb_available_comments(), unchanged logic -- static since it
     * builds its own PermissionService/GroupRepository internally (same
     * as the original free function did) rather than needing this class's
     * own injected CommentRepository/EphemeralKeyService/MailerInterface,
     * matching InputValidator's own mixed static/instance precedent.
     */
    public static function getNbAvailableComments(): int
    {
        $currentUser = \Piwigo\Users\CurrentUser::get();

        if (! isset($currentUser->rawAttributes['nb_available_comments'])) {
            $where = [];
            if (! AccessControl::isAdmin()) {
                $where[] = 'validated=\'true\'';
            }
            $where[] = new PermissionService(new PermissionRepository(DbConnection::build()), new GroupRepository(DbConnection::build()), new CategoryRepository(DbConnection::build()))
                ->getSqlConditionFandF([
                    'forbidden_categories' => 'category_id',
                    'forbidden_images' => 'ic.image_id',
                ], '', true);

            $nbAvailableComments = new CommentRepository(DbConnection::build())->countAvailableWithConditions($where);
            $currentUser = $currentUser->withRawAttribute('nb_available_comments', $nbAvailableComments);
            \Piwigo\Users\CurrentUser::set($currentUser);

            new CommentRepository(DbConnection::build())->saveNbAvailableComments($currentUser->id, $nbAvailableComments);
        }
        $nb_available_comments = $currentUser->rawAttributes['nb_available_comments'];
        return is_numeric($nb_available_comments) ? (int) $nb_available_comments : 0;
    }

    /**
     * Basic spam check (plugins can do more via the same `user_comment_check`
     * event). Registered in include/common.inc.php's default-event-handlers
     * block (P23 batch 8c, relocated from the now-deleted
     * functions_comment.inc.php) as that event's own handler -- called by
     * trigger_change() from insertComment()/updateComment() themselves, not
     * directly by callers.
     *
     * @param array<string, mixed> $comment
     * @return string validate, moderate, reject
     */
    public function checkForSpam(string $action, array $comment): string
    {

        if ($action === 'reject') {
            return $action;
        }

        $myAction = \Piwigo\Config\Config::commentSpamReject() ? 'reject' : 'moderate';
        if ($action === $myAction) {
            return $action;
        }

        if (! \Piwigo\Auth\AccessControl::isAGuest()) {
            return $action;
        }

        $content = is_string($comment['content'] ?? null) ? $comment['content'] : '';
        $linkCount = preg_match_all('/https?:\/\//', $content, $matches);
        // the pattern above is a hardcoded, always-valid regex
        assert($linkCount !== false);

        $author = is_string($comment['author'] ?? null) ? $comment['author'] : '';
        if (str_contains($author, 'http://')) {
            $linkCount++;
        }

        $maxLinks = \Piwigo\Config\Config::commentSpamMaxLinks();

        if ($linkCount > $maxLinks) {
            self::pushCrReason('links');

            return $myAction;
        }

        return $action;
    }

    /**
     * Tries to insert a user comment and returns the action to perform.
     *
     * @param array<string, mixed> $comm in/out: augmented with ip/agent/
     *   author_id and (on success) id
     * @param list<string> $infos out: user-facing validation messages
     * @return string validate, moderate, reject
     */
    public function insertComment(array &$comm, string $key, array &$infos): string
    {

        $comm['ip'] = is_scalar($_SERVER['REMOTE_ADDR'] ?? null) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $comm['agent'] = is_scalar($_SERVER['HTTP_USER_AGENT'] ?? null) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        $infos = [];
        $commentAction = (! \Piwigo\Config\Config::commentsValidation() || \Piwigo\Auth\AccessControl::isAdmin()) ? 'validate' : 'moderate';

        if (! \Piwigo\Auth\AccessControl::isClassicUser()) {
            if (self::emptyValue($comm['author'] ?? null)) {
                if (\Piwigo\Config\Config::commentsAuthorMandatory()) {
                    $infos[] = l10n('Username is mandatory');
                    $commentAction = 'reject';
                }

                $comm['author'] = 'guest';
            }

            $guestId = \Piwigo\Config\Config::guestId();
            $comm['author_id'] = $guestId;

            // if a guest tries to use the name of an already existing user,
            // they must be rejected
            if ($comm['author'] !== 'guest') {
                $authorName = is_string($comm['author']) ? $comm['author'] : '';
                $user_fields = \Piwigo\Config\Config::userFields();
                $usernameColumn = is_string($user_fields['username'] ?? null) ? $user_fields['username'] : 'username';

                if ($this->repo->usernameExists($usernameColumn, $authorName)) {
                    $infos[] = l10n('This login is already used by another user');
                    $commentAction = 'reject';
                }
            }
        } else {
            $currentUser = \Piwigo\Users\CurrentUser::get();
            $comm['author'] = addslashes($currentUser->username);
            $comm['author_id'] = $currentUser->id;
        }

        if (self::emptyValue($comm['content'] ?? null)) {
            $commentAction = 'reject';
        }

        $imageIdRaw = is_scalar($comm['image_id'] ?? null) ? (string) $comm['image_id'] : '';
        $imageId = is_numeric($imageIdRaw) ? (int) $imageIdRaw : 0;

        if (! $this->ephemeralKeys->verify($key, $imageIdRaw)) {
            $commentAction = 'reject';
            self::pushCrReason('key'); // rvelices: I use this outside to see how spam robots work
        }

        // website
        if (! self::emptyValue($comm['website_url'] ?? null)) {
            if (! \Piwigo\Config\Config::commentsEnableWebsite()) { // honeypot: if the field is disabled, it should be empty !
                $commentAction = 'reject';
                self::pushCrReason('website_url');
            } else {
                $websiteUrl = is_string($comm['website_url']) ? $comm['website_url'] : '';
                $websiteUrl = strip_tags($websiteUrl);
                if (preg_match('/^https?/i', $websiteUrl) !== 1) {
                    $websiteUrl = 'http://' . $websiteUrl;
                }

                $comm['website_url'] = $websiteUrl;
                if (! \Piwigo\Validation\InputValidator::checkUrlFormat($websiteUrl)) {
                    $infos[] = l10n('Your website URL is invalid');
                    $commentAction = 'reject';
                }
            }
        }

        // email
        if (self::emptyValue($comm['email'] ?? null)) {
            $currentUserEmail = \Piwigo\Users\CurrentUser::get()->email;
            if (! self::emptyValue($currentUserEmail)) {
                $comm['email'] = $currentUserEmail;
            } elseif (\Piwigo\Config\Config::commentsEmailMandatory()) {
                $infos[] = l10n('Email address is missing. Please specify an email address.');
                $commentAction = 'reject';
            }
        } else {
            $email = is_string($comm['email']) ? $comm['email'] : null;
            if (! \Piwigo\Validation\InputValidator::checkEmailFormat($email)) {
                $infos[] = l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
                $commentAction = 'reject';
            }
        }

        // Trimmed IP (drops the last octet), used only as the anti-flood
        // LIKE prefix below -- the `anonymous_id` column itself stores the
        // full, untrimmed $comm['ip'] (see the INSERT below), not this
        // trimmed value.
        // $comm['ip'] was set to a string unconditionally at the top of
        // this method.
        $ipComponents = explode('.', $comm['ip']);
        if (count($ipComponents) > 3) {
            array_pop($ipComponents);
        }

        $trimmedIp = implode('.', $ipComponents);

        // $comm['author_id'] was set to an int unconditionally in both
        // branches above.
        $authorId = $comm['author_id'];

        $antiFloodTime = \Piwigo\Config\Config::antiFloodTime();

        if ($commentAction !== 'reject' && $antiFloodTime > 0 && ! \Piwigo\Auth\AccessControl::isAdmin()) { // anti-flood system
            $anonymousIdPrefix = \Piwigo\Auth\AccessControl::isClassicUser() ? null : $trimmedIp;
            $counter = $this->repo->countRecentComments($authorId, $anonymousIdPrefix, $antiFloodTime);
            if ($counter > 0) {
                $infos[] = l10n('Anti-flood system : please wait for a moment before trying to post another comment');
                $commentAction = 'reject';
                self::pushCrReason('flood_time');
            }
        }

        // perform more spam check
        $result = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('user_comment_check', $commentAction, $comm);
        // handlers of the user_comment_check event contract MUST return a
        // string (validate/moderate/reject); fail closed if a handler
        // misbehaves
        $commentAction = is_string($result) ? $result : 'reject';

        if ($commentAction !== 'reject') {
            $author = is_string($comm['author']) ? $comm['author'] : '';
            $content = is_string($comm['content']) ? $comm['content'] : '';
            $websiteUrl = ! self::emptyValue($comm['website_url'] ?? null) && is_string($comm['website_url']) ? $comm['website_url'] : null;
            $email = ! self::emptyValue($comm['email'] ?? null) && is_string($comm['email']) ? $comm['email'] : null;

            $id = $this->repo->insert([
                'author' => $author,
                'authorId' => $authorId,
                'anonymousId' => $comm['ip'],
                'content' => $content,
                'validated' => $commentAction === 'validate',
                'imageId' => $imageId,
                'websiteUrl' => $websiteUrl,
                'email' => $email,
            ]);
            $comm['id'] = $id;

            $this->invalidateNbCommentsCache();

            $emailAdminOnComment = \Piwigo\Config\Config::emailAdminOnComment() && $commentAction === 'validate';
            $emailAdminOnValidation = \Piwigo\Config\Config::emailAdminOnCommentValidation() && $commentAction === 'moderate';
            if ($emailAdminOnComment || $emailAdminOnValidation) {
                $commentUrl = get_absolute_root_url() . 'comments.php?comment_id=' . $id;

                $keyargsContent = [
                    Lang::buildArgs('Author: %s', stripslashes($author)),
                    Lang::buildArgs('Email: %s', stripslashes($email ?? '')),
                    Lang::buildArgs('Comment: %s', stripslashes($content)),
                    Lang::buildArgs(''),
                    Lang::buildArgs('Manage this user comment: %s', $commentUrl),
                ];

                if ($commentAction === 'moderate') {
                    $keyargsContent[] = Lang::buildArgs('(!) This comment requires validation');
                }

                $this->mailer->mailNotificationAdmins(
                    Lang::buildArgs('Comment by %s', stripslashes($author)),
                    $keyargsContent
                );
            }
        }

        return $commentAction;
    }

    /**
     * Tries to delete a (or more) user comment.
     *   only admin can delete all comments
     *   other users can delete their own comments
     *
     * @param int|array<int, int> $commentId
     * @return bool false if nothing deleted
     */
    public function deleteComment(int|array $commentId): bool
    {
        $ids = is_array($commentId) ? array_values(array_map(intval(...), $commentId)) : [$commentId];

        $authorId = null;
        if (! \Piwigo\Auth\AccessControl::isAdmin()) {
            $authorId = \Piwigo\Users\CurrentUser::get()->id;
        }

        if ($this->repo->delete($ids, $authorId) === 0) {
            return false;
        }

        $this->invalidateNbCommentsCache();

        $username = \Piwigo\Users\CurrentUser::get()->username;
        $this->emailAdmin('delete', [
            'author' => $username,
            'comment_id' => $commentId,
        ]);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('user_comment_deletion', $commentId);

        return true;
    }

    /**
     * Tries to update a user comment.
     *   only admin can update all comments
     *   users can edit their own comments if admin allows them
     *
     * Assumes the caller has already authorized this exact edit (e.g. via
     * can_manage_comment('edit', ...)) -- the UPDATE's own author_id
     * restriction (for non-admins) is defense in depth, not the primary
     * access check.
     *
     * @param array<string, mixed> $comment
     * @return string validate, moderate, reject
     */
    public function updateComment(array $comment, string $postKey): string
    {
        $username = \Piwigo\Users\CurrentUser::get()->username;

        $imageIdRaw = is_scalar($comment['image_id'] ?? null) ? (string) $comment['image_id'] : '';

        if (! $this->ephemeralKeys->verify($postKey, $imageIdRaw)) {
            $commentAction = 'reject';
        } elseif (! \Piwigo\Config\Config::commentsValidation() || \Piwigo\Auth\AccessControl::isAdmin()) { // should the updated comment be validated
            $commentAction = 'validate';
        } else {
            $commentAction = 'moderate';
        }

        // perform more spam check
        $result = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
            'user_comment_check',
            $commentAction,
            array_merge($comment, [
                'author' => $username,
            ])
        );
        // handlers of the user_comment_check event contract MUST return a
        // string (validate/moderate/reject); fail closed if a handler
        // misbehaves
        $commentAction = is_string($result) ? $result : 'reject';

        // website
        if (! self::emptyValue($comment['website_url'] ?? null)) {
            $websiteUrl = is_string($comment['website_url']) ? $comment['website_url'] : '';
            $websiteUrl = strip_tags($websiteUrl);
            if (preg_match('/^https?/i', $websiteUrl) !== 1) {
                $websiteUrl = 'http://' . $websiteUrl;
            }

            $comment['website_url'] = $websiteUrl;
            if (! \Piwigo\Validation\InputValidator::checkUrlFormat($websiteUrl)) {
                \Piwigo\Core\PageState::current()->addError(l10n('Your website URL is invalid'));
                $commentAction = 'reject';
            }
        }

        if ($commentAction !== 'reject') {
            $authorId = null;
            if (! \Piwigo\Auth\AccessControl::isAdmin()) {
                $authorId = \Piwigo\Users\CurrentUser::get()->id;
            }

            $content = is_string($comment['content']) ? $comment['content'] : '';
            $websiteUrl = ! self::emptyValue($comment['website_url'] ?? null) && is_string($comment['website_url']) ? $comment['website_url'] : null;
            $commentId = is_numeric($comment['comment_id'] ?? null) ? (int) $comment['comment_id'] : 0;

            $updated = $this->repo->update(
                $commentId,
                [
                    'content' => $content,
                    'websiteUrl' => $websiteUrl,
                    'validated' => $commentAction === 'validate',
                ],
                $authorId
            );

            // mail admin and ask to validate the comment
            if ($updated && \Piwigo\Config\Config::emailAdminOnCommentValidation() && $commentAction === 'moderate') {
                $commentUrl = get_absolute_root_url() . 'comments.php?comment_id=' . $commentId;

                $keyargsContent = [
                    Lang::buildArgs('Author: %s', stripslashes($username)),
                    Lang::buildArgs('Comment: %s', stripslashes($content)),
                    Lang::buildArgs(''),
                    Lang::buildArgs('Manage this user comment: %s', $commentUrl),
                    Lang::buildArgs('(!) This comment requires validation'),
                ];

                $this->mailer->mailNotificationAdmins(
                    Lang::buildArgs('Comment by %s', stripslashes($username)),
                    $keyargsContent
                );
            } elseif ($updated) {
                // just mail admin
                $this->emailAdmin('edit', [
                    'author' => $username,
                    'content' => stripslashes($content),
                ]);
            }
        }

        return $commentAction;
    }

    /**
     * Notifies admins about an updated or deleted comment. Only used when
     * no validation is needed, otherwise pwg_mail_notification_admins() is
     * called directly from insertComment()/updateComment().
     *
     * @param array<string, mixed> $comment
     */
    public function emailAdmin(string $action, array $comment): void
    {
        if (! in_array($action, ['edit', 'delete'], true)
            || ($action === 'edit' && ! \Piwigo\Config\Config::emailAdminOnCommentEdition())
            || ($action === 'delete' && ! \Piwigo\Config\Config::emailAdminOnCommentDeletion())) {
            return;
        }

        $author = is_string($comment['author'] ?? null) ? $comment['author'] : '';
        $keyargsContent = [
            Lang::buildArgs('Author: %s', $author),
        ];

        if ($action === 'delete') {
            $keyargsContent[] = Lang::buildArgs('This author removed the comment with id %d', $comment['comment_id']);
        } else {
            $keyargsContent[] = Lang::buildArgs('This author modified following comment:');
            $keyargsContent[] = Lang::buildArgs('Comment: %s', $comment['content']);
        }

        $this->mailer->mailNotificationAdmins(
            Lang::buildArgs('Comment by %s', $author),
            $keyargsContent
        );
    }

    /**
     * Returns the author id of a comment.
     *
     * @return int|false false if $dieOnError is false and the comment
     *   doesn't exist, or if it exists but has no owner (anonymous/guest
     *   comment)
     */
    public function getCommentAuthorId(int $commentId, bool $dieOnError = true): int|false
    {
        $value = $this->repo->findAuthorId($commentId);

        if ($value === false) {
            if ($dieOnError) {
                $this->htmlRenderer->fatalError('Unknown comment identifier');
            }

            return false;
        }

        return is_numeric($value) ? (int) $value : false;
    }

    /**
     * Tries to validate a user comment.
     *
     * @param int|array<int, int> $commentId
     */
    public function validateComment(int|array $commentId): void
    {
        $ids = is_array($commentId) ? array_values(array_map(intval(...), $commentId)) : [$commentId];

        $this->repo->validate($ids);
        $this->invalidateNbCommentsCache();
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('user_comment_validation', $commentId);
    }

    /**
     * Clears the cache of nb comments for all users.
     */
    public function invalidateNbCommentsCache(): void
    {
        \Piwigo\Users\CurrentUser::set(\Piwigo\Users\CurrentUser::get()->withRawAttribute('nb_available_comments', null));
        $this->repo->clearNbCommentsCache();
    }

    private static function pushCrReason(string $reason): void
    {
        if (! isset($_POST['cr']) || ! is_array($_POST['cr'])) {
            $_POST['cr'] = [];
        }

        $_POST['cr'][] = $reason;
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}
