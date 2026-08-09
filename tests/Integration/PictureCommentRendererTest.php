<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Comment\CommentEntity;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use mysqli;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Mail\MailService;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Doctrine\DBAL\Connection;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Config\ConfigLoader;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Event\Template\SetStatusHeader;
use Piwigo\Event\User\UserCommentInsertion;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\SessionServiceTestFactory;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Url\UrlService;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Covers the branches past `$nbComments > 0`, which need a real
 * CommentRepository row (findForImage()). $editCommentId is threaded
 * explicitly, so with 2 real comments on the same image, only the one
 * matching the given id may ever get IN_EDIT.
 */
final class PictureCommentRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CommentRepository $commentRepo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        // The moderation-message assertion below expects the real en_UK
        // common.po wording (which differs slightly from the raw English
        // literal passed to Lang::t()) -- whether common.lang happens to
        // already be loaded otherwise depends on which other Integration
        // test file ran earlier in this shared process, confirmed live.
        // Loading it explicitly here makes that assertion deterministic
        // regardless of run order.
        LangTestFactory::get()->load('common.lang');

        $this->conn = DbConnection::build();
        $this->commentRepo = EntityManagerFactory::build($this->conn)->getRepository(CommentEntity::class);

        // dataDirChecked() defaults to null after applyDefaults(), which
        // would make Template's constructor reach for CurrentConfigService
        // (a full RequestBootstrap dependency this test never boots) just
        // to persist a "don't recheck this" cache flag -- skip it the same
        // way a real request's 2nd-and-later call already does.
        CurrentConfigTestFactory::get()->dataDirChecked = '1';
        // render()'s final assign_var_from_handle() really compiles
        // comment_list.tpl -- root/theme='default' is what points
        // Smarty's template_dir at the real themes/default/template/
        // directory that file lives in (same root shape every real
        // Template() call site uses, e.g. RequestBootstrap.php:568).
        CurrentTemplate::current()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));
        // A literal id here would collide across concurrent worktrees sharing
        // one machine-wide /var/lib/php/sessions directory (see
        // Tests\Unit\Csrf\CsrfServiceTest's own docblock) -- CsrfService::getToken()
        // just needs a session id, not a running session, so any unique value works.
        // str_replace() strips uniqid()'s own '.' separator (more_entropy):
        // session_start() rejects a session id containing anything outside
        // A-Z/a-z/0-9/'-'/',' -- see CsrfServiceTest's own docblock for the
        // real warning this caused when inherited by an unrelated test.
        session_id(str_replace('.', '-', uniqid('picture-comment-test-', true)));
        unset($_POST['content']);
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentTemplate::current()->reset();
        CurrentUserTestFactory::get()->reset();
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        unset($_POST['content']);
        parent::tearDown();
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        if (($GLOBALS['mysqli'] ?? null) instanceof mysqli) {
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
        CurrentConfigTestFactory::get()->userCanEditComment = true;

        $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $commentIdB, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

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
        CurrentConfigTestFactory::get()->userCanEditComment = true;
        CurrentConfigTestFactory::get()->userCanDeleteComment = true;

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
        CurrentConfigTestFactory::get()->commentsForall = false;
        $imageId = 3;
        $_POST['content'] = 'A guest comment attempt.';

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());
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
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), [['commentable' => false]], '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());
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
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = true;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        // Real production default (true) would otherwise attempt a real
        // MailerInterface::mail() send for this exact outcome.
        CurrentConfigTestFactory::get()->emailAdminOnCommentValidation = false;
        $imageId = 3;
        $_POST['content'] = 'A moderated comment.';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertSame(
                [
                    // common.po's own en_UK translation rewords this from
                    // the raw source literal ("becomes" instead of "is").
                    'An administrator must authorize your comment before it becomes visible.',
                    'Your comment has been registered',
                ],
                PageStateTestFactory::get()->infos
            );
        } finally {
            unset($_POST['content'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'A moderated comment.'");
        }
    }

    public function test_render_validates_a_guest_submission_immediately_when_comments_validation_is_disabled(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['content'] = 'A validated comment.';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertSame(['Your comment has been registered'], PageStateTestFactory::get()->infos);
        } finally {
            unset($_POST['content'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'A validated comment.'");
        }
    }

    public function test_render_rejects_an_invalid_key_and_repopulates_the_add_comment_form(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['author'] = 'Some Author';
        $_POST['content'] = 'Rejected <b>content</b>.';
        $_POST['website_url'] = '';
        $_POST['email'] = '';
        // Not a real ephemeral key -- EphemeralKeyService::verify() fails,
        // forcing 'reject' regardless of the (otherwise valid) content.
        $_POST['key'] = 'totally-invalid-key';

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertSame(
                ['Your comment has NOT been registered because it did not pass the validation rules'],
                PageStateTestFactory::get()->errors
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
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertSame('desc', SessionServiceTestFactory::get()->getCommentsOrder());
            // The nav link toggles to the opposite of whatever order is now
            // active ('desc' -> offers 'ASC').
            $orderUrl = CurrentTemplate::current()->get()->get_template_vars('COMMENTS_ORDER_URL');
            self::assertIsString($orderUrl);
            self::assertStringContainsString('comments_order=ASC', $orderUrl);
        } finally {
            unset($_GET['comments_order']);
            SessionServiceTestFactory::get()->unsetSessionVar('comments_order');
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
        CurrentConfigTestFactory::get()->userCanEditComment = false;
        CurrentConfigTestFactory::get()->userCanDeleteComment = false;

        $this->seedUser(1, UserStatus::Admin);
        $adminRow = $this->findRenderedRow(
            $this->renderComments($imageId),
            $commentId
        );

        self::assertArrayHasKey('U_EDIT', $adminRow);
        self::assertArrayHasKey('U_DELETE', $adminRow);
    }

    public function test_render_shows_unvalidated_comments_only_to_an_admin(): void
    {
        // imageId 5: shared with the two comment-count-boundary tests
        // below (all three clean up their own inserted rows, so image 5
        // is back to its real fixture baseline of zero comments between
        // tests regardless of execution order).
        $imageId = 5;
        $this->commentRepo->insert(['author' => 'onlyvalidated_a', 'authorId' => 1, 'anonymousId' => '10.40.0.1', 'content' => 'Validated onlyValidated check.', 'validated' => true, 'imageId' => $imageId, 'websiteUrl' => null, 'email' => null]);
        $this->commentRepo->insert(['author' => 'onlyvalidated_b', 'authorId' => 1, 'anonymousId' => '10.40.0.2', 'content' => 'Unvalidated onlyValidated check.', 'validated' => false, 'imageId' => $imageId, 'websiteUrl' => null, 'email' => null]);

        try {
            $this->seedUser(1, UserStatus::Admin);
            $this->renderComments($imageId);
            $adminCount = CurrentTemplate::current()->get()->get_template_vars('COMMENT_COUNT');

            $this->seedUser(3, UserStatus::Normal);
            $this->renderComments($imageId);
            $normalCount = CurrentTemplate::current()->get()->get_template_vars('COMMENT_COUNT');

            self::assertSame(2, $adminCount);
            self::assertSame(1, $normalCount);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content IN ('Validated onlyValidated check.', 'Unvalidated onlyValidated check.')");
        }
    }

    public function test_render_leaves_the_comments_order_url_unset_when_the_image_has_no_comments(): void
    {
        // imageId 5: the only fixture image with zero real comment rows
        // (fixture comments exist on images 1 x2, 2, 3 and 4 -- see
        // Fixtures/piwigo-17.0.sql). Shared with
        // test_render_shows_unvalidated_comments_only_to_an_admin below,
        // which restores it to zero via its own finally-block cleanup, so
        // reuse here is safe regardless of test execution order.
        $imageId = 5;
        $this->seedUser(3, UserStatus::Normal);

        $this->renderComments($imageId);

        self::assertSame(0, CurrentTemplate::current()->get()->get_template_vars('COMMENT_COUNT'));
        self::assertNull(CurrentTemplate::current()->get()->get_template_vars('COMMENTS_ORDER_URL'));
    }

    public function test_render_sets_the_comments_order_url_when_the_image_has_exactly_one_comment(): void
    {
        // imageId 5: see the zero-comments test above -- the only fixture
        // image with no pre-existing comment rows, so inserting exactly
        // one here really does yield a total of one.
        $imageId = 5;
        $commentId = $this->insertComment($imageId, 3, 'Single comment boundary check.');
        $this->seedUser(3, UserStatus::Normal);

        try {
            $this->renderComments($imageId);

            self::assertSame(1, CurrentTemplate::current()->get()->get_template_vars('COMMENT_COUNT'));
            self::assertIsString(CurrentTemplate::current()->get()->get_template_vars('COMMENTS_ORDER_URL'));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::comments() . ' WHERE id = ?', [$commentId->value]);
        }
    }

    public function test_render_builds_a_clean_url_navigation_bar_and_strips_the_current_start_param(): void
    {
        // imageId 4: exclusive to this test within this file.
        $imageId = 4;
        CurrentConfigTestFactory::get()->nbCommentPage = 2;
        $sectionRegistry = Kernel::container()->get(SectionContextRegistry::class);
        if (! $sectionRegistry instanceof SectionContextRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . SectionContextRegistry::class);
        }
        // A nonzero current `start` must already be present on the
        // section context for paramsForDuplication()'s own $removed=['start']
        // to have anything real to strip.
        $sectionRegistry->set(new SectionContext(start: 42));

        $commentIds = [
            $this->insertComment($imageId, 3, 'Pagination comment 1.'),
            $this->insertComment($imageId, 3, 'Pagination comment 2.'),
            $this->insertComment($imageId, 3, 'Pagination comment 3.'),
        ];
        $this->seedUser(3, UserStatus::Normal);

        try {
            $this->renderComments($imageId);

            self::assertSame(3, CurrentTemplate::current()->get()->get_template_vars('COMMENT_COUNT'));
            $navbar = CurrentTemplate::current()->get()->get_template_vars('navbar');
            self::assertIsArray($navbar);
            self::assertArrayHasKey('URL_NEXT', $navbar);
            self::assertIsString($navbar['URL_NEXT']);
            // cleanUrl=true (real 5th arg) -- '/start-N', not '?start=N'.
            self::assertStringContainsString('/start-', $navbar['URL_NEXT']);
            // The stale start=42 from the current section context must
            // have been stripped before the nav bar appended its own.
            self::assertStringNotContainsString('start-42', $navbar['URL_NEXT']);
        } finally {
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::comments() . ' WHERE id IN (?, ?, ?)',
                array_map(static fn (CommentId $id): int => $id->value, $commentIds)
            );
            CurrentConfigTestFactory::get()->nbCommentPage = 10;
            $sectionRegistry->reset();
        }
    }

    public function test_render_shows_the_add_comment_form_with_its_full_default_field_set(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = true;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = true;
        CurrentConfigTestFactory::get()->commentsEnableWebsite = true;
        $imageId = 3;
        // A classic (Normal) user with a blank registered email -- not a
        // guest, so line 285's hide-guard never fires regardless of
        // CommentsForall (set false above specifically to also prove
        // that: LogicalAndToLogicalOr would wrongly hide the form here
        // if `!commentsForall()` alone could trip the guard for a
        // non-guest).
        $this->seedUser(3, UserStatus::Normal);

        $this->renderComments($imageId);

        $commentAdd = CurrentTemplate::current()->get()->get_template_vars('comment_add');
        self::assertIsArray($commentAdd);
        self::assertArrayHasKey('KEY', $commentAdd);
        self::assertIsString($commentAdd['KEY']);
        $keyParts = explode(':', $commentAdd['KEY']);
        self::assertCount(3, $keyParts);
        // generate()'s own $validAfterSeconds argument (3).
        self::assertSame('3', $keyParts[1]);

        unset($commentAdd['KEY']);
        self::assertSame(
            [
                'F_ACTION' => '/picture.php',
                'CONTENT' => '',
                'SHOW_AUTHOR' => false,
                'AUTHOR_MANDATORY' => true,
                'AUTHOR' => '',
                'WEBSITE_URL' => '',
                'SHOW_EMAIL' => true,
                'EMAIL_MANDATORY' => true,
                'EMAIL' => '',
                'SHOW_WEBSITE' => true,
            ],
            $commentAdd
        );
    }

    public function test_render_hides_the_add_comment_form_while_editing_an_existing_comment(): void
    {
        $imageId = 3;
        $ownerId = 3;
        $commentId = $this->insertComment($imageId, $ownerId, 'Being edited right now.');
        $this->seedUser($ownerId, UserStatus::Normal);
        CurrentConfigTestFactory::get()->userCanEditComment = true;

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $commentId, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertNull(CurrentTemplate::current()->get()->get_template_vars('comment_add'));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::comments() . ' WHERE id = ?', [$commentId->value]);
        }
    }

    public function test_render_hides_the_add_comment_form_for_a_guest_when_comments_for_all_is_disabled(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = false;
        $imageId = 3;
        // No seedUser() call -- the default CurrentUser is a guest.

        $this->renderComments($imageId);

        self::assertNull(CurrentTemplate::current()->get()->get_template_vars('comment_add'));
    }

    public function test_render_hides_the_email_prompt_for_a_classic_user_with_a_real_registered_email(): void
    {
        $imageId = 3;
        $this->seedUser(3, UserStatus::Normal, 'real-email-check@example.test');

        $this->renderComments($imageId);

        $commentAdd = CurrentTemplate::current()->get()->get_template_vars('comment_add');
        self::assertIsArray($commentAdd);
        self::assertFalse($commentAdd['SHOW_EMAIL']);
    }

    public function test_render_shows_the_author_and_email_prompts_for_a_non_classic_non_guest_viewer_with_a_real_email(): void
    {
        $imageId = 3;
        // 'generic' status: not classic (isClassicUser() === false), but
        // also not literally 'guest' (isAGuest() === false) -- the one
        // status combination that lets !isClassicUser() and a non-empty
        // email coexist for a viewer that line 285's guest-guard never
        // hides the form for.
        $this->seedUser(3, UserStatus::Generic, 'generic-email-check@example.test');

        $this->renderComments($imageId);

        $commentAdd = CurrentTemplate::current()->get()->get_template_vars('comment_add');
        self::assertIsArray($commentAdd);
        self::assertTrue($commentAdd['SHOW_AUTHOR']);
        self::assertTrue($commentAdd['SHOW_EMAIL']);
    }

    public function test_render_repopulates_website_url_and_email_after_a_rejected_submission_with_no_author(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        $imageId = 3;
        $_POST['content'] = 'Reject me for repopulation check.';
        $_POST['website_url'] = 'mutation-check.example.test';
        $_POST['email'] = 'mutation-check@example.test';
        // Deliberately no $_POST['author'] at all -- exercises the
        // postValue === null branch of the repopulation loop.
        // Not a real ephemeral key -- forces a 'reject' outcome.
        $_POST['key'] = 'totally-invalid-key';

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            $commentAdd = CurrentTemplate::current()->get()->get_template_vars('comment_add');
            self::assertIsArray($commentAdd);
            self::assertSame('', $commentAdd['AUTHOR']);
            self::assertSame('mutation-check.example.test', $commentAdd['WEBSITE_URL']);
            self::assertSame('mutation-check@example.test', $commentAdd['EMAIL']);
        } finally {
            unset($_POST['content'], $_POST['website_url'], $_POST['email'], $_POST['key']);
        }
    }

    public function test_render_sets_a_403_status_header_when_a_submission_is_rejected(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        $imageId = 3;
        $_POST['content'] = 'Rejected for status header check.';
        $_POST['key'] = 'totally-invalid-key';

        // header() is a real no-op under CLI SAPI -- setStatusHeader()'s
        // own SetStatusHeader notify event is the real side channel
        // (same technique as HtmlServiceTest's own setStatusHeader
        // coverage). HtmlService (PresentationAccessor::htmlService())
        // dispatches through the container-shared EventDispatcher, not
        // the throwaway instance passed as render()'s own $eventDispatcher
        // param.
        $captured = [];
        $handler = static function (SetStatusHeader $event) use (&$captured): void {
            $captured[] = [$event->code, $event->text];
        };
        EventDispatcherTestFactory::get()->addTypedHandler(SetStatusHeader::class, $handler);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertSame([[403, 'Forbidden']], $captured);
        } finally {
            EventDispatcherTestFactory::get()->removeEventHandler(SetStatusHeader::class, $handler);
            unset($_POST['content'], $_POST['key']);
        }
    }

    public function test_render_notifies_plugins_with_the_persisted_comm_array_and_the_resulting_action(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['content'] = 'Notify plugins comment.';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        // render()'s own $eventDispatcher param (not the container-shared
        // one) is what dispatchNotify(UserCommentInsertion) actually uses.
        $eventDispatcher = new EventDispatcher();
        $captured = null;
        $handler = static function (UserCommentInsertion $event) use (&$captured): void {
            $captured = $event->comm;
        };
        $eventDispatcher->addTypedHandler(UserCommentInsertion::class, $handler);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), $eventDispatcher, PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertIsArray($captured);
            self::assertArrayHasKey('action', $captured);
            self::assertSame('validate', $captured['action']);
            self::assertSame('Notify plugins comment.', $captured['content']);
        } finally {
            unset($_POST['content'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'Notify plugins comment.'");
        }
    }

    public function test_render_persists_trimmed_non_sentinel_author_and_content_from_a_valid_guest_submission(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['author'] = '  MutationTestAuthor  ';
        $_POST['content'] = '  Trimmed mutation content check.  ';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            $row = $this->findCommentRowByContent('Trimmed mutation content check.');
            self::assertSame('MutationTestAuthor', $row['author']);
        } finally {
            unset($_POST['author'], $_POST['content'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'Trimmed mutation content check.'");
        }
    }

    public function test_render_treats_a_literal_zero_author_as_absent_and_falls_back_to_guest(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['author'] = '0';
        $_POST['content'] = 'Zero author fallback check.';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            $row = $this->findCommentRowByContent('Zero author fallback check.');
            self::assertSame('guest', $row['author']);
        } finally {
            unset($_POST['author'], $_POST['content'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'Zero author fallback check.'");
        }
    }

    public function test_render_rejects_a_submission_whose_content_is_a_literal_zero(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['content'] = '0';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertSame(
                ['Your comment has NOT been registered because it did not pass the validation rules'],
                PageStateTestFactory::get()->errors
            );
        } finally {
            unset($_POST['content'], $_POST['key']);
        }
    }

    public function test_render_persists_a_trimmed_scheme_prefixed_website_url_from_a_valid_guest_submission(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->commentsEnableWebsite = true;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['content'] = 'Website url trim check.';
        $_POST['website_url'] = '  mutation-check.example.test  ';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            $row = $this->findCommentRowByContent('Website url trim check.');
            self::assertSame('http://mutation-check.example.test', $row['website_url']);
        } finally {
            unset($_POST['content'], $_POST['website_url'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'Website url trim check.'");
        }
    }

    public function test_render_treats_a_literal_zero_website_url_as_absent_without_rejecting(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        // Disabled deliberately: if '0' were wrongly treated as a real,
        // non-empty website_url, the honeypot check below would reject
        // regardless of URL format.
        CurrentConfigTestFactory::get()->commentsEnableWebsite = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['content'] = 'Zero website url no reject check.';
        $_POST['website_url'] = '0';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertSame(['Your comment has been registered'], PageStateTestFactory::get()->infos);
        } finally {
            unset($_POST['content'], $_POST['website_url'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'Zero website url no reject check.'");
        }
    }

    public function test_render_persists_a_trimmed_email_from_a_valid_guest_submission(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['content'] = 'Email trim check.';
        $_POST['email'] = '  mutation-check@example.test  ';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            $row = $this->findCommentRowByContent('Email trim check.');
            self::assertSame('mutation-check@example.test', $row['email']);
        } finally {
            unset($_POST['content'], $_POST['email'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'Email trim check.'");
        }
    }

    public function test_render_treats_a_literal_zero_email_as_absent_without_rejecting(): void
    {
        CurrentConfigTestFactory::get()->commentsForall = true;
        CurrentConfigTestFactory::get()->commentsValidation = false;
        CurrentConfigTestFactory::get()->commentsAuthorMandatory = false;
        CurrentConfigTestFactory::get()->commentsEmailMandatory = false;
        CurrentConfigTestFactory::get()->antiFloodTime = 0;
        $imageId = 3;
        $_POST['content'] = 'Zero email no reject check.';
        $_POST['email'] = '0';
        $_POST['key'] = new EphemeralKeyService(CurrentConfigTestFactory::get())->generate(0, (string) $imageId);

        try {
            $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

            self::assertSame(['Your comment has been registered'], PageStateTestFactory::get()->infos);
        } finally {
            unset($_POST['content'], $_POST['email'], $_POST['key']);
            $this->conn->executeStatement("DELETE FROM " . Tables::comments() . " WHERE content = 'Zero email no reject check.'");
        }
    }

    /**
     * @return list<mixed>
     */
    private function renderComments(int $imageId): array
    {
        $this->renderer()->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php', $this->sessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), $this->mailService(), PresentationAccessor::htmlService());

        return $this->renderedComments();
    }

    private function renderer(): PictureCommentRenderer
    {
        return new PictureCommentRenderer();
    }

    private function urlService(): UrlService
    {
        return UrlServiceTestFactory::build();
    }

    private function sessionService(): SessionService
    {
        return new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class),CurrentConfigTestFactory::get());
    }

    private function mailService(): MailService
    {
        $mailer = Kernel::container()->get(MailService::class);
        if (! $mailer instanceof MailService) {
            throw new LogicException('Container returned an unexpected type for ' . MailService::class);
        }

        return $mailer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commentableCategory(): array
    {
        return [['commentable' => true]];
    }

    private function seedUser(int $id, UserStatus $status, string $email = ''): void
    {
        CurrentUserTestFactory::get()->set(new User(
            id: UserId::from($id),
            username: Username::from('fixture_user_' . $id),
            email: Email::tryFrom($email),
            language: LangCode::from('en_UK'),
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

    /**
     * @return array<string, mixed>
     */
    private function findCommentRowByContent(string $content): array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::comments())
            ->where('content = :content')
            ->setParameter('content', $content)
            ->executeQuery()
            ->fetchAssociative();

        if (! is_array($row)) {
            self::fail("no persisted comment row found with content '{$content}'");
        }

        return $row;
    }
}
