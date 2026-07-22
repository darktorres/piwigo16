<?php

declare(strict_types=1);

// CommentService calls several real, stable, already-migrated free
// functions that need more bootstrap (the plugin-event system,
// $user/$conf-driven access-level checks, HtmlService's Template-rendering
// fatal_error()) than this isolated integration test wants to depend on.
// Same "minimal stub to load standalone" pattern as
// tests/Integration/UserServiceTest.php. No url_check_format()/
// email_check_format() stubs: CommentService's real call sites now call
// Piwigo\Validation\InputValidator::checkUrlFormat()/checkEmailFormat()
// directly (P23 batch 8d), real class methods a same-named bare-function
// stub can no longer intercept.
//
// No fatal_error() stub: only getCommentAuthorId()'s $dieOnError=true path
// (never exercised below) would reach it, and it's `never`-typed (renders
// a page and exits) -- unsafe to invoke from a test process, same reason
// PasswordHashTest/AuthServiceTest never stub it either.
//
// pwg_mail_notification_admins()/get_l10n_args()/get_absolute_root_url()
// are deliberately NOT stubbed either: every test below sets all four
// `email_admin_on_comment*` config flags to false, so the branches that
// would call them are never reached -- same "don't stub what's never
// called" reasoning as TagServiceTest's PHPStan stub-collision lesson.
// The real end-to-end admin-notification path is live-verified separately.
namespace {
    if (! function_exists('l10n')) {
        function l10n(string $key, mixed ...$args): string
        {
            return $args === [] ? $key : vsprintf($key, array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $args));
        }
    }

    // trigger_change()/trigger_notify() calls go directly through the real
    // Piwigo\PluginConfig\EventDispatcher::get() singleton now, pure
    // passthroughs with no handlers registered, so no local stubs are
    // needed for them here.

    // is_admin()/is_a_guest()/is_classic_user() -- CommentService now calls
    // Piwigo\Auth\AccessControl::isAdmin()/isAGuest()/isClassicUser()
    // directly (P23 batch 8d), which read Piwigo\Users\CurrentUser (Legacy
    // Coupling Retirement Track A batch A3); tests below seed CurrentUser
    // instead.
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Auth\EphemeralKeyService;
    use Piwigo\Comment\CommentRepository;
    use Piwigo\Comment\CommentService;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Html\HtmlService;
    use Piwigo\Mail\MailService;
    use Piwigo\Url\UrlService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;
    use Piwigo\Users\UserStatus;

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

            Config::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            Config::override('secret_key', 'test-secret-key');
            Config::override('comments_validation', true);
            Config::override('comments_author_mandatory', false);
            Config::override('comments_email_mandatory', false);
            Config::override('comments_enable_website', true);
            Config::override('comment_spam_reject', true);
            Config::override('comment_spam_max_links', 3);
            Config::override('anti-flood_time', 0);
            Config::override('guest_id', 2);
            Config::override('guest_access', true);
            Config::override('user_fields', ['id' => 'id', 'username' => 'username', 'password' => 'password', 'email' => 'mail_address']);
            Config::override('email_admin_on_comment', false);
            Config::override('email_admin_on_comment_validation', false);
            Config::override('email_admin_on_comment_edition', false);
            Config::override('email_admin_on_comment_deletion', false);
            CurrentUser::set(User::fromUserArray(['id' => 1, 'status' => 'normal', 'username' => 'fixture_admin', 'email' => 'fixture_admin@example.test']));
            \Piwigo\Core\PageState::reset();
            $_POST['cr'] = [];

            $this->conn = DbConnection::build();
            $this->service = new CommentService(new CommentRepository($this->conn), new EphemeralKeyService(), new MailService(), new HtmlService(), new UrlService(new HtmlService()));
        }

        // --- checkForSpam() -------------------------------------------------

        public function test_check_for_spam_returns_reject_unchanged(): void
        {
            self::assertSame('reject', $this->service->checkForSpam('reject', ['content' => '', 'author' => '']));
        }

        public function test_check_for_spam_leaves_action_alone_for_a_non_guest(): void
        {
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Normal));

            self::assertSame('moderate', $this->service->checkForSpam('moderate', ['content' => 'hi', 'author' => 'a']));
        }

        public function test_check_for_spam_escalates_when_link_count_exceeds_the_max(): void
        {
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Guest));

            $content = 'http://a.test http://b.test http://c.test http://d.test';
            self::assertSame('reject', $this->service->checkForSpam('moderate', ['content' => $content, 'author' => 'a']));
            self::assertContains('links', $this->postCr());
        }

        public function test_check_for_spam_leaves_action_alone_under_the_link_limit(): void
        {
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Guest));

            self::assertSame('moderate', $this->service->checkForSpam('moderate', ['content' => 'http://a.test', 'author' => 'a']));
        }

        // --- insertComment() --------------------------------------------------

        public function test_insert_comment_validates_immediately_when_validation_disabled(): void
        {
            $this->setConf('comments_validation', false);

            $comm = $this->baseComm();
            $key = $this->validKey();
            $infos = [];

            $action = $this->service->insertComment($comm, $key, $infos);

            self::assertSame('validate', $action);
            self::assertSame([], $infos);
            $id = $this->insertedId($comm);
            self::assertSame('A perfectly fine comment.', $this->fetchColumn($id, 'content'));
            self::assertSame('true', $this->fetchColumn($id, 'validated'));
        }

        public function test_insert_comment_moderates_when_validation_required_and_not_admin(): void
        {
            $comm = $this->baseComm();
            $key = $this->validKey();
            $infos = [];

            $action = $this->service->insertComment($comm, $key, $infos);

            self::assertSame('moderate', $action);
            self::assertSame('false', $this->fetchColumn($this->insertedId($comm), 'validated'));
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
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Guest));

            $comm = $this->baseComm();
            $comm['author'] = 'fixture_admin';
            $key = $this->validKey();
            $infos = [];

            self::assertSame('reject', $this->service->insertComment($comm, $key, $infos));
            self::assertContains('This login is already used by another user', $infos);
        }

        public function test_insert_comment_website_url_honeypot_rejected_when_disabled(): void
        {
            $this->setConf('comments_enable_website', false);

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
            $this->setConf('comments_validation', false);

            $comm = $this->baseComm();
            $comm['email'] = '';
            $key = $this->validKey();
            $infos = [];

            $this->service->insertComment($comm, $key, $infos);

            self::assertSame('fixture_admin@example.test', $this->fetchColumn($this->insertedId($comm), 'email'));
        }

        public function test_insert_comment_anti_flood_rejects_a_second_immediate_post(): void
        {
            $this->setConf('comments_validation', false);
            $this->setConf('anti-flood_time', 3600);
            CurrentUser::set(User::fromUserArray(['id' => 3, 'status' => 'normal', 'username' => 'regular_user']));

            $first = $this->baseComm();
            $infos = [];
            $this->service->insertComment($first, $this->validKey(), $infos);

            $second = $this->baseComm();
            $infos = [];
            $action = $this->service->insertComment($second, $this->validKey(), $infos);

            self::assertSame('reject', $action);
            self::assertContains('flood_time', $this->postCr());
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
            CurrentUser::set(User::fromUserArray(['id' => 3, 'status' => 'normal', 'username' => 'regular_user']));
            $comment = ['comment_id' => 2, 'image_id' => 2, 'content' => 'edited content', 'website_url' => ''];

            $action = $this->service->updateComment($comment, $this->validKey(2));

            self::assertSame('moderate', $action);
            self::assertSame('edited content', $this->fetchColumn(2, 'content'));
            self::assertSame('false', $this->fetchColumn(2, 'validated'));
        }

        public function test_update_comment_invalid_website_url_appends_a_page_error_and_rejects(): void
        {
            $comment = ['comment_id' => 2, 'image_id' => 2, 'content' => 'edited', 'website_url' => '"><script>'];

            $action = $this->service->updateComment($comment, $this->validKey(2));

            self::assertSame('reject', $action);
            self::assertContains('Your website URL is invalid', $this->pageErrors());
        }

        // --- deleteComment() ----------------------------------------------

        public function test_delete_comment_returns_false_for_a_missing_comment(): void
        {
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Admin));

            self::assertFalse($this->service->deleteComment(999999));
        }

        public function test_delete_comment_removes_as_admin(): void
        {
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Admin));

            self::assertTrue($this->service->deleteComment(3));
            self::assertNull($this->fetchColumn(3, 'content'));
        }

        public function test_delete_comment_denied_for_a_non_owning_user(): void
        {
            CurrentUser::set(User::fromUserArray(['id' => 999, 'status' => 'normal', 'username' => 'someone-else']));

            self::assertFalse($this->service->deleteComment(4)); // owned by author_id 4
            self::assertNotNull($this->fetchColumn(4, 'content'));
        }

        public function test_delete_comment_allowed_for_the_owning_user(): void
        {
            CurrentUser::set(User::fromUserArray(['id' => 4, 'status' => 'normal', 'username' => 'power_user']));

            self::assertTrue($this->service->deleteComment(4));
        }

        // --- validateComment() ----------------------------------------------

        public function test_validate_comment_marks_it_validated(): void
        {
            self::assertSame('false', $this->fetchColumn(5, 'validated'));

            $this->service->validateComment(5);

            self::assertSame('true', $this->fetchColumn(5, 'validated'));
        }

        // --- getCommentAuthorId() --------------------------------------------

        public function test_get_comment_author_id_returns_the_owner(): void
        {
            self::assertSame(1, $this->service->getCommentAuthorId(1));
        }

        public function test_get_comment_author_id_returns_false_without_dying_when_missing(): void
        {
            self::assertFalse($this->service->getCommentAuthorId(999999, false));
        }

        // --- invalidateNbCommentsCache() -------------------------------------

        public function test_invalidate_nb_comments_cache_unsets_the_global_and_clears_the_db(): void
        {
            CurrentUser::set(CurrentUser::get()->withRawAttribute('nb_available_comments', 5));
            $this->conn->createQueryBuilder()
                ->update(Tables::userCache())
                ->set('nb_available_comments', '5')
                ->executeStatement();

            $this->service->invalidateNbCommentsCache();

            self::assertFalse(isset(CurrentUser::get()->rawAttributes['nb_available_comments']));
            $value = $this->conn->createQueryBuilder()
                ->select('nb_available_comments')
                ->from(Tables::userCache())
                ->where('user_id = 1')
                ->executeQuery()
                ->fetchOne();
            self::assertNull($value);
        }

        private function setConf(string $key, mixed $value): void
        {
            Config::override($key, $value);
        }

        /**
         * @return list<string>
         */
        private function postCr(): array
        {
            /** @var list<string> $cr */
            $cr = $_POST['cr'];

            return $cr;
        }

        /**
         * @return list<string>
         */
        private function pageErrors(): array
        {
            return \Piwigo\Core\PageState::current()->errors;
        }

        private function insertedId(mixed $comm): int
        {
            self::assertIsArray($comm);
            self::assertIsInt($comm['id'] ?? null);

            return $comm['id'];
        }

        /**
         * @return array<string, mixed>
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
            $secretKey = Config::secretKey();
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
    }
}
