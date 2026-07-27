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

            CurrentConfig::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            CurrentConfig::setSecretKey('test-secret-key');
            CurrentConfig::setCommentsValidation(true);
            CurrentConfig::setCommentsAuthorMandatory(false);
            CurrentConfig::setCommentsEmailMandatory(false);
            CurrentConfig::setCommentsEnableWebsite(true);
            CurrentConfig::setCommentSpamReject(true);
            CurrentConfig::setCommentSpamMaxLinks(3);
            CurrentConfig::setAntiFloodTime(0);
            CurrentConfig::setGuestId(2);
            CurrentConfig::setGuestAccess(true);
            CurrentConfig::setUserFields(['id' => 'id', 'username' => 'username', 'password' => 'password', 'email' => 'mail_address']);
            CurrentConfig::setEmailAdminOnComment(false);
            CurrentConfig::setEmailAdminOnCommentValidation(false);
            CurrentConfig::setEmailAdminOnCommentEdition(false);
            CurrentConfig::setEmailAdminOnCommentDeletion(false);
            CurrentUser::set(User::fromUserArray(['id' => 1, 'status' => 'normal', 'username' => 'fixture_admin', 'email' => 'fixture_admin@example.test']));
            \Piwigo\Core\PageState::reset();

            $this->conn = DbConnection::build();
            $this->service = new CommentService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Comment\CommentEntity::class), new EphemeralKeyService(), new MailService(), new HtmlService(), new UrlService(new HtmlService()));
        }

        // --- checkForSpam() -------------------------------------------------

        public function test_check_for_spam_returns_reject_unchanged(): void
        {
            self::assertSame('reject', $this->service->checkForSpam('reject', ['content' => '', 'author' => '', 'image_id' => 1]));
        }

        public function test_check_for_spam_leaves_action_alone_for_a_non_guest(): void
        {
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Normal));

            self::assertSame('moderate', $this->service->checkForSpam('moderate', ['content' => 'hi', 'author' => 'a', 'image_id' => 1]));
        }

        public function test_check_for_spam_escalates_when_link_count_exceeds_the_max(): void
        {
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Guest));

            $content = 'http://a.test http://b.test http://c.test http://d.test';
            self::assertSame('reject', $this->service->checkForSpam('moderate', ['content' => $content, 'author' => 'a', 'image_id' => 1]));
            self::assertContains('links', $this->postCr());
        }

        public function test_check_for_spam_leaves_action_alone_under_the_link_limit(): void
        {
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Guest));

            self::assertSame('moderate', $this->service->checkForSpam('moderate', ['content' => 'http://a.test', 'author' => 'a', 'image_id' => 1]));
        }

        // --- insertComment() --------------------------------------------------

        public function test_insert_comment_validates_immediately_when_validation_disabled(): void
        {
            CurrentConfig::setCommentsValidation(false);

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
            CurrentConfig::setCommentsEnableWebsite(false);

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
            CurrentConfig::setCommentsValidation(false);

            $comm = $this->baseComm();
            $comm['email'] = '';
            $key = $this->validKey();
            $infos = [];

            $this->service->insertComment($comm, $key, $infos);

            self::assertSame('fixture_admin@example.test', $this->fetchColumn($this->insertedId($comm), 'email'));
        }

        public function test_insert_comment_anti_flood_rejects_a_second_immediate_post(): void
        {
            CurrentConfig::setCommentsValidation(false);
            CurrentConfig::setAntiFloodTime(3600);
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
            self::assertSame(0, $this->fetchValidated(2));
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

            self::assertFalse($this->service->deleteComment(CommentId::from(999999)));
        }

        public function test_delete_comment_removes_as_admin(): void
        {
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Admin));

            self::assertTrue($this->service->deleteComment(CommentId::from(3)));
            self::assertNull($this->fetchColumn(3, 'content'));
        }

        public function test_delete_comment_denied_for_a_non_owning_user(): void
        {
            CurrentUser::set(User::fromUserArray(['id' => 999, 'status' => 'normal', 'username' => 'someone-else']));

            self::assertFalse($this->service->deleteComment(CommentId::from(4))); // owned by author_id 4
            self::assertNotNull($this->fetchColumn(4, 'content'));
        }

        public function test_delete_comment_allowed_for_the_owning_user(): void
        {
            CurrentUser::set(User::fromUserArray(['id' => 4, 'status' => 'normal', 'username' => 'power_user']));

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

            self::assertFalse(\Piwigo\Auth\AccessControl::canManageComment('edit', $authorId));
        }

        // --- invalidateNbCommentsCache() -------------------------------------

        /**
         * Gap-closure Stage 4f (docs/plan/gap-closure-p0-p23.md): this no
         * longer touches `user_cache.nb_available_comments` at all -- that
         * write was confirmed dead (the read side only ever consults
         * CurrentUser::rawAttributes, never the DB column) and deleted
         * outright, along with CommentRepository::clearNbCommentsCache().
         */
        public function test_invalidate_nb_comments_cache_unsets_the_global(): void
        {
            CurrentUser::set(CurrentUser::get()->withRawAttribute('nb_available_comments', 5));

            $this->service->invalidateNbCommentsCache();

            self::assertFalse(isset(CurrentUser::get()->rawAttributes['nb_available_comments']));
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
            $secretKey = CurrentConfig::secretKey();
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

            return is_numeric($value) ? (int) $value : null;
        }
    }
}
