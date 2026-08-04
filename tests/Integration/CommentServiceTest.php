<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Auth\EphemeralKeyService;
    use Piwigo\Comment\CommentRepository;
    use Piwigo\Comment\CommentService;
    use Piwigo\Common\ValueObject\CommentId;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Core\HtmlRenderingInterface;
    use Piwigo\Core\MailerInterface;
    use Piwigo\Core\RedirectServiceInterface;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Event\User\UserCommentCheck;
    use Piwigo\Html\HtmlService;
    use Piwigo\Mail\MailService;
    use Piwigo\Url\UrlService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;
    use Piwigo\Users\UserStatus;

    /**
     * insertComment()/updateComment()'s admin-notification mail dispatch
     * and emailAdmin() itself build exact keyargsContent arrays passed to
     * MailerInterface::mailNotificationAdmins() -- this fake records every
     * call verbatim so tests can assert on that exact shape without a real
     * Mail\MailService (Symfony Mailer) round trip.
     */
    final class CommentServiceFakeMailerRecordsNotifications implements MailerInterface
    {
        /** @var list<array{subject: string|array{key_args: array<int, mixed>}, content: string|list<array{key_args: array<int, mixed>}>, sendTechnicalDetails: bool, groupId: int|string|null}> */
        public array $calls = [];

        #[\Override]
        public function mail(string|array $to, array $args = [], array $tpl = []): bool
        {
            throw new \LogicException("not used by CommentService's mail dispatch paths");
        }

        #[\Override]
        public function mailNotificationAdmins(string|array $subject, string|array $content, bool $sendTechnicalDetails = true, int|string|null $groupId = null): bool
        {
            $this->calls[] = [
                'subject' => $subject,
                'content' => $content,
                'sendTechnicalDetails' => $sendTechnicalDetails,
                'groupId' => $groupId,
            ];

            return true;
        }
    }

    /**
     * getCommentAuthorId()'s unknown-comment-id branch is the only
     * HtmlRenderingInterface method CommentService ever calls -- every
     * other method throws, same pattern as CategoryServiceTest's own
     * CategoryServiceFakeHtmlRendererDeniesAccess.
     */
    final class CommentServiceFakeHtmlRendererThrowsOnFatalError implements HtmlRenderingInterface
    {
        #[\Override]
        public function getCatDisplayName(array $catInformations, ?string $url = ''): string
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function getCatDisplayNameCache(
            string $uppercats,
            ?string $url = '',
            bool $singleLink = false,
            ?string $linkClass = null,
            ?string $authKey = null,
        ): string {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function nameCompare(array $a, array $b): int
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function tagAlphaCompare(array $a, array $b): int
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function accessDenied(RedirectServiceInterface $redirectService): never
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
        {
            throw new \RuntimeException('COMMENT_SERVICE_FATAL_ERROR_MARKER: ' . $msg);
        }

        #[\Override]
        public function getTagsContentTitle(array $tags): string
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function setStatusHeader(int $code, string $text = ''): void
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function renderElementName(array $info): string
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function renderElementDescription(array $info, string $param = ''): string
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }

        #[\Override]
        public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
        {
            throw new \LogicException('not used by getCommentAuthorId()');
        }
    }

    /**
     * Covers checkForSpam()/insertComment()/updateComment()/deleteComment()/
     * validateComment()/getCommentAuthorId()/invalidateNbCommentsCache()
     * with every `email_admin_on_comment*` config flag off, so no test
     * needs the real Mail infrastructure (MailerInterface -> MailService ->
     * Symfony Mailer) -- same split established for
     * GroupService/UserService: the admin-notification email paths are
     * live-verified separately against the running Apache instance.
     */
    final class CommentServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private CommentService $service;

        private Connection $conn;

        #[\Override]
        protected function setUp(): void
        {
            parent::setUp();
            $this->setUpConnectionFromEnv();

            if (! self::$fixtureReady) {
                $this->resetDatabase();
                $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
                self::$fixtureReady = true;
            }

            $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
            if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
                throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
            }
            $currentConfig->reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            CurrentConfig::current()->setSecretKey('test-secret-key');
            CurrentConfig::current()->setCommentsValidation(true);
            CurrentConfig::current()->setCommentsAuthorMandatory(false);
            CurrentConfig::current()->setCommentsEmailMandatory(false);
            CurrentConfig::current()->setCommentsEnableWebsite(true);
            CurrentConfig::current()->setCommentSpamReject(true);
            CurrentConfig::current()->setCommentSpamMaxLinks(3);
            CurrentConfig::current()->setAntiFloodTime(0);
            CurrentConfig::current()->setGuestId(2);
            CurrentConfig::current()->setGuestAccess(true);
            CurrentConfig::current()->setEmailAdminOnComment(false);
            CurrentConfig::current()->setEmailAdminOnCommentValidation(false);
            CurrentConfig::current()->setEmailAdminOnCommentEdition(false);
            CurrentConfig::current()->setEmailAdminOnCommentDeletion(false);
            CurrentUser::current()->set(User::fromUserArray(['id' => 1, 'status' => 'normal', 'username' => 'fixture_admin', 'email' => 'fixture_admin@example.test']));
            \Piwigo\Core\PageState::current()->reset();

            $this->conn = DbConnection::build();
            $this->service = new CommentService(\Piwigo\Core\Lang::current(), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Comment\CommentEntity::class), new EphemeralKeyService(\Piwigo\Config\CurrentConfig::current()), new MailService(), new HtmlService(), new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Config\CurrentConfig::current());
        }

        private function accessControl(): \Piwigo\Auth\AccessControl
        {
            // Kernel is already booted by parent::setUp() above -- resolve
            // the same container-shared instance a real request would get
            // (singleton/service-locator elimination campaign, Phase 7).
            $accessControl = \Piwigo\Core\Kernel::container()->get(\Piwigo\Auth\AccessControl::class);
            if (! $accessControl instanceof \Piwigo\Auth\AccessControl) {
                throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Auth\AccessControl::class);
            }

            return $accessControl;
        }

        // --- checkForSpam() -------------------------------------------------

        /**
         * @param array<string, mixed> $comment
         */
        private function checkForSpam(string $action, array $comment): string
        {
            return $this->service->checkForSpam(new UserCommentCheck($action, $comment))->commentAction;
        }

        public function test_check_for_spam_returns_reject_unchanged(): void
        {
            self::assertSame('reject', $this->checkForSpam('reject', ['content' => '', 'author' => '', 'image_id' => 1]));
        }

        public function test_check_for_spam_leaves_action_alone_for_a_non_guest(): void
        {
            CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Normal));

            self::assertSame('moderate', $this->checkForSpam('moderate', ['content' => 'hi', 'author' => 'a', 'image_id' => 1]));
        }

        public function test_check_for_spam_escalates_when_link_count_exceeds_the_max(): void
        {
            CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Guest));

            $content = 'http://a.test http://b.test http://c.test http://d.test';
            self::assertSame('reject', $this->checkForSpam('moderate', ['content' => $content, 'author' => 'a', 'image_id' => 1]));
            self::assertContains('links', $this->postCr());
        }

        public function test_check_for_spam_leaves_action_alone_under_the_link_limit(): void
        {
            CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Guest));

            self::assertSame('moderate', $this->checkForSpam('moderate', ['content' => 'http://a.test', 'author' => 'a', 'image_id' => 1]));
        }

        /**
         * The `$action === $myAction` early return -- reached only when
         * $action isn't 'reject' (that's the earlier early return) but
         * already matches what this method would itself compute. Disabling
         * comment_spam_reject makes $myAction 'moderate', so passing
         * 'moderate' in hits this exact branch before the isAGuest()
         * check is even reached.
         */
        public function test_check_for_spam_returns_action_unchanged_when_it_already_matches_my_action(): void
        {
            CurrentConfig::current()->setCommentSpamReject(false);

            self::assertSame('moderate', $this->checkForSpam('moderate', ['content' => 'no links here', 'author' => 'plain_name', 'image_id' => 1]));
        }

        /**
         * The author-name link count (`str_contains($author, 'http://')`)
         * is a second, independent source added to the content-derived
         * link count -- exercised here with zero content links so only
         * the author-name increment can be responsible for tipping past
         * max_links.
         */
        public function test_check_for_spam_counts_a_link_embedded_in_the_author_name(): void
        {
            CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Guest));
            CurrentConfig::current()->setCommentSpamMaxLinks(0);

            $action = $this->checkForSpam('moderate', ['content' => 'no links in here at all', 'author' => 'http://spammer.example', 'image_id' => 1]);

            self::assertSame('reject', $action);
            self::assertContains('links', $this->postCr());
        }

        // --- insertComment() --------------------------------------------------

        public function test_insert_comment_validates_immediately_when_validation_disabled(): void
        {
            CurrentConfig::current()->setCommentsValidation(false);

            $comm = $this->baseComm();
            $key = $this->validKey();
            $infos = [];

            $action = $this->service->insertComment($comm, $key, $infos);

            self::assertSame('validate', $action);
            self::assertSame([], $infos);
            $id = $this->insertedId($comm);
            self::assertSame('A perfectly fine comment.', $this->fetchColumn($id, 'content'));
            self::assertSame(1, $this->fetchValidated($id));
        }

        public function test_insert_comment_moderates_when_validation_required_and_not_admin(): void
        {
            $comm = $this->baseComm();
            $key = $this->validKey();
            $infos = [];

            $action = $this->service->insertComment($comm, $key, $infos);

            self::assertSame('moderate', $action);
            self::assertSame(0, $this->fetchValidated($this->insertedId($comm)));
        }

        public function test_insert_comment_rejects_empty_content(): void
        {
            $comm = $this->baseComm();
            $comm['content'] = '';
            $key = $this->validKey();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, $key, $infos));
            self::assertArrayNotHasKey('id', $comm);
        }

        public function test_insert_comment_rejects_an_invalid_key(): void
        {
            $comm = $this->baseComm();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, 'not-a-real-key', $infos));
            self::assertContains('key', $this->postCr());
            self::assertArrayNotHasKey('id', $comm);
        }

        public function test_insert_comment_rejects_a_guest_impersonating_an_existing_username(): void
        {
            CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Guest));

            $comm = $this->baseComm();
            $comm['author'] = 'fixture_admin';
            $key = $this->validKey();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, $key, $infos));
            self::assertContains('This login is already used by another user', $infos);
        }

        public function test_insert_comment_website_url_honeypot_rejected_when_disabled(): void
        {
            CurrentConfig::current()->setCommentsEnableWebsite(false);

            $comm = $this->baseComm();
            $comm['website_url'] = 'http://spam.example';
            $key = $this->validKey();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, $key, $infos));
            self::assertContains('website_url', $this->postCr());
        }

        public function test_insert_comment_rejects_a_malformed_email(): void
        {
            $comm = $this->baseComm();
            $comm['email'] = 'not-an-email';
            $key = $this->validKey();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, $key, $infos));
            self::assertNotSame([], $infos);
        }

        public function test_insert_comment_falls_back_to_the_current_users_email(): void
        {
            CurrentConfig::current()->setCommentsValidation(false);

            $comm = $this->baseComm();
            $comm['email'] = '';
            $key = $this->validKey();
            $infos = [];

            $this->service->insertComment($comm, $key, $infos);

            self::assertSame('fixture_admin@example.test', $this->fetchColumn($this->insertedId($comm), 'email'));
        }

        public function test_insert_comment_anti_flood_rejects_a_second_immediate_post(): void
        {
            CurrentConfig::current()->setCommentsValidation(false);
            CurrentConfig::current()->setAntiFloodTime(3600);
            CurrentUser::current()->set(User::fromUserArray(['id' => 3, 'status' => 'normal', 'username' => 'regular_user']));

            $first = $this->baseComm();
            $infos = [];
            $this->service->insertComment($first, $this->validKey(), $infos);

            $second = $this->baseComm();
            $infos = [];
            $action = $this->service->insertComment($second, $this->validKey(), $infos);

            self::assertSame('reject', $action);
            self::assertContains('flood_time', $this->postCr());
        }

        /**
         * Non-classic (guest) poster, empty author, comment_author_mandatory
         * on: rejected with the exact "Username is mandatory" message, and
         * (by-ref) $comm['author'] is still defaulted to 'guest' even
         * though the comment itself is rejected.
         */
        public function test_insert_comment_rejects_a_missing_author_when_mandatory(): void
        {
            CurrentUser::current()->set(User::fromUserArray(['id' => 6, 'status' => 'guest', 'username' => '']));
            CurrentConfig::current()->setCommentsAuthorMandatory(true);

            $comm = $this->baseComm();
            $infos = [];

            $action = $this->service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('reject', $action);
            self::assertContains('Username is mandatory', $infos);
            self::assertSame('guest', $comm['author']);
        }

        /**
         * Same empty-author guest post, but comment_author_mandatory is off
         * (the default): no rejection, $comm['author'] still defaults to
         * 'guest', and that literal value is what lands in the `author`
         * column.
         */
        public function test_insert_comment_defaults_a_missing_guest_author_to_guest(): void
        {
            CurrentConfig::current()->setCommentsValidation(false);
            CurrentUser::current()->set(User::fromUserArray(['id' => 6, 'status' => 'guest', 'username' => '']));

            $comm = $this->baseComm();
            $infos = [];

            $action = $this->service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('validate', $action);
            self::assertSame('guest', $comm['author']);
            self::assertSame('guest', $this->fetchColumn($this->insertedId($comm), 'author'));
        }

        /**
         * website_url strip+validate happy path: a scheme-less host with
         * an HTML tag embedded gets strip_tags()'d first, then prefixed
         * with 'http://' since it didn't already start with http(s), then
         * passes checkUrlFormat() -- no rejection, and the *stored* value
         * is the fully normalized one, not the raw input.
         */
        public function test_insert_comment_website_url_is_stripped_and_scheme_prefixed(): void
        {
            CurrentConfig::current()->setCommentsValidation(false);

            $comm = $this->baseComm();
            $comm['website_url'] = 'example.test/<b>promo</b>';
            $infos = [];

            $action = $this->service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('validate', $action);
            self::assertSame([], $infos);
            self::assertSame('http://example.test/promo', $this->fetchColumn($this->insertedId($comm), 'website_url'));
        }

        /**
         * A website_url containing a double quote fails checkUrlFormat()
         * even after the scheme-prefix step -- rejected with the exact
         * message, distinct from the honeypot (comments_enable_website
         * disabled) rejection path already covered above.
         */
        public function test_insert_comment_rejects_a_malformed_website_url(): void
        {
            $comm = $this->baseComm();
            $comm['website_url'] = '"><script>alert(1)</script>';
            $infos = [];

            $action = $this->service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('reject', $action);
            self::assertContains('Your website URL is invalid', $infos);
        }

        /**
         * Empty comm['email'], current user also has no email on file
         * (a guest with no email set), and comment_email_mandatory is on:
         * rejected with the exact message.
         */
        public function test_insert_comment_rejects_a_missing_email_when_mandatory(): void
        {
            CurrentUser::current()->set(User::fromUserArray(['id' => 6, 'status' => 'guest', 'username' => 'emailless_guest', 'email' => '']));
            CurrentConfig::current()->setCommentsEmailMandatory(true);

            $comm = $this->baseComm();
            $infos = [];

            $action = $this->service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('reject', $action);
            self::assertContains('Email address is missing. Please specify an email address.', $infos);
        }

        /**
         * email_admin_on_comment fires mailNotificationAdmins() with the
         * exact keyargs (author/email/content/blank-line/manage-url), and
         * *not* the "(!) This comment requires validation" line since the
         * comment was immediately validated, not moderated.
         */
        public function test_insert_comment_notifies_admins_by_mail_on_immediate_validation(): void
        {
            CurrentConfig::current()->setCommentsValidation(false);
            CurrentConfig::current()->setEmailAdminOnComment(true);

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $comm = $this->baseComm();
            $infos = [];
            $action = $service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('validate', $action);
            self::assertCount(1, $mailer->calls);
            $call = $mailer->calls[0];
            self::assertSame(['key_args' => ['Comment by %s', 'fixture_admin']], $call['subject']);
            self::assertSame([
                ['key_args' => ['Author: %s', 'fixture_admin']],
                ['key_args' => ['Email: %s', 'fixture_admin@example.test']],
                ['key_args' => ['Comment: %s', 'A perfectly fine comment.']],
                ['key_args' => ['', '']],
                ['key_args' => ['Manage this user comment: %s', $this->absoluteRootUrl() . 'comments.php?comment_id=' . $this->insertedId($comm)]],
            ], $call['content']);
        }

        /**
         * email_admin_on_comment_validation fires the same mail, this time
         * *with* the trailing "(!) This comment requires validation" line
         * since the comment was moderated, not immediately validated.
         */
        public function test_insert_comment_notifies_admins_by_mail_on_moderation(): void
        {
            CurrentConfig::current()->setEmailAdminOnCommentValidation(true);

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $comm = $this->baseComm();
            $infos = [];
            $action = $service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('moderate', $action);
            self::assertCount(1, $mailer->calls);
            $content = $mailer->calls[0]['content'];
            self::assertIsArray($content);
            $lastKey = array_key_last($content);
            self::assertNotNull($lastKey);
            self::assertSame(['key_args' => ['(!) This comment requires validation', '']], $content[$lastKey]);
        }

        // --- updateComment() --------------------------------------------------

        public function test_update_comment_rejects_an_invalid_key(): void
        {
            $comment = ['comment_id' => 2, 'image_id' => 2, 'content' => 'edited', 'website_url' => ''];

            self::assertSame('reject', $this->service->updateComment($comment, 'not-a-real-key'));
        }

        public function test_update_comment_moderates_when_validation_required(): void
        {
            // Impersonate comment 2's real owner (author_id 3) so the
            // UPDATE's own non-admin author_id restriction doesn't block
            // it -- can't use is_admin() here instead, since is_admin()
            // true would itself force the 'validate' branch, not
            // 'moderate'. updateComment() assumes the caller has already
            // authorized this exact edit, same as the real comments.php/
            // picture_comment.inc.php callers do via can_manage_comment()
            // before ever reaching this method.
            CurrentUser::current()->set(User::fromUserArray(['id' => 3, 'status' => 'normal', 'username' => 'regular_user']));
            $comment = ['comment_id' => 2, 'image_id' => 2, 'content' => 'edited content', 'website_url' => ''];

            $action = $this->service->updateComment($comment, $this->validKey(2));

            self::assertSame('moderate', $action);
            self::assertSame('edited content', $this->fetchColumn(2, 'content'));
            self::assertSame(0, $this->fetchValidated(2));
        }

        public function test_update_comment_invalid_website_url_appends_a_page_error_and_rejects(): void
        {
            $comment = ['comment_id' => 2, 'image_id' => 2, 'content' => 'edited', 'website_url' => '"><script>'];

            $action = $this->service->updateComment($comment, $this->validKey(2));

            self::assertSame('reject', $action);
            self::assertContains('Your website URL is invalid', $this->pageErrors());
        }

        /**
         * email_admin_on_comment_validation fires mailNotificationAdmins()
         * with the exact keyargs, including the trailing "(!) This comment
         * requires validation" line -- distinct from the plain "just mail
         * admin" (emailAdmin('edit', ...)) elseif branch exercised by
         * test_update_comment_moderates_when_validation_required above,
         * which never reaches that flag check because it's off there.
         */
        public function test_update_comment_notifies_admins_by_mail_on_moderation(): void
        {
            CurrentConfig::current()->setEmailAdminOnCommentValidation(true);
            CurrentUser::current()->set(User::fromUserArray(['id' => 3, 'status' => 'normal', 'username' => 'mail_dispatch_owner']));
            $comment = ['comment_id' => 2, 'image_id' => 2, 'content' => 'edited for mail dispatch', 'website_url' => ''];

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $action = $service->updateComment($comment, $this->validKey(2));

            self::assertSame('moderate', $action);
            self::assertCount(1, $mailer->calls);
            $call = $mailer->calls[0];
            self::assertSame(['key_args' => ['Comment by %s', 'mail_dispatch_owner']], $call['subject']);
            self::assertSame([
                ['key_args' => ['Author: %s', 'mail_dispatch_owner']],
                ['key_args' => ['Comment: %s', 'edited for mail dispatch']],
                ['key_args' => ['', '']],
                ['key_args' => ['Manage this user comment: %s', $this->absoluteRootUrl() . 'comments.php?comment_id=2']],
                ['key_args' => ['(!) This comment requires validation', '']],
            ], $call['content']);
        }

        // --- deleteComment() ----------------------------------------------

        public function test_delete_comment_returns_false_for_a_missing_comment(): void
        {
            CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Admin));

            self::assertFalse($this->service->deleteComment(CommentId::from(999999)));
        }

        public function test_delete_comment_removes_as_admin(): void
        {
            CurrentUser::current()->set(CurrentUser::current()->get()->withStatus(UserStatus::Admin));

            self::assertTrue($this->service->deleteComment(CommentId::from(3)));
            self::assertNull($this->fetchColumn(3, 'content'));
        }

        public function test_delete_comment_denied_for_a_non_owning_user(): void
        {
            CurrentUser::current()->set(User::fromUserArray(['id' => 999, 'status' => 'normal', 'username' => 'someone-else']));

            self::assertFalse($this->service->deleteComment(CommentId::from(4))); // owned by author_id 4
            self::assertNotNull($this->fetchColumn(4, 'content'));
        }

        public function test_delete_comment_allowed_for_the_owning_user(): void
        {
            CurrentUser::current()->set(User::fromUserArray(['id' => 4, 'status' => 'normal', 'username' => 'power_user']));

            self::assertTrue($this->service->deleteComment(CommentId::from(4)));
        }

        // --- validateComment() ----------------------------------------------

        public function test_validate_comment_marks_it_validated(): void
        {
            self::assertSame(0, $this->fetchValidated(5));

            $this->service->validateComment(CommentId::from(5));

            self::assertSame(1, $this->fetchValidated(5));
        }

        // --- getCommentAuthorId() --------------------------------------------

        public function test_get_comment_author_id_returns_the_owner(): void
        {
            self::assertSame(1, $this->service->getCommentAuthorId(CommentId::from(1)));
        }

        public function test_get_comment_author_id_returns_false_without_dying_when_missing(): void
        {
            self::assertFalse($this->service->getCommentAuthorId(CommentId::from(999999), false));
        }

        /**
         * author_id is nullable in schema (anonymous/guest comment with no
         * owner) -- this is a distinct state from "comment doesn't exist",
         * see CommentRepository::findAuthorId(). A prior version of
         * getCommentAuthorId() collapsed both states down to `false`,
         * which then flowed into AccessControl::canManageComment()'s
         * strictly-typed `int|string` parameter and crashed with a
         * TypeError as soon as assert() (a no-op under this project's
         * zend.assertions=-1) failed to catch it.
         */
        public function test_get_comment_author_id_returns_null_for_an_anonymous_comment(): void
        {
            $id = $this->insertAnonymousComment();

            self::assertNull($this->service->getCommentAuthorId(CommentId::from($id)));
        }

        public function test_get_comment_author_id_null_flows_safely_into_can_manage_comment(): void
        {
            $id = $this->insertAnonymousComment();
            $authorId = $this->service->getCommentAuthorId(CommentId::from($id));
            self::assertNotFalse($authorId); // dieOnError defaults to true; see getCommentAuthorId()'s docblock

            self::assertFalse($this->accessControl()->canManageComment('edit', $authorId));
        }

        /**
         * $dieOnError defaults to true: an unknown identifier routes to
         * HtmlRenderingInterface::fatalError() (`never`-returning), unlike
         * the false-$dieOnError case above which just returns false.
         */
        public function test_get_comment_author_id_dies_on_an_unknown_identifier_by_default(): void
        {
            $service = $this->serviceWithHtmlRenderer(new CommentServiceFakeHtmlRendererThrowsOnFatalError());

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('COMMENT_SERVICE_FATAL_ERROR_MARKER: Unknown comment identifier');

            $service->getCommentAuthorId(CommentId::from(999999));
        }

        // --- emailAdmin() -----------------------------------------------------

        public function test_email_admin_notifies_on_delete_with_the_comment_id(): void
        {
            CurrentConfig::current()->setEmailAdminOnCommentDeletion(true);

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $service->emailAdmin('delete', ['author' => 'evicted_author', 'comment_id' => 42]);

            self::assertCount(1, $mailer->calls);
            $call = $mailer->calls[0];
            self::assertSame(['key_args' => ['Comment by %s', 'evicted_author']], $call['subject']);
            self::assertSame([
                ['key_args' => ['Author: %s', 'evicted_author']],
                ['key_args' => ['This author removed the comment with id %d', 42]],
            ], $call['content']);
        }

        public function test_email_admin_notifies_on_edit_with_the_new_content(): void
        {
            CurrentConfig::current()->setEmailAdminOnCommentEdition(true);

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $service->emailAdmin('edit', ['author' => 'editing_author', 'content' => 'the revised text']);

            self::assertCount(1, $mailer->calls);
            $call = $mailer->calls[0];
            self::assertSame(['key_args' => ['Comment by %s', 'editing_author']], $call['subject']);
            self::assertSame([
                ['key_args' => ['Author: %s', 'editing_author']],
                ['key_args' => ['This author modified following comment:', '']],
                ['key_args' => ['Comment: %s', 'the revised text']],
            ], $call['content']);
        }

        public function test_email_admin_does_nothing_for_an_unrecognized_action(): void
        {
            CurrentConfig::current()->setEmailAdminOnCommentDeletion(true);
            CurrentConfig::current()->setEmailAdminOnCommentEdition(true);

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $service->emailAdmin('rename', ['author' => 'someone']);

            self::assertSame([], $mailer->calls);
        }

        // --- invalidateNbCommentsCache() -------------------------------------

        /**
         * Gap-closure Stage 4f (docs/plan/gap-closure-p0-p23.md): this no
         * longer touches `user_cache.nb_available_comments` at all -- that
         * write was confirmed dead (the read side only ever consults
         * CurrentUser::rawAttributes, never the DB column) and deleted
         * outright, along with CommentRepository::clearNbCommentsCache().
         */
        public function test_get_nb_available_comments_counts_only_validated_comments_for_a_non_admin_user(): void
        {
            // Item 16I: countAvailableWithConditions() converted to real
            // DQL, and this real caller's own condition fragments
            // (com.validated/ic.categoryId/ic.imageId, wired up in
            // CommentService itself) had zero direct test coverage --
            // CommentRepositoryTest's own countAvailableWithConditions()
            // tests exercise the same repository mechanism, but never
            // through this exact caller.
            $repo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Comment\CommentEntity::class);
            $baseline = CommentService::getNbAvailableComments();

            $validatedId = $repo->insert(['author' => 'nbc-test', 'authorId' => null, 'anonymousId' => '10.40.0.1', 'content' => 'nbc validated', 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);
            $unvalidatedId = $repo->insert(['author' => 'nbc-test', 'authorId' => null, 'anonymousId' => '10.40.0.2', 'content' => 'nbc unvalidated', 'validated' => false, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);

            try {
                // Busts getNbAvailableComments()'s own per-request cache
                // (CurrentUser::rawAttributes['nb_available_comments']) so
                // the second call genuinely recomputes.
                CurrentUser::current()->set(CurrentUser::current()->get()->withRawAttribute('nb_available_comments', null));

                $afterInsert = CommentService::getNbAvailableComments();

                self::assertSame($baseline + 1, $afterInsert, 'only the validated comment should count');
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::comments() . ' WHERE id IN (?, ?)', [$validatedId->value, $unvalidatedId->value]);
            }
        }

        public function test_invalidate_nb_comments_cache_unsets_the_global(): void
        {
            CurrentUser::current()->set(CurrentUser::current()->get()->withRawAttribute('nb_available_comments', 5));

            $this->service->invalidateNbCommentsCache();

            self::assertFalse(isset(CurrentUser::current()->get()->rawAttributes['nb_available_comments']));
        }

        /**
         * @return list<string>
         */
        private function postCr(): array
        {
            return \Piwigo\Core\PageState::current()->commentRejectionReasons;
        }

        /**
         * @return list<string>
         */
        private function pageErrors(): array
        {
            return \Piwigo\Core\PageState::current()->errors;
        }

        /**
         * A CommentService wired to a fake MailerInterface so the
         * admin-notification mail dispatch paths (insertComment()/
         * updateComment()/emailAdmin()) can be asserted on exactly,
         * without a real Mail\MailService (Symfony Mailer) round trip.
         */
        private function serviceWithMailer(MailerInterface $mailer): CommentService
        {
            return new CommentService(
                \Piwigo\Core\Lang::current(),
                \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Comment\CommentEntity::class),
                new EphemeralKeyService(\Piwigo\Config\CurrentConfig::current()),
                $mailer,
                new HtmlService(),
                new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()),
                new \Piwigo\PluginConfig\EventDispatcher(),
                \Piwigo\Core\PageState::current(),
                \Piwigo\Users\CurrentUser::current(),
                \Piwigo\Config\CurrentConfig::current(),
            );
        }

        /**
         * A CommentService wired to a fake HtmlRenderingInterface so
         * getCommentAuthorId()'s fatalError() dispatch can be observed
         * without a real HtmlService (which throws a ResponseReadyException
         * carrying rendered HTML, not a simple assertable marker).
         */
        private function serviceWithHtmlRenderer(HtmlRenderingInterface $htmlRenderer): CommentService
        {
            return new CommentService(
                \Piwigo\Core\Lang::current(),
                \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Comment\CommentEntity::class),
                new EphemeralKeyService(\Piwigo\Config\CurrentConfig::current()),
                new MailService(),
                $htmlRenderer,
                new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()),
                new \Piwigo\PluginConfig\EventDispatcher(),
                \Piwigo\Core\PageState::current(),
                \Piwigo\Users\CurrentUser::current(),
                \Piwigo\Config\CurrentConfig::current(),
            );
        }

        private function absoluteRootUrl(): string
        {
            return (new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()))->getAbsoluteRootUrl();
        }

        private function insertedId(mixed $comm): int
        {
            self::assertIsArray($comm);
            self::assertIsInt($comm['id'] ?? null);

            return $comm['id'];
        }

        /**
         * insertComment() always assigns a real author_id (a registered
         * user's id, or CurrentConfig::guestId() for anonymous posters) --
         * a genuinely NULL author_id only ever occurs for legacy/imported
         * data or a directly-owned user row later deleted, which the
         * schema (`author_id` nullable) and CommentRepository::
         * findAuthorId() both explicitly support. Insert directly to
         * reproduce that state.
         */
        private function insertAnonymousComment(int $imageId = 1): int
        {
            $this->conn->insert(Tables::comments(), [
                'image_id' => $imageId,
                'date' => '2026-08-01 00:00:00',
                'author' => 'anonymous',
                'author_id' => null,
                'anonymous_id' => '127.0.0.4',
                'content' => 'Anonymous comment with no owner.',
                'validated' => 1,
            ]);

            return (int) $this->conn->lastInsertId();
        }

        /**
         * @return array{author: string, content: string, website_url: string, email: string, image_id: int}
         */
        private function baseComm(int $imageId = 1): array
        {
            return [
                'author' => '',
                'content' => 'A perfectly fine comment.',
                'website_url' => '',
                'email' => '',
                'image_id' => $imageId,
            ];
        }

        /**
         * A genuine generate()-then-verify() round trip is inherently racy:
         * EphemeralKeyService::generate()'s round(microtime(true), 1) can
         * round up to 0.1s ahead of the raw instant it was measured at, so
         * an immediate verify() can occasionally see the key as "from the
         * future". Same hand-crafted, 1-second-old key workaround as
         * tests/Unit/Auth/EphemeralKeyServiceTest.php.
         */
        private function validKey(int $imageId = 1): string
        {
            $issuedAt = round(microtime(true), 1) - 1.0;
            $remoteAddr = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
            $secretKey = CurrentConfig::current()->secretKey();
            $signature = hash_hmac('sha256', (string) $issuedAt . substr($remoteAddr, 0, 5) . '0' . $imageId, $secretKey);

            return (string) $issuedAt . ':0:' . $signature;
        }

        private function fetchColumn(int $commentId, string $column): ?string
        {
            $value = $this->conn->createQueryBuilder()
                ->select($column)
                ->from(Tables::comments())
                ->where('id = :id')
                ->setParameter('id', $commentId)
                ->executeQuery()
                ->fetchOne();

            return is_string($value) ? $value : null;
        }

        /**
         * validated is a real tinyint(1) column now (Comment domain Stage
         * 1a) -- fetchColumn()'s is_string() narrowing would always
         * return null for it, same reasoning as CommentRepositoryTest's
         * own fetchValidated().
         */
        private function fetchValidated(int $commentId): ?int
        {
            $value = $this->conn->createQueryBuilder()
                ->select('validated')
                ->from(Tables::comments())
                ->where('id = :id')
                ->setParameter('id', $commentId)
                ->executeQuery()
                ->fetchOne();

            return is_bool($value) || is_numeric($value) ? (int) (bool) $value : null;
        }
    }
}
