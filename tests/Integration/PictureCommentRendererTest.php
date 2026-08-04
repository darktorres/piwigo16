<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Picture had zero tests in any suite before this class + its Unit
 * sibling (tests/Unit/Picture/PictureCommentRendererTest.php) -- this
 * file covers the branches those Unit tests can't: everything past
 * `$nbComments > 0`, which needs a real CommentRepository row
 * (findForImage()). The first test directly re-verifies the historical
 * $edit_comment scope-sharing bug this class's own docblock documents
 * (fixed by threading $editCommentId explicitly): with 2 real comments on
 * the same image, only the one matching the given id may ever get
 * IN_EDIT.
 */
final class PictureCommentRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CommentRepository $commentRepo;

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
        // The moderation-message assertion below expects the real en_UK
        // common.po wording (which differs slightly from the raw English
        // literal passed to Lang::t()) -- whether common.lang happens to
        // already be loaded otherwise depends on which other Integration
        // test file ran earlier in this shared process, confirmed live.
        // Loading it explicitly here makes that assertion deterministic
        // regardless of run order.
        Lang::current()->load('common.lang');

        $this->conn = DbConnection::build();
        $this->commentRepo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Comment\CommentEntity::class);

        // dataDirChecked() defaults to null after applyDefaults(), which
        // would make Template's constructor reach for CurrentConfigService
        // (a full RequestBootstrap dependency this test never boots) just
        // to persist a "don't recheck this" cache flag -- skip it the same
        // way a real request's 2nd-and-later call already does.
        CurrentConfig::setDataDirChecked('1');
        // render()'s final assign_var_from_handle() really compiles
        // comment_list.tpl -- root/theme='default' is what points
        // Smarty's template_dir at the real themes/default/template/
        // directory that file lives in (same root shape every real
        // Template() call site uses, e.g. RequestBootstrap.php:568).
        CurrentTemplate::current()->set(new Template(\Piwigo\Core\CurrentPaths::get()->root . 'themes', 'default'));
        session_id('fixed-test-session-id'); // CsrfService::getToken() needs a session id, not a running session.
        unset($_POST['content']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        CurrentTemplate::current()->reset();
        CurrentUser::current()->reset();
        CurrentConfig::reset();
        unset($_POST['content']);
        parent::tearDown();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (($GLOBALS['mysqli'] ?? null) instanceof \mysqli) {
            $GLOBALS['mysqli']->close();
        }
        unset($GLOBALS['mysqli']);
        parent::tearDownAfterClass();
    }

    public function test_render_prefills_the_edit_form_only_for_the_matching_comment_id(): void
    {
        $imageId = 3; // owned by no other test's disposable comments (see CommentRepositoryTest's own convention).
        $ownerId = 3;
        $commentIdA = $this->insertComment($imageId, $ownerId, 'First comment.');
        $commentIdB = $this->insertComment($imageId, $ownerId, 'Second comment.');
        $this->seedUser($ownerId, UserStatus::Normal);
        CurrentConfig::setUserCanEditComment(true);

        $this->renderer()->render(\Piwigo\Core\Lang::current(), \Piwigo\Auth\AccessControl::current(), $commentIdB, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Template\CurrentTemplate::current(), new \Piwigo\Mail\MailService());

        $rows = $this->renderedComments();
        $rowA = $this->findRenderedRow($rows, $commentIdA);
        $rowB = $this->findRenderedRow($rows, $commentIdB);

        self::assertArrayNotHasKey('IN_EDIT', $rowA, 'the non-matching comment must never be prefilled');
        self::assertTrue($rowB['IN_EDIT'] ?? false, 'the matching comment must be prefilled');
        self::assertSame('Second comment.', $rowB['CONTENT']);
    }

    public function test_render_exposes_edit_and_delete_links_only_to_the_comment_owner(): void
    {
        $imageId = 3;
        $ownerId = 3;
        $otherUserId = 4;
        $commentId = $this->insertComment($imageId, $ownerId, 'Owned by user 3.');
        CurrentConfig::setUserCanEditComment(true);
        CurrentConfig::setUserCanDeleteComment(true);

        $this->seedUser($ownerId, UserStatus::Normal);
        $ownerRow = $this->findRenderedRow(
            $this->renderComments($imageId),
            $commentId
        );

        $this->seedUser($otherUserId, UserStatus::Normal);
        $otherRow = $this->findRenderedRow(
            $this->renderComments($imageId),
            $commentId
        );

        self::assertArrayHasKey('U_EDIT', $ownerRow);
        self::assertArrayHasKey('U_DELETE', $ownerRow);
        self::assertArrayNotHasKey('U_EDIT', $otherRow);
        self::assertArrayNotHasKey('U_DELETE', $otherRow);
    }

    public function test_render_rejects_a_guest_submission_with_session_expired_when_comments_for_all_is_disabled(): void
    {
        CurrentConfig::setCommentsForall(false);
        $imageId = 3;
        $_POST['content'] = 'A guest comment attempt.';

        try {
            $this->renderer()->render(\Piwigo\Core\Lang::current(), \Piwigo\Auth\AccessControl::current(), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Template\CurrentTemplate::current(), new \Piwigo\Mail\MailService());
            self::fail('Expected a ResponseReadyException');
        } catch (ResponseReadyException $e) {
            self::assertSame(200, $e->response()->getStatusCode());
            self::assertSame('Session expired', (string) $e->response()->getBody());
        } finally {
            unset($_POST['content']);
        }
    }

    public function test_render_rejects_a_submission_as_ugly_spammer_when_the_picture_is_not_commentable(): void
    {
        $imageId = 3;
        $_POST['content'] = 'Spam attempt on a non-commentable picture.';

        try {
            $this->renderer()->render(\Piwigo\Core\Lang::current(), \Piwigo\Auth\AccessControl::current(), null, $imageId, 0, $this->urlService(), [['commentable' => false]], '/picture.php', $this->sessionService(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Template\CurrentTemplate::current(), new \Piwigo\Mail\MailService());
            self::fail('Expected a ResponseReadyException');
        } catch (ResponseReadyException $e) {
            self::assertSame(403, $e->response()->getStatusCode());
            self::assertSame('ugly spammer', (string) $e->response()->getBody());
        } finally {
            unset($_POST['content']);
        }
    }

    public function test_render_moderates_a_valid_guest_submission_when_comments_validation_is_enabled(): void
    {
        CurrentConfig::setCommentsForall(true);
        CurrentConfig::setCommentsValidation(true);
        CurrentConfig::setCommentsAuthorMandatory(false);
        CurrentConfig::setCommentsEmailMandatory(false);
        CurrentConfig::setAntiFloodTime(0);
        // Real production default (true) would otherwise attempt a real
        // MailerInterface::mail() send for this exact outcome.
        CurrentConfig::setEmailAdminOnCommentValidation(false);
        $imageId = 3;
        $_POST['content'] = 'A moderated comment.';
        $_POST['key'] = new EphemeralKeyService()->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(\Piwigo\Core\Lang::current(), \Piwigo\Auth\AccessControl::current(), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Template\CurrentTemplate::current(), new \Piwigo\Mail\MailService());

            self::assertSame(
                [
                    // common.po's own en_UK translation rewords this from
                    // the raw source literal ("becomes" instead of "is").
                    'An administrator must authorize your comment before it becomes visible.',
                    'Your comment has been registered',
                ],
                PageState::current()->infos
            );
        } finally {
            unset($_POST['content'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'A moderated comment.'");
        }
    }

    public function test_render_validates_a_guest_submission_immediately_when_comments_validation_is_disabled(): void
    {
        CurrentConfig::setCommentsForall(true);
        CurrentConfig::setCommentsValidation(false);
        CurrentConfig::setCommentsAuthorMandatory(false);
        CurrentConfig::setCommentsEmailMandatory(false);
        CurrentConfig::setAntiFloodTime(0);
        $imageId = 3;
        $_POST['content'] = 'A validated comment.';
        $_POST['key'] = new EphemeralKeyService()->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(\Piwigo\Core\Lang::current(), \Piwigo\Auth\AccessControl::current(), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Template\CurrentTemplate::current(), new \Piwigo\Mail\MailService());

            self::assertSame(['Your comment has been registered'], PageState::current()->infos);
        } finally {
            unset($_POST['content'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'A validated comment.'");
        }
    }

    public function test_render_rejects_an_invalid_key_and_repopulates_the_add_comment_form(): void
    {
        CurrentConfig::setCommentsForall(true);
        CurrentConfig::setCommentsAuthorMandatory(false);
        CurrentConfig::setCommentsEmailMandatory(false);
        CurrentConfig::setAntiFloodTime(0);
        $imageId = 3;
        $_POST['author'] = 'Some Author';
        $_POST['content'] = 'Rejected <b>content</b>.';
        $_POST['website_url'] = '';
        $_POST['email'] = '';
        // Not a real ephemeral key -- EphemeralKeyService::verify() fails,
        // forcing 'reject' regardless of the (otherwise valid) content.
        $_POST['key'] = 'totally-invalid-key';

        try {
            $this->renderer()->render(\Piwigo\Core\Lang::current(), \Piwigo\Auth\AccessControl::current(), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Template\CurrentTemplate::current(), new \Piwigo\Mail\MailService());

            self::assertSame(
                ['Your comment has NOT been registered because it did not pass the validation rules'],
                PageState::current()->errors
            );

            $commentAdd = CurrentTemplate::current()->get()->get_template_vars('comment_add');
            self::assertIsArray($commentAdd);
            self::assertSame('Some Author', $commentAdd['AUTHOR']);
            // stripslashes() is a no-op here (no backslashes); htmlspecialchars()
            // escapes the tag markup.
            self::assertSame('Rejected &lt;b&gt;content&lt;/b&gt;.', $commentAdd['CONTENT']);
            self::assertSame('', $commentAdd['WEBSITE_URL']);
            self::assertSame('', $commentAdd['EMAIL']);
        } finally {
            unset($_POST['author'], $_POST['content'], $_POST['website_url'], $_POST['email'], $_POST['key']);
        }
    }

    public function test_render_persists_a_get_comments_order_override_to_the_session(): void
    {
        $_SESSION ??= [];
        $imageId = 3;
        $this->insertComment($imageId, 3, 'Order override test comment.');
        $this->seedUser(3, UserStatus::Normal);
        $_GET['comments_order'] = 'desc';

        try {
            $this->renderer()->render(\Piwigo\Core\Lang::current(), \Piwigo\Auth\AccessControl::current(), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Template\CurrentTemplate::current(), new \Piwigo\Mail\MailService());

            self::assertSame('desc', \Piwigo\Session\SessionService::get()->getSessionVar('comments_order'));
            // The nav link toggles to the opposite of whatever order is now
            // active ('desc' -> offers 'ASC').
            $orderUrl = CurrentTemplate::current()->get()->get_template_vars('COMMENTS_ORDER_URL');
            self::assertIsString($orderUrl);
            self::assertStringContainsString('comments_order=ASC', $orderUrl);
        } finally {
            unset($_GET['comments_order']);
            \Piwigo\Session\SessionService::get()->unsetSessionVar('comments_order');
        }
    }

    public function test_render_falls_back_to_the_comment_own_email_when_no_registered_user_email_matches(): void
    {
        $imageId = 3;
        // authorId points at fixture_admin (id 1), which has a real
        // mail_address on file -- exercises the `$row->userEmail` branch.
        $commentIdWithUserEmail = $this->commentRepo->insert([
            'author' => 'fixture_admin',
            'authorId' => 1,
            'anonymousId' => '10.30.0.1',
            'content' => 'Comment by a user with a registered email.',
            'validated' => true,
            'imageId' => $imageId,
            'websiteUrl' => null,
            'email' => null,
        ]);
        // Anonymous (no authorId -> no user-table match), but with its own
        // posted email column set -- exercises the `$row->email` fallback.
        $commentIdWithOwnEmail = $this->commentRepo->insert([
            'author' => 'anon-commenter',
            'authorId' => null,
            'anonymousId' => '10.30.0.2',
            'content' => 'Comment by an anonymous poster with their own email.',
            'validated' => true,
            'imageId' => $imageId,
            'websiteUrl' => null,
            'email' => 'anon@example.test',
        ]);

        $this->seedUser(1, UserStatus::Admin);

        try {
            $rows = $this->renderComments($imageId);
            $rowWithUserEmail = $this->findRenderedRow($rows, $commentIdWithUserEmail);
            $rowWithOwnEmail = $this->findRenderedRow($rows, $commentIdWithOwnEmail);

            self::assertSame('fixture_admin@example.test', $rowWithUserEmail['EMAIL']);
            self::assertSame('anon@example.test', $rowWithOwnEmail['EMAIL']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::comments() . ' WHERE id IN (?, ?)', [$commentIdWithUserEmail->value, $commentIdWithOwnEmail->value]);
        }
    }

    public function test_render_exposes_edit_and_delete_links_to_an_admin_regardless_of_ownership(): void
    {
        $imageId = 3;
        $ownerId = 3;
        $commentId = $this->insertComment($imageId, $ownerId, 'Owned by user 3.');
        CurrentConfig::setUserCanEditComment(false);
        CurrentConfig::setUserCanDeleteComment(false);

        $this->seedUser(1, UserStatus::Admin);
        $adminRow = $this->findRenderedRow(
            $this->renderComments($imageId),
            $commentId
        );

        self::assertArrayHasKey('U_EDIT', $adminRow);
        self::assertArrayHasKey('U_DELETE', $adminRow);
    }

    /**
     * @return list<mixed>
     */
    private function renderComments(int $imageId): array
    {
        $this->renderer()->render(\Piwigo\Core\Lang::current(), \Piwigo\Auth\AccessControl::current(), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), \Piwigo\Users\CurrentUser::current(), \Piwigo\Template\CurrentTemplate::current(), new \Piwigo\Mail\MailService());

        return $this->renderedComments();
    }

    private function renderer(): PictureCommentRenderer
    {
        return new PictureCommentRenderer();
    }

    private function urlService(): UrlService
    {
        return new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride());
    }

    private function sessionService(): SessionService
    {
        return new SessionService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commentableCategory(): array
    {
        return [['commentable' => true]];
    }

    private function seedUser(int $id, UserStatus $status): void
    {
        CurrentUser::current()->set(new User(
            id: \Piwigo\Common\ValueObject\UserId::from($id),
            username: 'fixture_user_' . $id,
            email: '',
            language: '',
            theme: '',
            status: $status,
            enabledHigh: false,
        ));
    }

    private function insertComment(int $imageId, int $authorId, string $content): CommentId
    {
        return $this->commentRepo->insert([
            'author' => 'fixture_user_' . $authorId,
            'authorId' => $authorId,
            'anonymousId' => '10.30.0.1',
            'content' => $content,
            'validated' => true,
            'imageId' => $imageId,
            'websiteUrl' => null,
            'email' => null,
        ]);
    }

    /**
     * @return list<mixed>
     */
    private function renderedComments(): array
    {
        $vars = CurrentTemplate::current()->get()->get_template_vars('comments');

        return is_array($vars) ? array_values($vars) : [];
    }

    /**
     * @param list<mixed> $rows
     * @return array<int|string, mixed>
     */
    private function findRenderedRow(array $rows, CommentId $commentId): array
    {
        foreach ($rows as $row) {
            if (is_array($row) && ($row['ID'] ?? null) === $commentId->value) {
                return $row;
            }
        }

        self::fail("no rendered row found for comment id {$commentId->value}");
    }
}
