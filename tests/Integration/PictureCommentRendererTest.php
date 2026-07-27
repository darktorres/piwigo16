<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Comment\CommentRepository;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Picture\PictureCommentRenderer;
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
        CurrentTemplate::set(new Template(\Piwigo\Core\CurrentPaths::get()->root . 'themes', 'default'));
        session_id('fixed-test-session-id'); // CsrfService::getToken() needs a session id, not a running session.
        unset($_POST['content']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        CurrentTemplate::reset();
        CurrentUser::reset();
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

        $this->renderer()->render($commentIdB, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php');

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
        $this->renderer()->render(null, $imageId, 0, $this->urlService(), $this->commentableCategory(), '/picture.php');

        return $this->renderedComments();
    }

    private function renderer(): PictureCommentRenderer
    {
        return new PictureCommentRenderer();
    }

    private function urlService(): UrlService
    {
        return new UrlService(new HtmlService());
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
        CurrentUser::set(new User(
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
        $vars = CurrentTemplate::get()->get_template_vars('comments');

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
