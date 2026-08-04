<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\User\UserCommentCheck;
use Piwigo\Event\User\UserCommentDeletion;
use Piwigo\Event\User\UserCommentValidation;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;

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
 * `getCommentAuthorId()`'s unknown-comment-id `fatal_error()` call is now
 * routed through the same-shaped HtmlRenderingInterface (Piwigo\Core)
 * instead of staying a bare free-function call.
 *
 * AccessControl::current()->isAdmin()/isAGuest()/isClassicUser() and the
 * CurrentUser::get()/CurrentConfig:: accessors they and this class use
 * replace the original functions_comment.inc.php's bare is_admin()/
 * is_a_guest()/is_classic_user() calls and `$user`/`$conf` globals -- the
 * entire access-level-check family is explicitly out of scope for this
 * phase (see task #343), too widely used app-wide to safely wrap here.
 */
final readonly class CommentService
{
    public function __construct(
        private Lang $lang,
        private CommentRepository $repo,
        private EphemeralKeyService $ephemeralKeys,
        private MailerInterface $mailer,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private \Piwigo\Core\PageState $pageState,
        private \Piwigo\Users\CurrentUser $currentUser,
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
        $currentUser = \Piwigo\Users\CurrentUser::current()->get();

        if (! isset($currentUser->rawAttributes['nb_available_comments'])) {
            $where = [];
            if (! AccessControl::current()->isAdmin()) {
                $where[] = new SqlCondition('validated=1');
            }
            $where[] = new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())), \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Category\CategoryEntity::class))
                ->getSqlConditionFandFAsCondition([
                    'forbidden_categories' => 'category_id',
                    'forbidden_images' => 'ic.image_id',
                ], true);

            $nbAvailableComments = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Comment\CommentEntity::class)->countAvailableWithConditions($where);
            $currentUser = $currentUser->withRawAttribute('nb_available_comments', $nbAvailableComments);
            \Piwigo\Users\CurrentUser::current()->set($currentUser);
        }
        $nb_available_comments = $currentUser->rawAttributes['nb_available_comments'];
        return is_numeric($nb_available_comments) ? (int) $nb_available_comments : 0;
    }

    /**
     * @param list<SqlCondition> $whereClauses
     * @return PaginatedResult<array<string, mixed>>
     */
    public function getAllCommentsWithConditions(
        array $whereClauses,
        string $userIdColumn,
        string $userEmailColumn,
        string $sortByColumn,
        string $sortOrder,
        int|string $limit,
        int $offset
    ): PaginatedResult {
        return $this->repo->findAllWithConditions($whereClauses, $userIdColumn, $userEmailColumn, $sortByColumn, $sortOrder, $limit, $offset);
    }

    /**
     * @param  list<SqlCondition>  $whereClauses
     * @return array{all_comments: mixed, validated: mixed, pending: mixed}|null
     */
    public function getSummaryCounts(array $whereClauses): ?array
    {
        return $this->repo->findSummaryCounts($whereClauses);
    }

    /**
     * @param  list<SqlCondition>  $whereClauses
     * @return list<array<string, mixed>>
     */
    public function getListForAdminWs(
        array $whereClauses,
        string $userIdColumn,
        string $userUsernameColumn,
        int $offset,
        int $limit
    ): array {
        return $this->repo->findListForAdminWs($whereClauses, $userIdColumn, $userUsernameColumn, $offset, $limit);
    }

    /**
     * @param  list<SqlCondition>  $whereClauses
     * @return array{started_at: mixed, ended_at: mixed}|null
     */
    public function getDateRange(array $whereClauses): ?array
    {
        return $this->repo->findDateRange($whereClauses);
    }

    /**
     * @param  list<SqlCondition>  $whereClauses
     * @return list<array<string, mixed>>
     */
    public function getAuthorCounts(array $whereClauses): array
    {
        return $this->repo->findAuthorCounts($whereClauses);
    }

    public function countAll(): int
    {
        return $this->repo->countAll();
    }

    public function countUnvalidated(): int
    {
        return $this->repo->countUnvalidated();
    }

    public function countForImage(int $imageId, bool $onlyValidated): int
    {
        return $this->repo->countForImage($imageId, $onlyValidated);
    }

    /**
     * @return list<\Piwigo\Comment\Projection\CommentSummary>
     */
    public function getSummariesForImage(int $imageId, bool $onlyValidated, int $limit, int $offset): array
    {
        return $this->repo->findSummariesForImage($imageId, $onlyValidated, $limit, $offset);
    }

    /**
     * Basic spam check (plugins can do more via the same `user_comment_check`
     * event). Registered in Piwigo\Bootstrap\RequestBootstrap's own
     * default-event-handlers block (P23 batch 8c, relocated from the now-deleted
     * functions_comment.inc.php) as that event's own handler -- called by
     * dispatchChange() from insertComment()/updateComment() themselves, not
     * directly by callers. $event->comm is untyped per-key (matching
     * updateComment()'s own already-established looseness), so 'content'/
     * 'author' are narrowed defensively here rather than trusted as string.
     */
    public function checkForSpam(UserCommentCheck $event): UserCommentCheck
    {
        $action = $event->commentAction;

        if ($action === 'reject') {
            return $event;
        }

        $myAction = \Piwigo\Config\CurrentConfig::commentSpamReject() ? 'reject' : 'moderate';
        if ($action === $myAction) {
            return $event;
        }

        if (! \Piwigo\Auth\AccessControl::current()->isAGuest()) {
            return $event;
        }

        $content = is_string($event->comm['content'] ?? null) ? $event->comm['content'] : '';
        $linkCount = preg_match_all('/https?:\/\//', $content, $matches);
        // the pattern above is a hardcoded, always-valid regex
        assert($linkCount !== false);

        $author = is_string($event->comm['author'] ?? null) ? $event->comm['author'] : '';
        if (str_contains($author, 'http://')) {
            $linkCount++;
        }

        $maxLinks = \Piwigo\Config\CurrentConfig::commentSpamMaxLinks();

        if ($linkCount > $maxLinks) {
            $this->pushCrReason('links');
            $event->commentAction = $myAction;

            return $event;
        }

        return $event;
    }

    /**
     * Tries to insert a user comment and returns the action to perform.
     *
     * @param array{author: string, content: string, image_id: int, website_url?: string, email?: string} $comm in: caller-supplied fields
     * @param-out array{author: string, content: string, image_id: int, website_url?: string, email?: string, ip?: string, agent?: string, author_id?: int, id?: int} $comm out: augmented with ip/agent/author_id and (on success) id
     * @param list<string> $infos out: user-facing validation messages
     * @return string validate, moderate, reject
     */
    public function insertComment(array &$comm, string $key, array &$infos): string
    {

        $comm['ip'] = IpAddress::fromRemoteAddr()->value ?? '';
        $http_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $comm['agent'] = is_string($http_user_agent) ? $http_user_agent : '';

        $infos = [];
        $commentAction = (! \Piwigo\Config\CurrentConfig::commentsValidation() || \Piwigo\Auth\AccessControl::current()->isAdmin()) ? 'validate' : 'moderate';

        if (! \Piwigo\Auth\AccessControl::current()->isClassicUser()) {
            if (self::emptyValue($comm['author'])) {
                if (\Piwigo\Config\CurrentConfig::commentsAuthorMandatory()) {
                    $infos[] = $this->lang->t('Username is mandatory');
                    $commentAction = 'reject';
                }

                $comm['author'] = 'guest';
            }

            $guestId = \Piwigo\Config\CurrentConfig::guestId();
            $comm['author_id'] = $guestId;

            // if a guest tries to use the name of an already existing user,
            // they must be rejected
            if ($comm['author'] !== 'guest') {
                $authorName = $comm['author'];
                $user_fields = \Piwigo\Config\CurrentConfig::userFields();
                $usernameColumn = $user_fields['username'];

                if ($this->repo->usernameExists($usernameColumn, $authorName)) {
                    $infos[] = $this->lang->t('This login is already used by another user');
                    $commentAction = 'reject';
                }
            }
        } else {
            $user = $this->currentUser->get();
            $comm['author'] = addslashes($user->username);
            $comm['author_id'] = $user->id->value;
        }

        if (self::emptyValue($comm['content'])) {
            $commentAction = 'reject';
        }

        $imageId = $comm['image_id'];
        $imageIdRaw = (string) $imageId;

        if (! $this->ephemeralKeys->verify($key, $imageIdRaw)) {
            $commentAction = 'reject';
            $this->pushCrReason('key'); // rvelices: I use this outside to see how spam robots work
        }

        // website
        if (! self::emptyValue($comm['website_url'] ?? null)) {
            if (! \Piwigo\Config\CurrentConfig::commentsEnableWebsite()) { // honeypot: if the field is disabled, it should be empty !
                $commentAction = 'reject';
                $this->pushCrReason('website_url');
            } else {
                $websiteUrl = is_string($comm['website_url'] ?? null) ? $comm['website_url'] : '';
                $websiteUrl = strip_tags($websiteUrl);
                if (preg_match('/^https?/i', $websiteUrl) !== 1) {
                    $websiteUrl = 'http://' . $websiteUrl;
                }

                $comm['website_url'] = $websiteUrl;
                if (! \Piwigo\Validation\InputValidator::checkUrlFormat($websiteUrl)) {
                    $infos[] = $this->lang->t('Your website URL is invalid');
                    $commentAction = 'reject';
                }
            }
        }

        // email
        if (self::emptyValue($comm['email'] ?? null)) {
            $currentUserEmail = $this->currentUser->get()
                ->email;
            if (! self::emptyValue($currentUserEmail)) {
                $comm['email'] = $currentUserEmail;
            } elseif (\Piwigo\Config\CurrentConfig::commentsEmailMandatory()) {
                $infos[] = $this->lang->t('Email address is missing. Please specify an email address.');
                $commentAction = 'reject';
            }
        } else {
            $email = is_string($comm['email'] ?? null) ? $comm['email'] : null;
            if (! \Piwigo\Validation\InputValidator::checkEmailFormat($email)) {
                $infos[] = $this->lang->t('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
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

        $antiFloodTime = \Piwigo\Config\CurrentConfig::antiFloodTime();

        if ($commentAction !== 'reject' && $antiFloodTime > 0 && ! \Piwigo\Auth\AccessControl::current()->isAdmin()) { // anti-flood system
            $anonymousIdPrefix = \Piwigo\Auth\AccessControl::current()->isClassicUser() ? null : $trimmedIp;
            $counter = $this->repo->countRecentComments($authorId, $anonymousIdPrefix, $antiFloodTime);
            if ($counter > 0) {
                $infos[] = $this->lang->t('Anti-flood system : please wait for a moment before trying to post another comment');
                $commentAction = 'reject';
                $this->pushCrReason('flood_time');
            }
        }

        // perform more spam check
        $commentAction = $this->eventDispatcher->dispatchChange(new UserCommentCheck($commentAction, $comm))
            ->commentAction;

        if ($commentAction !== 'reject') {
            $author = $comm['author'];
            $content = $comm['content'];
            $websiteUrl = ! self::emptyValue($comm['website_url'] ?? null) && is_string($comm['website_url'] ?? null) ? $comm['website_url'] : null;
            $email = ! self::emptyValue($comm['email'] ?? null) && is_string($comm['email'] ?? null) ? $comm['email'] : null;

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
            $comm['id'] = $id->value;

            $this->invalidateNbCommentsCache();

            $emailAdminOnComment = \Piwigo\Config\CurrentConfig::emailAdminOnComment() && $commentAction === 'validate';
            $emailAdminOnValidation = \Piwigo\Config\CurrentConfig::emailAdminOnCommentValidation() && $commentAction === 'moderate';
            if ($emailAdminOnComment || $emailAdminOnValidation) {
                $commentUrl = $this->urlService->getAbsoluteRootUrl() . 'comments.php?comment_id=' . $id->value;

                $keyargsContent = [
                    $this->lang->buildArgs('Author: %s', stripslashes($author)),
                    $this->lang->buildArgs('Email: %s', stripslashes($email ?? '')),
                    $this->lang->buildArgs('Comment: %s', stripslashes($content)),
                    $this->lang->buildArgs(''),
                    $this->lang->buildArgs('Manage this user comment: %s', $commentUrl),
                ];

                if ($commentAction === 'moderate') {
                    $keyargsContent[] = $this->lang->buildArgs('(!) This comment requires validation');
                }

                $this->mailer->mailNotificationAdmins(
                    $this->lang->buildArgs('Comment by %s', stripslashes($author)),
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
     * @param CommentId|list<CommentId> $commentId
     * @return bool false if nothing deleted
     */
    public function deleteComment(CommentId|array $commentId): bool
    {
        $ids = is_array($commentId) ? $commentId : [$commentId];

        $authorId = null;
        if (! \Piwigo\Auth\AccessControl::current()->isAdmin()) {
            $authorId = $this->currentUser->get()
                ->id->value;
        }

        if ($this->repo->delete($ids, $authorId) === 0) {
            return false;
        }

        $this->invalidateNbCommentsCache();

        // EventDispatcher/emailAdmin() are cross-boundary sinks (plugin
        // events, a generic untyped $comment array) -- unwrap ->value
        // explicitly rather than rely on Stringable, same rule as every
        // other Smarty/JSON/plugin-event boundary in this codebase.
        $rawCommentId = is_array($commentId)
            ? array_map(static fn (CommentId $id): int => $id->value, $commentId)
            : $commentId->value;

        $username = $this->currentUser->get()
            ->username;
        $this->emailAdmin('delete', [
            'author' => $username,
            'comment_id' => $rawCommentId,
        ]);

        $this->eventDispatcher->dispatchNotify(new UserCommentDeletion($rawCommentId));

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
     * $comment is still only known as array<string, mixed> here even
     * though both real callers (CommentsController/PictureController) now
     * build it from their own validated Request\CommentsRequest/
     * Request\PictureRequest DTO fields (P26/SEC-40) rather than raw
     * $_GET/$_POST -- kept a generic array (matching insertComment()'s
     * own $comm shape) since this method's own defensive is_scalar()/
     * is_string() narrowing below already treats every field as
     * untrusted, same as any other external-boundary array.
     *
     * @param array<string, mixed> $comment
     * @return string validate, moderate, reject
     */
    public function updateComment(array $comment, string $postKey): string
    {
        $username = $this->currentUser->get()
            ->username;

        $imageIdRaw = is_scalar($comment['image_id'] ?? null) ? (string) $comment['image_id'] : '';

        if (! $this->ephemeralKeys->verify($postKey, $imageIdRaw)) {
            $commentAction = 'reject';
        } elseif (! \Piwigo\Config\CurrentConfig::commentsValidation() || \Piwigo\Auth\AccessControl::current()->isAdmin()) { // should the updated comment be validated
            $commentAction = 'validate';
        } else {
            $commentAction = 'moderate';
        }

        // perform more spam check
        $commentAction = $this->eventDispatcher->dispatchChange(new UserCommentCheck(
            $commentAction,
            array_merge($comment, [
                'author' => $username,
            ])
        ))->commentAction;

        // website
        if (! self::emptyValue($comment['website_url'] ?? null)) {
            $websiteUrl = is_string($comment['website_url']) ? $comment['website_url'] : '';
            $websiteUrl = strip_tags($websiteUrl);
            if (preg_match('/^https?/i', $websiteUrl) !== 1) {
                $websiteUrl = 'http://' . $websiteUrl;
            }

            $comment['website_url'] = $websiteUrl;
            if (! \Piwigo\Validation\InputValidator::checkUrlFormat($websiteUrl)) {
                $this->pageState->addError($this->lang->t('Your website URL is invalid'));
                $commentAction = 'reject';
            }
        }

        if ($commentAction !== 'reject') {
            $authorId = null;
            if (! \Piwigo\Auth\AccessControl::current()->isAdmin()) {
                $authorId = $this->currentUser->get()
                    ->id->value;
            }

            $content = is_string($comment['content']) ? $comment['content'] : '';
            $websiteUrl = ! self::emptyValue($comment['website_url'] ?? null) && is_string($comment['website_url']) ? $comment['website_url'] : null;
            $commentId = CommentId::tryFrom($comment['comment_id'] ?? null);

            // A missing/invalid comment_id never matches a real row anyway
            // (ids start at 1) -- skip the query entirely rather than
            // calling update() with a sentinel, same outcome ($updated ===
            // false) the original's own `?? 0` default produced.
            $updated = $commentId !== null && $this->repo->update(
                $commentId,
                [
                    'content' => $content,
                    'websiteUrl' => $websiteUrl,
                    'validated' => $commentAction === 'validate',
                ],
                $authorId
            );

            // mail admin and ask to validate the comment
            if ($updated && \Piwigo\Config\CurrentConfig::emailAdminOnCommentValidation() && $commentAction === 'moderate') {
                // $updated === true only when $commentId !== null (short-circuit above) --
                // PHPStan already narrows this without an assert().
                $commentUrl = $this->urlService->getAbsoluteRootUrl() . 'comments.php?comment_id=' . $commentId->value;

                $keyargsContent = [
                    $this->lang->buildArgs('Author: %s', stripslashes($username)),
                    $this->lang->buildArgs('Comment: %s', stripslashes($content)),
                    $this->lang->buildArgs(''),
                    $this->lang->buildArgs('Manage this user comment: %s', $commentUrl),
                    $this->lang->buildArgs('(!) This comment requires validation'),
                ];

                $this->mailer->mailNotificationAdmins(
                    $this->lang->buildArgs('Comment by %s', stripslashes($username)),
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
     * Same cross-domain generic-row-reader rationale as
     * Category\CategoryService::compareByGlobalRank() -- called from both
     * insertComment()'s (structured) and updateComment()'s (raw request
     * input, see that method's own docblock) $comment locals.
     *
     * @param array<string, mixed> $comment
     */
    public function emailAdmin(string $action, array $comment): void
    {
        if (! in_array($action, ['edit', 'delete'], true)
            || ($action === 'edit' && ! \Piwigo\Config\CurrentConfig::emailAdminOnCommentEdition())
            || ($action === 'delete' && ! \Piwigo\Config\CurrentConfig::emailAdminOnCommentDeletion())) {
            return;
        }

        $author = is_string($comment['author'] ?? null) ? $comment['author'] : '';
        $keyargsContent = [
            $this->lang->buildArgs('Author: %s', $author),
        ];

        if ($action === 'delete') {
            $keyargsContent[] = $this->lang->buildArgs('This author removed the comment with id %d', $comment['comment_id']);
        } else {
            $keyargsContent[] = $this->lang->buildArgs('This author modified following comment:');
            $keyargsContent[] = $this->lang->buildArgs('Comment: %s', $comment['content']);
        }

        $this->mailer->mailNotificationAdmins(
            $this->lang->buildArgs('Comment by %s', $author),
            $keyargsContent
        );
    }

    /**
     * Returns the author id of a comment.
     *
     * @return int|null|false false if $dieOnError is false and the comment
     *   doesn't exist; null if it exists but has no owner (anonymous/guest
     *   comment) -- these are distinct states, see
     *   CommentRepository::findAuthorId()
     */
    public function getCommentAuthorId(CommentId $commentId, bool $dieOnError = true): int|null|false
    {
        $value = $this->repo->findAuthorId($commentId);

        if ($value === false) {
            if ($dieOnError) {
                $this->htmlRenderer->fatalError('Unknown comment identifier');
            }

            return false;
        }

        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (int) $value : false;
    }

    /**
     * Tries to validate a user comment.
     *
     * @param CommentId|list<CommentId> $commentId
     */
    public function validateComment(CommentId|array $commentId): void
    {
        $ids = is_array($commentId) ? $commentId : [$commentId];

        $this->repo->validate($ids);
        $this->invalidateNbCommentsCache();

        // EventDispatcher is a plugin-event boundary -- unwrap ->value
        // explicitly, same rule as deleteComment()'s own identical case.
        $rawCommentId = is_array($commentId)
            ? array_map(static fn (CommentId $id): int => $id->value, $commentId)
            : $commentId->value;
        $this->eventDispatcher->dispatchNotify(new UserCommentValidation($rawCommentId));
    }

    /**
     * Clears the cache of nb comments for all users.
     */
    public function invalidateNbCommentsCache(): void
    {
        $this->currentUser->set($this->currentUser->get()->withRawAttribute('nb_available_comments', null));
    }

    private function pushCrReason(string $reason): void
    {
        $this->pageState->addCommentRejectionReason($reason);
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
