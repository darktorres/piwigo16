<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Core\MailerInterface;

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
 * namespace entry and MailerInterface's own docblock. `fatal_error()`
 * (1 call site, `validateComment()`'s unknown-comment-id branch) stays a
 * bare free-function call, deliberately, permanently -- P23 batch 8's
 * finding 8, not an oversight.
 *
 * is_admin()/is_a_guest()/is_classic_user() and the `$user`/`$conf`
 * globals they and this class read are called exactly as the original
 * functions_comment.inc.php did -- the entire access-level-check family is
 * explicitly out of scope for this phase (see task #343), too widely used
 * app-wide to safely wrap here.
 */
final class CommentService
{
    public function __construct(
        private readonly CommentRepository $repo,
        private readonly EphemeralKeyService $ephemeralKeys,
        private readonly MailerInterface $mailer,
    ) {}

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
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($action === 'reject') {
            return $action;
        }

        $myAction = (bool) $conf['comment_spam_reject'] ? 'reject' : 'moderate';
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

        $maxLinks = $conf['comment_spam_max_links'] ?? 0;
        $maxLinks = is_numeric($maxLinks) ? (int) $maxLinks : 0;

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
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $user
         */
        global $conf, $user;

        $comm['ip'] = is_scalar($_SERVER['REMOTE_ADDR'] ?? null) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $comm['agent'] = is_scalar($_SERVER['HTTP_USER_AGENT'] ?? null) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        $infos = [];
        $commentAction = (! (bool) $conf['comments_validation'] || \Piwigo\Auth\AccessControl::isAdmin()) ? 'validate' : 'moderate';

        if (! \Piwigo\Auth\AccessControl::isClassicUser()) {
            if (self::emptyValue($comm['author'] ?? null)) {
                if ((bool) $conf['comments_author_mandatory']) {
                    $infos[] = l10n('Username is mandatory');
                    $commentAction = 'reject';
                }

                $comm['author'] = 'guest';
            }

            $guestId = $conf['guest_id'] ?? 0;
            $comm['author_id'] = is_numeric($guestId) ? (int) $guestId : 0;

            // if a guest tries to use the name of an already existing user,
            // they must be rejected
            if ($comm['author'] !== 'guest') {
                $authorName = is_string($comm['author']) ? $comm['author'] : '';
                $user_fields = $conf['user_fields'] ?? null;
                $user_fields = is_array($user_fields) ? $user_fields : [];
                $usernameColumn = is_string($user_fields['username'] ?? null) ? $user_fields['username'] : 'username';

                if ($this->repo->usernameExists($usernameColumn, $authorName)) {
                    $infos[] = l10n('This login is already used by another user');
                    $commentAction = 'reject';
                }
            }
        } else {
            $username = is_string($user['username'] ?? null) ? $user['username'] : '';
            $comm['author'] = addslashes($username);
            $userId = $user['id'] ?? 0;
            $comm['author_id'] = is_numeric($userId) ? (int) $userId : 0;
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
            if (! (bool) $conf['comments_enable_website']) { // honeypot: if the field is disabled, it should be empty !
                $commentAction = 'reject';
                self::pushCrReason('website_url');
            } else {
                $websiteUrl = is_string($comm['website_url']) ? $comm['website_url'] : '';
                $websiteUrl = strip_tags($websiteUrl);
                if (preg_match('/^https?/i', $websiteUrl) !== 1) {
                    $websiteUrl = 'http://' . $websiteUrl;
                }

                $comm['website_url'] = $websiteUrl;
                if (! url_check_format($websiteUrl)) {
                    $infos[] = l10n('Your website URL is invalid');
                    $commentAction = 'reject';
                }
            }
        }

        // email
        if (self::emptyValue($comm['email'] ?? null)) {
            if (! self::emptyValue($user['email'] ?? null)) {
                $comm['email'] = $user['email'];
            } elseif ((bool) $conf['comments_email_mandatory']) {
                $infos[] = l10n('Email address is missing. Please specify an email address.');
                $commentAction = 'reject';
            }
        } else {
            $email = is_string($comm['email']) ? $comm['email'] : null;
            if (! email_check_format($email)) {
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

        $antiFloodTime = $conf['anti-flood_time'] ?? 0;
        $antiFloodTime = is_numeric($antiFloodTime) ? (int) $antiFloodTime : 0;

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
        $result = trigger_change('user_comment_check', $commentAction, $comm);
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

            $emailAdminOnComment = (bool) $conf['email_admin_on_comment'] && $commentAction === 'validate';
            $emailAdminOnValidation = (bool) $conf['email_admin_on_comment_validation'] && $commentAction === 'moderate';
            if ($emailAdminOnComment || $emailAdminOnValidation) {
                $commentUrl = get_absolute_root_url() . 'comments.php?comment_id=' . $id;

                $keyargsContent = [
                    get_l10n_args('Author: %s', stripslashes($author)),
                    get_l10n_args('Email: %s', stripslashes($email ?? '')),
                    get_l10n_args('Comment: %s', stripslashes($content)),
                    get_l10n_args(''),
                    get_l10n_args('Manage this user comment: %s', $commentUrl),
                ];

                if ($commentAction === 'moderate') {
                    $keyargsContent[] = get_l10n_args('(!) This comment requires validation');
                }

                $this->mailer->mailNotificationAdmins(
                    get_l10n_args('Comment by %s', stripslashes($author)),
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
        /** @var array<string, mixed> $user */
        global $user;

        $ids = is_array($commentId) ? array_values(array_map(intval(...), $commentId)) : [$commentId];

        $authorId = null;
        if (! \Piwigo\Auth\AccessControl::isAdmin()) {
            $userId = $user['id'] ?? null;
            $authorId = is_numeric($userId) ? (int) $userId : 0;
        }

        if ($this->repo->delete($ids, $authorId) === 0) {
            return false;
        }

        $this->invalidateNbCommentsCache();

        $username = is_string($user['username'] ?? null) ? $user['username'] : '';
        $this->emailAdmin('delete', [
            'author' => $username,
            'comment_id' => $commentId,
        ]);

        trigger_notify('user_comment_deletion', $commentId);

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
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var array<string, mixed> $user
         */
        global $conf, $page, $user;

        $username = is_string($user['username'] ?? null) ? $user['username'] : '';

        $imageIdRaw = is_scalar($comment['image_id'] ?? null) ? (string) $comment['image_id'] : '';

        if (! $this->ephemeralKeys->verify($postKey, $imageIdRaw)) {
            $commentAction = 'reject';
        } elseif (! (bool) $conf['comments_validation'] || \Piwigo\Auth\AccessControl::isAdmin()) { // should the updated comment be validated
            $commentAction = 'validate';
        } else {
            $commentAction = 'moderate';
        }

        // perform more spam check
        $result = trigger_change(
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
            if (! url_check_format($websiteUrl)) {
                if (! is_array($page['errors'] ?? null)) {
                    $page['errors'] = [];
                }

                $page['errors'][] = l10n('Your website URL is invalid');
                $commentAction = 'reject';
            }
        }

        if ($commentAction !== 'reject') {
            $authorId = null;
            if (! \Piwigo\Auth\AccessControl::isAdmin()) {
                $userId = $user['id'] ?? null;
                $authorId = is_numeric($userId) ? (int) $userId : 0;
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
            if ($updated && (bool) $conf['email_admin_on_comment_validation'] && $commentAction === 'moderate') {
                $commentUrl = get_absolute_root_url() . 'comments.php?comment_id=' . $commentId;

                $keyargsContent = [
                    get_l10n_args('Author: %s', stripslashes($username)),
                    get_l10n_args('Comment: %s', stripslashes($content)),
                    get_l10n_args(''),
                    get_l10n_args('Manage this user comment: %s', $commentUrl),
                    get_l10n_args('(!) This comment requires validation'),
                ];

                $this->mailer->mailNotificationAdmins(
                    get_l10n_args('Comment by %s', stripslashes($username)),
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
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! in_array($action, ['edit', 'delete'], true)
            || ($action === 'edit' && ! (bool) $conf['email_admin_on_comment_edition'])
            || ($action === 'delete' && ! (bool) $conf['email_admin_on_comment_deletion'])) {
            return;
        }

        $author = is_string($comment['author'] ?? null) ? $comment['author'] : '';
        $keyargsContent = [
            get_l10n_args('Author: %s', $author),
        ];

        if ($action === 'delete') {
            $keyargsContent[] = get_l10n_args('This author removed the comment with id %d', $comment['comment_id']);
        } else {
            $keyargsContent[] = get_l10n_args('This author modified following comment:');
            $keyargsContent[] = get_l10n_args('Comment: %s', $comment['content']);
        }

        $this->mailer->mailNotificationAdmins(
            get_l10n_args('Comment by %s', $author),
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
                fatal_error('Unknown comment identifier');
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
        trigger_notify('user_comment_validation', $commentId);
    }

    /**
     * Clears the cache of nb comments for all users.
     */
    public function invalidateNbCommentsCache(): void
    {
        /** @var array<string, mixed> $user */
        global $user;

        unset($user['nb_available_comments']);
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
