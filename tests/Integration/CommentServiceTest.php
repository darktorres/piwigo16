<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use Override;
    use Piwigo\Auth\AccessControl;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Auth\EphemeralKeyService;
    use Piwigo\Comment\AvailableCommentsCounter;
    use Piwigo\Comment\CommentEntity;
    use Piwigo\Comment\CommentRepository;
    use Piwigo\Comment\CommentService;
    use Piwigo\Comment\Event\UserCommentCheck;
    use Piwigo\Comment\Projection\CommentInsertData;
    use Piwigo\Common\ValueObject\CommentId;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\HtmlRenderingInterface;
    use Piwigo\Core\HttpStatusLine;
    use Piwigo\Core\Kernel;
    use Piwigo\Core\MailerInterface;
    use Piwigo\Core\Projection\MailArgs;
    use Piwigo\Core\Projection\MailOptions;
    use Piwigo\Core\RedirectServiceInterface;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Db\TypedRepository;
    use Piwigo\Mail\MailService;
    use Piwigo\Permission\PermissionService;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\DbTransactionTestOverride;
    use Piwigo\Tests\Support\HtmlServiceTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\PageStateTestFactory;
    use Piwigo\Tests\Support\UrlServiceTestFactory;
    use Piwigo\Users\User;
    use Piwigo\Users\UserStatus;
    use RuntimeException;

    /**
     * insertComment()/updateComment()'s admin-notification mail dispatch
     * and emailAdminOnEdit()/emailAdminOnDelete() themselves build exact
     * keyargsContent arrays passed to MailerInterface::
     * mailNotificationAdmins() -- this fake records every call verbatim so
     * tests can assert on that exact shape without a real Mail\MailService
     * (Symfony Mailer) round trip.
     */
    final class CommentServiceFakeMailerRecordsNotifications implements MailerInterface
    {
        /**
         * @var list<array{subject: string|array{key_args: array<int, mixed>}, content: string|list<array{key_args: array<int, mixed>}>, sendTechnicalDetails: bool, groupId: int|string|null}>
         */
        public array $calls = [];

        #[Override]
        public function mail(string|array $to, ?MailArgs $args = null, ?MailOptions $tpl = null): bool
        {
            throw new LogicException("not used by CommentService's mail dispatch paths");
        }

        #[Override]
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
        #[Override]
        public function getCatDisplayName(array $catInformations, ?string $url = ''): string
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function getCatDisplayNameCache(
            string $uppercats,
            ?string $url = '',
            bool $singleLink = false,
            ?string $linkClass = null,
            ?string $authKey = null,
        ): string {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function getCatBreadcrumb(string $uppercats): array
        {
            return [];
        }

        #[Override]
        public function nameCompare(array $a, array $b): int
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function tagAlphaCompare(array $a, array $b): int
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function accessDenied(RedirectServiceInterface $redirectService): never
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
        {
            throw new RuntimeException('COMMENT_SERVICE_FATAL_ERROR_MARKER: ' . $msg);
        }

        #[Override]
        public function getTagsContentTitle(array $tags): string
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function setStatusHeader(int $code, string $text = ''): HttpStatusLine
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function renderElementName(array $info): string
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function renderElementDescription(array $info, string $param = ''): string
        {
            throw new LogicException('not used by getCommentAuthorId()');
        }

        #[Override]
        public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
        {
            throw new LogicException('not used by getCommentAuthorId()');
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

        #[Override]
        protected function setUp(): void
        {
            parent::setUp();
            $this->setUpConnectionFromEnv();

            if (! self::$fixtureReady) {
                $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
                self::$fixtureReady = true;
            }

            // PILOT (transaction-wrapping rollout): begin before any container
            // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
            // comment for the full reasoning.
            DbTransactionTestOverride::begin();

            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            CurrentConfigTestFactory::get()->secretKey = 'test-secret-key';
            CurrentConfigTestFactory::get()->commentsValidation = true;
            CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
            CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
            CurrentConfigTestFactory::get()->commentsEnableWebsite = true;
            CurrentConfigTestFactory::get()->commentSpamReject = true;
            CurrentConfigTestFactory::get()->commentSpamMaxLinks = 3;
            CurrentConfigTestFactory::get()->antiFloodTime = 0;
            CurrentConfigTestFactory::get()->guestId = 2;
            CurrentConfigTestFactory::get()->guestAccess = true;
            CurrentConfigTestFactory::get()->emailAdminOnComment = false;
            CurrentConfigTestFactory::get()->emailAdminOnCommentValidation = false;
            CurrentConfigTestFactory::get()->emailAdminOnCommentEdition = false;
            CurrentConfigTestFactory::get()->emailAdminOnCommentDeletion = false;
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 1,
                'status' => 'normal',
                'username' => 'fixture_admin',
                'email' => 'fixture_admin@example.test',
            ]));
            PageStateTestFactory::get()->reset();

            $this->conn = DbConnection::build();
            $mailer = Kernel::container()->get(MailService::class);
            self::assertInstanceOf(MailService::class, $mailer);
            $this->service = new CommentService(LangTestFactory::get(), TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(CommentEntity::class), CommentRepository::class), new EphemeralKeyService(CurrentConfigTestFactory::get()), $mailer, HtmlServiceTestFactory::build(), UrlServiceTestFactory::build(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), $this->accessLevelChecker());
        }

        #[Override]
        protected function tearDown(): void
        {
            DbTransactionTestOverride::rollback();
            parent::tearDown();
        }

        private function accessControl(): AccessControl
        {
            // Kernel is already booted by parent::setUp() above -- resolve
            // the same container-shared instance a real request would get.
            $accessControl = Kernel::container()->get(AccessControl::class);
            if (! $accessControl instanceof AccessControl) {
                throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
            }

            return $accessControl;
        }

        private function accessLevelChecker(): AccessLevelChecker
        {
            $accessLevelChecker = Kernel::container()->get(AccessLevelChecker::class);
            if (! $accessLevelChecker instanceof AccessLevelChecker) {
                throw new LogicException('Container returned an unexpected type for ' . AccessLevelChecker::class);
            }

            return $accessLevelChecker;
        }

        private function permissionService(): PermissionService
        {
            $permissionService = Kernel::container()->get(PermissionService::class);
            if (! $permissionService instanceof PermissionService) {
                throw new LogicException('Container returned an unexpected type for ' . PermissionService::class);
            }

            return $permissionService;
        }

        // --- checkForSpam() -------------------------------------------------

        /**
         * @param array<string, mixed> $comment
         */
        private function checkForSpam(string $action, array $comment): string
        {
            return $this->service->checkForSpam(new UserCommentCheck($action, $comment))
                ->commentAction;
        }

        public function testCheckForSpamReturnsRejectUnchanged(): void
        {
            self::assertSame('reject', $this->checkForSpam('reject', [
                'content' => '',
                'author' => '',
                'image_id' => 1,
            ]));
        }

        public function testCheckForSpamLeavesActionAloneForANonGuest(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Normal));

            self::assertSame('moderate', $this->checkForSpam('moderate', [
                'content' => 'hi',
                'author' => 'a',
                'image_id' => 1,
            ]));
        }

        public function testCheckForSpamEscalatesWhenLinkCountExceedsTheMax(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Guest));

            $content = 'http://a.test http://b.test http://c.test http://d.test';
            self::assertSame('reject', $this->checkForSpam('moderate', [
                'content' => $content,
                'author' => 'a',
                'image_id' => 1,
            ]));
            self::assertContains('links', $this->postCr());
        }

        public function testCheckForSpamLeavesActionAloneUnderTheLinkLimit(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Guest));

            self::assertSame('moderate', $this->checkForSpam('moderate', [
                'content' => 'http://a.test',
                'author' => 'a',
                'image_id' => 1,
            ]));
        }

        /**
         * The `$action === $myAction` early return -- reached only when
         * $action isn't 'reject' (that's the earlier early return) but
         * already matches what this method would itself compute. Disabling
         * comment_spam_reject makes $myAction 'moderate', so passing
         * 'moderate' in hits this exact branch before the isAGuest()
         * check is even reached.
         */
        public function testCheckForSpamReturnsActionUnchangedWhenItAlreadyMatchesMyAction(): void
        {
            CurrentConfigTestFactory::get()->commentSpamReject = false;

            self::assertSame('moderate', $this->checkForSpam('moderate', [
                'content' => 'no links here',
                'author' => 'plain_name',
                'image_id' => 1,
            ]));
        }

        /**
         * The author-name link count (`str_contains($author, 'http://')`)
         * is a second, independent source added to the content-derived
         * link count -- exercised here with zero content links so only
         * the author-name increment can be responsible for tipping past
         * max_links.
         */
        public function testCheckForSpamCountsALinkEmbeddedInTheAuthorName(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Guest));
            CurrentConfigTestFactory::get()->commentSpamMaxLinks = 0;

            $action = $this->checkForSpam('moderate', [
                'content' => 'no links in here at all',
                'author' => 'http://spammer.example',
                'image_id' => 1,
            ]);

            self::assertSame('reject', $action);
            self::assertContains('links', $this->postCr());
        }

        // --- insertComment() --------------------------------------------------

        public function testInsertCommentValidatesImmediatelyWhenValidationDisabled(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;

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

        public function testInsertCommentModeratesWhenValidationRequiredAndNotAdmin(): void
        {
            $comm = $this->baseComm();
            $key = $this->validKey();
            $infos = [];

            $action = $this->service->insertComment($comm, $key, $infos);

            self::assertSame('moderate', $action);
            self::assertSame(0, $this->fetchValidated($this->insertedId($comm)));
        }

        /**
         * $comm->ip is written by insertComment() (object mutation, not
         * a by-ref param) -- asserted directly here since
         * no existing helper fetches the `anonymous_id` column. A real,
         * valid REMOTE_ADDR
         * (rather than baseComm()'s ambient empty/unset one) is what
         * actually exercises IpAddress::fromRemoteAddr()'s non-null path,
         * distinct from the `?? ''` fallback covered separately below.
         */
        public function testInsertCommentRecordsTheRemoteAddressAsIp(): void
        {
            $originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

            try {
                $comm = $this->baseComm();
                $infos = [];

                $this->service->insertComment($comm, $this->validKey(), $infos);

                self::assertSame('203.0.113.42', $comm->ip);
            } finally {
                if ($originalRemoteAddr === null) {
                    unset($_SERVER['REMOTE_ADDR']);
                } else {
                    $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
                }
            }
        }

        /**
         * The `?? ''` fallback only matters when IpAddress::
         * fromRemoteAddr() itself returns null (no REMOTE_ADDR) --
         * distinct from the "records a real address" case above, which
         * never reaches the fallback at all.
         */
        public function testInsertCommentDefaultsIpToEmptyStringWithoutARemoteAddress(): void
        {
            $originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            unset($_SERVER['REMOTE_ADDR']);

            try {
                $comm = $this->baseComm();
                $infos = [];

                $this->service->insertComment($comm, $this->validKey(), $infos);

                self::assertSame('', $comm->ip);
            } finally {
                if ($originalRemoteAddr !== null) {
                    $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
                }
            }
        }

        /**
         * $comm->agent is written by insertComment() -- HTTP_USER_AGENT only
         * reaches it via the `?? null` coalesce and the is_string()
         * ternary just below it, both otherwise unexercised since no
         * other test in this file ever sets
         * $_SERVER['HTTP_USER_AGENT'].
         */
        public function testInsertCommentRecordsTheUserAgentWhenPresent(): void
        {
            $originalUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $_SERVER['HTTP_USER_AGENT'] = 'PiwigoTestClient/1.0';

            try {
                $comm = $this->baseComm();
                $infos = [];

                $this->service->insertComment($comm, $this->validKey(), $infos);

                self::assertSame('PiwigoTestClient/1.0', $comm->agent);
            } finally {
                if ($originalUserAgent === null) {
                    unset($_SERVER['HTTP_USER_AGENT']);
                } else {
                    $_SERVER['HTTP_USER_AGENT'] = $originalUserAgent;
                }
            }
        }

        /**
         * Without HTTP_USER_AGENT at all (the ambient state every other
         * test in this file already relies on), $http_user_agent is
         * null -- is_string(null) is false, so $comm->agent falls to
         * the ternary's own '' branch, not $http_user_agent itself.
         */
        public function testInsertCommentDefaultsAgentToEmptyStringWithoutAUserAgent(): void
        {
            $originalUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            unset($_SERVER['HTTP_USER_AGENT']);

            try {
                $comm = $this->baseComm();
                $infos = [];

                $this->service->insertComment($comm, $this->validKey(), $infos);

                self::assertSame('', $comm->agent);
            } finally {
                if ($originalUserAgent !== null) {
                    $_SERVER['HTTP_USER_AGENT'] = $originalUserAgent;
                }
            }
        }

        public function testInsertCommentRejectsEmptyContent(): void
        {
            $comm = $this->baseComm();
            $comm->content = '';
            $key = $this->validKey();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, $key, $infos));
            self::assertNull($comm->id);
        }

        public function testInsertCommentRejectsAnInvalidKey(): void
        {
            $comm = $this->baseComm();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, 'not-a-real-key', $infos));
            self::assertContains('key', $this->postCr());
            self::assertNull($comm->id);
        }

        public function testInsertCommentRejectsAGuestImpersonatingAnExistingUsername(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Guest));

            $comm = $this->baseComm();
            $comm->author = 'fixture_admin';
            $key = $this->validKey();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, $key, $infos));
            self::assertContains('This login is already used by another user', $infos);
        }

        public function testInsertCommentWebsiteUrlHoneypotRejectedWhenDisabled(): void
        {
            CurrentConfigTestFactory::get()->commentsEnableWebsite = false;

            $comm = $this->baseComm();
            $comm->websiteUrl = 'http://spam.example';
            $key = $this->validKey();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, $key, $infos));
            self::assertContains('website_url', $this->postCr());
        }

        public function testInsertCommentRejectsAMalformedEmail(): void
        {
            $comm = $this->baseComm();
            $comm->email = 'not-an-email';
            $key = $this->validKey();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, $key, $infos));
            self::assertNotSame([], $infos);
        }

        public function testInsertCommentFallsBackToTheCurrentUsersEmail(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;

            $comm = $this->baseComm();
            $comm->email = '';
            $key = $this->validKey();
            $infos = [];

            $this->service->insertComment($comm, $key, $infos);

            self::assertSame('fixture_admin@example.test', $this->fetchColumn($this->insertedId($comm), 'email'));
        }

        public function testInsertCommentAntiFloodRejectsASecondImmediatePost(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;
            CurrentConfigTestFactory::get()->antiFloodTime = 3600;
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 3,
                'status' => 'normal',
                'username' => 'regular_user',
            ]));

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
         * antiFloodTime=1 (rather than the 3600 used above) is what
         * actually exercises the `> 0` boundary itself: any
         * antiFloodTime > 0 still enters the anti-flood block either
         * way, but the frozen PIWIGO_TEST_NOW clock (see .env.test)
         * makes every freshly-inserted comment's own `date` exactly
         * equal to "now", so a window of at least 1 second is what's
         * needed for `c.date > DATE_SUB(now, window, 'second')` to
         * genuinely evaluate true and trigger the rejection.
         */
        public function testInsertCommentAntiFloodTriggersWithAOneSecondWindow(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;
            CurrentConfigTestFactory::get()->antiFloodTime = 1;
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 3,
                'status' => 'normal',
                'username' => 'regular_user',
            ]));

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
         * The anti-flood block's own `$commentAction !== 'reject'`
         * guard means a comment already rejected for another reason
         * (an invalid key here) must never also pick up a spurious
         * 'flood_time' rejection reason, even when an
         * antiFloodTime-eligible recent comment from the same author
         * genuinely exists.
         */
        public function testInsertCommentSkipsTheAntiFloodCheckWhenAlreadyRejectedForAnotherReason(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;
            CurrentConfigTestFactory::get()->antiFloodTime = 3600;
            // Deliberately id 2, not the more obviously-named 3 ("regular_
            // user") the sibling anti-flood tests above use: countRecentComments()
            // (CommentRepository) only filters by author_id (and, for
            // guests, an IP prefix) -- it does NOT scope by image_id at
            // all -- so ANY existing comment for an author counts as
            // "recent" against a 3600s window, including the real fixture
            // comment already seeded for author_id 3 (id 2). An
            // author_id-only DELETE to clear that row would break an
            // unrelated later test that depends on it
            // (test_update_comment_moderates_when_validation_required),
            // and narrowing the DELETE to this test's own image_id doesn't
            // help since the fixture row's image_id differs but still
            // counts. Id 2 is a real, FK-valid users row (needed --
            // insertComment()'s own INSERT fails fk_comments_author_id
            // otherwise) that starts with zero comments in the fixture and
            // is never reused as a comment author anywhere else in this
            // file. (It happens to be this file's own
            // configured guestId(), but insertComment() only ever *writes*
            // guestId() into $comm->authorId for a real guest poster,
            // never *compares* against it, so status: 'normal' here takes
            // the classic-user branch cleanly regardless.)
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 2,
                'status' => 'normal',
                'username' => 'flood-skip-test-user',
            ]));

            $first = $this->baseComm();
            $infos = [];
            $this->service->insertComment($first, $this->validKey(), $infos);

            $second = $this->baseComm();
            $infos = [];
            $action = $this->service->insertComment($second, 'not-a-real-key', $infos);

            self::assertSame('reject', $action);
            self::assertContains('key', $this->postCr());
            self::assertNotContains('flood_time', $this->postCr());
        }

        /**
         * Anti-flood's own IP-prefix trimming (dropping the last octet
         * before building the LIKE '<prefix>.%' pattern) only matters
         * for a non-classic (guest) poster -- countRecentComments()'s
         * $authorId alone can't distinguish two different anonymous
         * posters sharing the same configured guestId, so the flood
         * check narrows by anonymous_id too. Needs a real, 4-octet
         * REMOTE_ADDR (rather than baseComm()'s ambient empty/unset
         * one) to actually exercise explode('.', ...)'s count() > 3
         * branch and its last-octet pop.
         */
        public function testInsertCommentAntiFloodMatchesAGuestByTrimmedIpPrefix(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;
            CurrentConfigTestFactory::get()->antiFloodTime = 3600;
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 6,
                'status' => 'guest',
                'username' => 'flood_guest',
            ]));

            $originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

            try {
                $first = $this->baseComm();
                $infos = [];
                $this->service->insertComment($first, $this->validKey(), $infos);

                $second = $this->baseComm();
                $infos = [];
                $action = $this->service->insertComment($second, $this->validKey(), $infos);

                self::assertSame('reject', $action);
                self::assertContains('flood_time', $this->postCr());
            } finally {
                if ($originalRemoteAddr === null) {
                    unset($_SERVER['REMOTE_ADDR']);
                } else {
                    $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
                }
            }
        }

        /**
         * Non-classic (guest) poster, empty author, comment_author_mandatory
         * on: rejected with the exact "Username is mandatory" message, and
         * $comm->author is still defaulted to 'guest' even
         * though the comment itself is rejected.
         */
        public function testInsertCommentRejectsAMissingAuthorWhenMandatory(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 6,
                'status' => 'guest',
                'username' => '',
            ]));
            CurrentConfigTestFactory::get()->commentsAuthorMandatory = true;

            $comm = $this->baseComm();
            $infos = [];

            $action = $this->service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('reject', $action);
            self::assertContains('Username is mandatory', $infos);
            self::assertSame('guest', $comm->author);
        }

        /**
         * Same empty-author guest post, but comment_author_mandatory is off
         * (the default): no rejection, $comm->author still defaults to
         * 'guest', and that literal value is what lands in the `author`
         * column.
         */
        public function testInsertCommentDefaultsAMissingGuestAuthorToGuest(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 6,
                'status' => 'guest',
                'username' => '',
            ]));

            $comm = $this->baseComm();
            $infos = [];

            $action = $this->service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('validate', $action);
            self::assertSame('guest', $comm->author);
            self::assertSame('guest', $this->fetchColumn($this->insertedId($comm), 'author'));
        }

        /**
         * website_url strip+validate happy path: a scheme-less host with
         * an HTML tag embedded gets strip_tags()'d first, then prefixed
         * with 'http://' since it didn't already start with http(s), then
         * passes checkUrlFormat() -- no rejection, and the *stored* value
         * is the fully normalized one, not the raw input.
         */
        public function testInsertCommentWebsiteUrlIsStrippedAndSchemePrefixed(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;

            $comm = $this->baseComm();
            $comm->websiteUrl = 'example.test/<b>promo</b>';
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
        public function testInsertCommentRejectsAMalformedWebsiteUrl(): void
        {
            $comm = $this->baseComm();
            $comm->websiteUrl = '"><script>alert(1)</script>';
            $infos = [];

            $action = $this->service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('reject', $action);
            self::assertContains('Your website URL is invalid', $infos);
        }

        /**
         * `?? null` only matters for a genuinely null $websiteUrl
         * (`?string $websiteUrl = null` on CommentInsertData) --
         * baseComm() always sets it to '', which already exercises
         * self::emptyValue('') but never the null branch this coalesce
         * actually guards. With a null value, emptyValue() must still
         * treat this as empty (matching empty()'s own null semantics)
         * and skip the honeypot/URL-validation block entirely.
         */
        public function testInsertCommentTreatsAMissingWebsiteUrlKeyAsEmpty(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;

            $comm = $this->baseComm();
            $comm->websiteUrl = null;
            $infos = [];

            $action = $this->service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('validate', $action);
            self::assertSame([], $infos);
        }

        /**
         * Empty comm['email'], current user also has no email on file
         * (a guest with no email set), and comment_email_mandatory is on:
         * rejected with the exact message.
         */
        public function testInsertCommentRejectsAMissingEmailWhenMandatory(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 6,
                'status' => 'guest',
                'username' => 'emailless_guest',
                'email' => '',
            ]));
            CurrentConfigTestFactory::get()->commentsEmailMandatory = true;

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
        public function testInsertCommentNotifiesAdminsByMailOnImmediateValidation(): void
        {
            CurrentConfigTestFactory::get()->commentsValidation = false;
            CurrentConfigTestFactory::get()->emailAdminOnComment = true;

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $comm = $this->baseComm();
            $infos = [];
            $action = $service->insertComment($comm, $this->validKey(), $infos);

            self::assertSame('validate', $action);
            self::assertCount(1, $mailer->calls);
            $call = $mailer->calls[0];
            self::assertSame([
                'key_args' => ['Comment by %s', 'fixture_admin'],
            ], $call['subject']);
            self::assertSame([
                [
                    'key_args' => ['Author: %s', 'fixture_admin'],
                ],
                [
                    'key_args' => ['Email: %s', 'fixture_admin@example.test'],
                ],
                [
                    'key_args' => ['Comment: %s', 'A perfectly fine comment.'],
                ],
                [
                    'key_args' => ['', ''],
                ],
                [
                    'key_args' => ['Manage this user comment: %s', $this->absoluteRootUrl() . 'comments.php?comment_id=' . $this->insertedId($comm)],
                ],
            ], $call['content']);
        }

        /**
         * email_admin_on_comment_validation fires the same mail, this time
         * *with* the trailing "(!) This comment requires validation" line
         * since the comment was moderated, not immediately validated.
         */
        public function testInsertCommentNotifiesAdminsByMailOnModeration(): void
        {
            CurrentConfigTestFactory::get()->emailAdminOnCommentValidation = true;

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
            self::assertSame([
                'key_args' => ['(!) This comment requires validation', ''],
            ], $content[$lastKey]);
        }

        // --- updateComment() --------------------------------------------------

        public function testUpdateCommentRejectsAnInvalidKey(): void
        {
            $comment = [
                'comment_id' => 2,
                'image_id' => 2,
                'content' => 'edited',
                'website_url' => '',
            ];

            self::assertSame('reject', $this->service->updateComment($comment, 'not-a-real-key'));
        }

        public function testUpdateCommentModeratesWhenValidationRequired(): void
        {
            // Impersonate comment 2's real owner (author_id 3) so the
            // UPDATE's own non-admin author_id restriction doesn't block
            // it -- can't use is_admin() here instead, since is_admin()
            // true would itself force the 'validate' branch, not
            // 'moderate'. updateComment() assumes the caller has already
            // authorized this exact edit, same as the real comments.php/
            // picture_comment.inc.php callers do via can_manage_comment()
            // before ever reaching this method.
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 3,
                'status' => 'normal',
                'username' => 'regular_user',
            ]));
            $comment = [
                'comment_id' => 2,
                'image_id' => 2,
                'content' => 'edited content',
                'website_url' => '',
            ];

            $action = $this->service->updateComment($comment, $this->validKey(2));

            self::assertSame('moderate', $action);
            self::assertSame('edited content', $this->fetchColumn(2, 'content'));
            self::assertSame(0, $this->fetchValidated(2));
        }

        /**
         * PictureRequest::$websiteUrl's own null-when-absent-or-non-string
         * DTO retype means PictureController.php's own updateComment() call
         * site always sets the 'website_url' key (never conditionally
         * omits it) -- this proves that's fine: an omitted key and an
         * explicit null value produce identical behavior here, since
         * updateComment() reads it via `$comment['website_url'] ?? null`
         * either way (see self::emptyValue() call, line 446).
         */
        public function testUpdateCommentTreatsOmittedWebsiteUrlSameAsExplicitNull(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 3,
                'status' => 'normal',
                'username' => 'regular_user',
            ]));

            $withoutKey = [
                'comment_id' => 2,
                'image_id' => 2,
                'content' => 'edited without a website_url key',
            ];
            $actionWithoutKey = $this->service->updateComment($withoutKey, $this->validKey(2));
            self::assertSame('moderate', $actionWithoutKey);
            self::assertSame('edited without a website_url key', $this->fetchColumn(2, 'content'));

            $withNullValue = [
                'comment_id' => 2,
                'image_id' => 2,
                'content' => 'edited with an explicit null website_url',
                'website_url' => null,
            ];
            $actionWithNullValue = $this->service->updateComment($withNullValue, $this->validKey(2));
            self::assertSame('moderate', $actionWithNullValue);
            self::assertSame('edited with an explicit null website_url', $this->fetchColumn(2, 'content'));
        }

        public function testUpdateCommentInvalidWebsiteUrlAppendsAPageErrorAndRejects(): void
        {
            $comment = [
                'comment_id' => 2,
                'image_id' => 2,
                'content' => 'edited',
                'website_url' => '"><script>',
            ];

            $action = $this->service->updateComment($comment, $this->validKey(2));

            self::assertSame('reject', $action);
            self::assertContains('Your website URL is invalid', $this->pageErrors());
        }

        /**
         * email_admin_on_comment_validation fires mailNotificationAdmins()
         * with the exact keyargs, including the trailing "(!) This comment
         * requires validation" line -- distinct from the plain "just mail
         * admin" (emailAdminOnEdit(...)) elseif branch exercised by
         * test_update_comment_moderates_when_validation_required above,
         * which never reaches that flag check because it's off there.
         */
        public function testUpdateCommentNotifiesAdminsByMailOnModeration(): void
        {
            CurrentConfigTestFactory::get()->emailAdminOnCommentValidation = true;
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 3,
                'status' => 'normal',
                'username' => 'mail_dispatch_owner',
            ]));
            $comment = [
                'comment_id' => 2,
                'image_id' => 2,
                'content' => 'edited for mail dispatch',
                'website_url' => '',
            ];

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $action = $service->updateComment($comment, $this->validKey(2));

            self::assertSame('moderate', $action);
            self::assertCount(1, $mailer->calls);
            $call = $mailer->calls[0];
            self::assertSame([
                'key_args' => ['Comment by %s', 'mail_dispatch_owner'],
            ], $call['subject']);
            self::assertSame([
                [
                    'key_args' => ['Author: %s', 'mail_dispatch_owner'],
                ],
                [
                    'key_args' => ['Comment: %s', 'edited for mail dispatch'],
                ],
                [
                    'key_args' => ['', ''],
                ],
                [
                    'key_args' => ['Manage this user comment: %s', $this->absoluteRootUrl() . 'comments.php?comment_id=2'],
                ],
                [
                    'key_args' => ['(!) This comment requires validation', ''],
                ],
            ], $call['content']);
        }

        // --- deleteComment() ----------------------------------------------

        public function testDeleteCommentReturnsFalseForAMissingComment(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Admin));

            self::assertFalse($this->service->deleteComment(CommentId::from(999999)));
        }

        public function testDeleteCommentRemovesAsAdmin(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Admin));

            self::assertTrue($this->service->deleteComment(CommentId::from(3)));
            self::assertNull($this->fetchColumn(3, 'content'));
        }

        public function testDeleteCommentDeniedForANonOwningUser(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 999,
                'status' => 'normal',
                'username' => 'someone-else',
            ]));

            self::assertFalse($this->service->deleteComment(CommentId::from(4))); // owned by author_id 4
            self::assertNotNull($this->fetchColumn(4, 'content'));
        }

        public function testDeleteCommentAllowedForTheOwningUser(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 4,
                'status' => 'normal',
                'username' => 'power_user',
            ]));

            self::assertTrue($this->service->deleteComment(CommentId::from(4)));
        }

        // --- validateComment() ----------------------------------------------

        public function testValidateCommentMarksItValidated(): void
        {
            self::assertSame(0, $this->fetchValidated(5));

            $this->service->validateComment(CommentId::from(5));

            self::assertSame(1, $this->fetchValidated(5));
        }

        // --- getCommentAuthorId() --------------------------------------------

        public function testGetCommentAuthorIdReturnsTheOwner(): void
        {
            self::assertSame(1, $this->service->getCommentAuthorId(CommentId::from(1)));
        }

        public function testGetCommentAuthorIdReturnsFalseWithoutDyingWhenMissing(): void
        {
            self::assertFalse($this->service->getCommentAuthorId(CommentId::from(999999), false));
        }

        /**
         * author_id is nullable in schema (anonymous/guest comment with no
         * owner) -- this is a distinct state from "comment doesn't exist",
         * see CommentRepository::findAuthorId(). Collapsing both states
         * down to `false` would flow into
         * AccessControl::canManageComment()'s strictly-typed `int|string`
         * parameter and crash with a TypeError, since assert() is a no-op
         * under this project's zend.assertions=-1 and can't catch it.
         */
        public function testGetCommentAuthorIdReturnsNullForAnAnonymousComment(): void
        {
            $id = $this->insertAnonymousComment();

            self::assertNull($this->service->getCommentAuthorId(CommentId::from($id)));
        }

        public function testGetCommentAuthorIdNullFlowsSafelyIntoCanManageComment(): void
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
        public function testGetCommentAuthorIdDiesOnAnUnknownIdentifierByDefault(): void
        {
            $service = $this->serviceWithHtmlRenderer(new CommentServiceFakeHtmlRendererThrowsOnFatalError());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('COMMENT_SERVICE_FATAL_ERROR_MARKER: Unknown comment identifier');

            $service->getCommentAuthorId(CommentId::from(999999));
        }

        // --- emailAdminOnDelete()/emailAdminOnEdit() --------------------------

        public function testEmailAdminOnDeleteNotifiesWithTheCommentId(): void
        {
            CurrentConfigTestFactory::get()->emailAdminOnCommentDeletion = true;

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $service->emailAdminOnDelete('evicted_author', 42);

            self::assertCount(1, $mailer->calls);
            $call = $mailer->calls[0];
            self::assertSame([
                'key_args' => ['Comment by %s', 'evicted_author'],
            ], $call['subject']);
            self::assertSame([
                [
                    'key_args' => ['Author: %s', 'evicted_author'],
                ],
                [
                    'key_args' => ['This author removed the comment with id %d', 42],
                ],
            ], $call['content']);
        }

        public function testEmailAdminOnDeleteDoesNothingWhenTheConfigFlagIsOff(): void
        {
            CurrentConfigTestFactory::get()->emailAdminOnCommentDeletion = false;

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $service->emailAdminOnDelete('evicted_author', 42);

            self::assertSame([], $mailer->calls);
        }

        public function testEmailAdminOnEditNotifiesWithTheNewContent(): void
        {
            CurrentConfigTestFactory::get()->emailAdminOnCommentEdition = true;

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $service->emailAdminOnEdit('editing_author', 'the revised text');

            self::assertCount(1, $mailer->calls);
            $call = $mailer->calls[0];
            self::assertSame([
                'key_args' => ['Comment by %s', 'editing_author'],
            ], $call['subject']);
            self::assertSame([
                [
                    'key_args' => ['Author: %s', 'editing_author'],
                ],
                [
                    'key_args' => ['This author modified following comment:', ''],
                ],
                [
                    'key_args' => ['Comment: %s', 'the revised text'],
                ],
            ], $call['content']);
        }

        public function testEmailAdminOnEditDoesNothingWhenTheConfigFlagIsOff(): void
        {
            CurrentConfigTestFactory::get()->emailAdminOnCommentEdition = false;

            $mailer = new CommentServiceFakeMailerRecordsNotifications();
            $service = $this->serviceWithMailer($mailer);

            $service->emailAdminOnEdit('editing_author', 'the revised text');

            self::assertSame([], $mailer->calls);
        }

        // --- invalidateNbCommentsCache() -------------------------------------

        /**
         * This method never touches `user_cache.nb_available_comments` --
         * the read side only ever consults CurrentUser::rawAttributes,
         * never the DB column.
         */
        public function testGetNbAvailableCommentsCountsOnlyValidatedCommentsForANonAdminUser(): void
        {
            // countAvailableWithConditions() is exercised here through
            // this real caller's own condition fragments
            // (com.validated/ic.category/ic.image, wired up in
            // CommentService itself) -- CommentRepositoryTest's own
            // countAvailableWithConditions() tests exercise the same
            // repository mechanism, but never through this exact caller.
            $repo = TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(CommentEntity::class), CommentRepository::class);
            $counter = new AvailableCommentsCounter(CurrentUserTestFactory::get(), $this->accessLevelChecker());
            $baseline = $counter->count($this->permissionService(), EntityManagerFactory::build($this->conn));

            $validatedId = $repo->insert([
                'author' => 'nbc-test',
                'authorId' => null,
                'anonymousId' => '10.40.0.1',
                'content' => 'nbc validated',
                'validated' => true,
                'imageId' => 1,
                'websiteUrl' => null,
                'email' => null,
            ]);
            $unvalidatedId = $repo->insert([
                'author' => 'nbc-test',
                'authorId' => null,
                'anonymousId' => '10.40.0.2',
                'content' => 'nbc unvalidated',
                'validated' => false,
                'imageId' => 1,
                'websiteUrl' => null,
                'email' => null,
            ]);

            try {
                // Busts count()'s own per-request cache
                // (CurrentUser::rawAttributes['nb_available_comments']) so
                // the second call genuinely recomputes.
                CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withRawAttribute('nb_available_comments', null));

                $afterInsert = $counter->count($this->permissionService(), EntityManagerFactory::build($this->conn));

                self::assertSame($baseline + 1, $afterInsert, 'only the validated comment should count');
            } finally {
                $this->conn->executeStatement('DELETE FROM comments WHERE id IN (?, ?)', [$validatedId->value, $unvalidatedId->value]);
            }
        }

        public function testInvalidateNbCommentsCacheUnsetsTheGlobal(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withRawAttribute('nb_available_comments', 5));

            $this->service->invalidateNbCommentsCache();

            self::assertFalse(isset(CurrentUserTestFactory::get()->get()->rawAttributes['nb_available_comments']));
        }

        /**
         * @return list<string>
         */
        private function postCr(): array
        {
            return PageStateTestFactory::get()->commentRejectionReasons;
        }

        /**
         * @return list<string>
         */
        private function pageErrors(): array
        {
            return PageStateTestFactory::get()->errors;
        }

        /**
         * A CommentService wired to a fake MailerInterface so the
         * admin-notification mail dispatch paths (insertComment()/
         * updateComment()/emailAdminOnEdit()/emailAdminOnDelete()) can be
         * asserted on exactly, without a real Mail\MailService (Symfony
         * Mailer) round trip.
         */
        private function serviceWithMailer(MailerInterface $mailer): CommentService
        {
            return new CommentService(
                LangTestFactory::get(),
                TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(CommentEntity::class), CommentRepository::class),
                new EphemeralKeyService(CurrentConfigTestFactory::get()),
                $mailer,
                HtmlServiceTestFactory::build(),
                UrlServiceTestFactory::build(),
                new EventDispatcher(),
                PageStateTestFactory::get(),
                CurrentUserTestFactory::get(),
                CurrentConfigTestFactory::get(),
                $this->accessLevelChecker(),
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
            $mailer = Kernel::container()->get(MailService::class);
            self::assertInstanceOf(MailService::class, $mailer);

            return new CommentService(
                LangTestFactory::get(),
                TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(CommentEntity::class), CommentRepository::class),
                new EphemeralKeyService(CurrentConfigTestFactory::get()),
                $mailer,
                $htmlRenderer,
                UrlServiceTestFactory::build(),
                new EventDispatcher(),
                PageStateTestFactory::get(),
                CurrentUserTestFactory::get(),
                CurrentConfigTestFactory::get(),
                $this->accessLevelChecker(),
            );
        }

        private function absoluteRootUrl(): string
        {
            return UrlServiceTestFactory::build()->getAbsoluteRootUrl();
        }

        private function insertedId(CommentInsertData $comm): int
        {
            self::assertIsInt($comm->id);

            return $comm->id;
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
            $this->conn->insert('comments', [
                'image_id' => $imageId,
                'date' => '2026-08-01 00:00:00',
                'author' => 'anonymous',
                'author_id' => null,
                'anonymous_id' => '127.0.0.4',
                'content' => 'Anonymous comment with no owner.',
                'validated' => true,
            ]);

            return (int) $this->conn->lastInsertId();
        }

        private function baseComm(int $imageId = 1): CommentInsertData
        {
            return new CommentInsertData(
                author: '',
                content: 'A perfectly fine comment.',
                imageId: $imageId,
                websiteUrl: '',
                email: '',
            );
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
            $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? null;
            $remoteAddr = is_string($remoteAddrRaw) ? $remoteAddrRaw : '';
            $secretKey = CurrentConfigTestFactory::get()->secretKey;
            $signature = hash_hmac('sha256', (string) $issuedAt . substr($remoteAddr, 0, 5) . '0' . $imageId, $secretKey);

            return (string) $issuedAt . ':0:' . $signature;
        }

        private function fetchColumn(int $commentId, string $column): ?string
        {
            $value = $this->conn->createQueryBuilder()
                ->select($column)
                ->from('comments')
                ->where('id = :id')
                ->setParameter('id', $commentId)
                ->executeQuery()
                ->fetchOne();

            return is_string($value) ? $value : null;
        }

        /**
         * validated is a real tinyint(1) column -- fetchColumn()'s
         * is_string() narrowing would always return null for it, same
         * reasoning as CommentRepositoryTest's own fetchValidated().
         */
        private function fetchValidated(int $commentId): ?int
        {
            $value = $this->conn->createQueryBuilder()
                ->select('validated')
                ->from('comments')
                ->where('id = :id')
                ->setParameter('id', $commentId)
                ->executeQuery()
                ->fetchOne();

            return is_bool($value) || is_numeric($value) ? (int) (bool) $value : null;
        }
    }
}
