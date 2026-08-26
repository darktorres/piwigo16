<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Api\Comments\CommentListController;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Http\AdminGuard;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Psr\Http\Message\ResponseInterface;

/**
 * Zero coverage of any kind existed for this controller before this file
 * (see the array-to-object refactoring plan's own "write tests first"
 * note) -- its author/guest-detection fallback chain
 * (`CommentListController::__invoke()`'s `$authorName` branch) is subtle
 * enough that converting `CommentRepository::findList()`'s row shape
 * without a safety net first would have been unverifiable churn. Fixture
 * users: id=1 is `fixture_admin`/webmaster (== CurrentConfig::$webmasterId),
 * id=2 is `guest` (== CurrentConfig::$guestId), id=3/4 are `normal`.
 */
final class CommentListControllerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CommentRepository $repo;

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

        $currentConfig = $this->resolve(CurrentConfig::class);
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        Kernel::boot();

        $conn = DbConnection::build();
        $this->repo = TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(CommentEntity::class), CommentRepository::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        Kernel::reset();
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function resolve(string $class): object
    {
        $instance = Kernel::container()->get($class);
        if (! $instance instanceof $class) {
            throw new LogicException('Container returned an unexpected type for ' . $class);
        }

        return $instance;
    }

    private function seedUser(UserStatus $status, int $id): void
    {
        $this->resolve(CurrentUser::class)->set(new User(
            id: UserId::from($id),
            username: null,
            email: null,
            language: LangCode::from('en_UK'),
            theme: ThemeId::from('default'),
            status: $status,
            enabledHigh: false,
        ));
    }

    private function seedAdmin(): void
    {
        $this->seedUser(UserStatus::Webmaster, 1);
    }

    private function buildController(): CommentListController
    {
        return new CommentListController(
            new AdminGuard(new AccessControl(
                $this->resolve(HtmlRenderingInterface::class),
                $this->resolve(RedirectServiceInterface::class),
                $this->resolve(AccessLevelChecker::class),
            )),
            $this->resolve(CommentService::class),
            $this->resolve(Lang::class),
            $this->resolve(CurrentConfig::class),
            $this->resolve(UrlServiceInterface::class),
            $this->resolve(EventDispatcher::class),
        );
    }

    /**
     * @param array{author: string, authorId: int|null, anonymousId: string, content: string, validated: bool, imageId: int, websiteUrl: string|null, email: string|null} $data
     */
    private function insertComment(array $data): int
    {
        return $this->repo->insert($data)
            ->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function obj(array $data, string $key): array
    {
        $value = $data[$key];
        self::assertIsArray($value);

        $result = [];
        foreach ($value as $k => $v) {
            self::assertIsString($k);
            $result[$k] = $v;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function rows(array $data, string $key): array
    {
        $value = $data[$key];
        self::assertIsArray($value);

        $result = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            $item = [];
            foreach ($row as $k => $v) {
                self::assertIsString($k);
                $item[$k] = $v;
            }
            $result[] = $item;
        }

        return $result;
    }

    public function testInvokeReturns401WhenNoSessionIsSignedIn(): void
    {
        $this->seedUser(UserStatus::Guest, 2);

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/comments'));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testInvokeReturns403WhenSignedInButNotAnAdmin(): void
    {
        $this->seedUser(UserStatus::Normal, 3);

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/comments'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testInvokeReturns403WhenCommentsAreDisabled(): void
    {
        $this->seedAdmin();
        $this->resolve(CurrentConfig::class)->activateComments = false;

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/comments'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testInvokeReturns422ForAnInvalidStatus(): void
    {
        $this->seedAdmin();

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/comments?status=bogus'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testInvokeReturns422ForAnInvalidPerPage(): void
    {
        $this->seedAdmin();

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/comments?perPage=7'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testInvokeReturns422ForAnUnparseableMinDate(): void
    {
        $this->seedAdmin();

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/comments?minDate=not-a-date'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testInvokeReturns422ForAnUnparseableMaxDate(): void
    {
        $this->seedAdmin();

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/comments?maxDate=not-a-date'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testInvokeListsAJoinedCommentWithSummaryAndFilters(): void
    {
        $marker = 'clct-happy-' . uniqid();
        $this->insertComment([
            'author' => 'ignored_since_a_real_username_exists',
            'authorId' => 3,
            'anonymousId' => '10.30.1.1',
            'content' => $marker,
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $this->seedAdmin();

        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/comments?search=' . urlencode($marker)));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);

        $comments = $this->rows($body, 'comments');
        self::assertCount(1, $comments);
        $comment = $comments[0];
        self::assertSame($marker, $comment['content']);
        self::assertSame($marker, $comment['contentRaw']);
        self::assertFalse($comment['isPending']);

        $summary = $this->obj($body, 'summary');
        self::assertSame(1, $summary['allComments']);
        self::assertSame(1, $summary['validated']);
        self::assertSame(0, $summary['pending']);

        $filters = $this->obj($body, 'filters');
        self::assertArrayHasKey('nbAuthors', $filters);

        $paging = $this->obj($body, 'paging');
        self::assertSame(0, $paging['page']);
        self::assertSame(10, $paging['perPage']);
    }

    public function testInvokeUsesTheRealUsernameForANonGuestNonWebmasterAuthor(): void
    {
        $marker = 'clct-normal-author-' . uniqid();
        $this->insertComment([
            'author' => 'stale free-text author, must be ignored',
            'authorId' => 3, // regular_user, status normal
            'anonymousId' => '10.30.1.2',
            'content' => $marker,
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $this->seedAdmin();

        $body = $this->decode($this->buildController()(new ServerRequest('GET', '/api/v1/comments?search=' . urlencode($marker))));
        $comment = $this->rows($body, 'comments')[0];

        self::assertSame('regular_user', $comment['author']);
        self::assertSame('normal', $comment['authorStatus']);
    }

    public function testInvokeMarksTheWebmasterAuthorAsMainUser(): void
    {
        $marker = 'clct-webmaster-author-' . uniqid();
        $this->insertComment([
            'author' => 'stale free-text author, must be ignored',
            'authorId' => 1, // fixture_admin, the real webmasterId
            'anonymousId' => '10.30.1.3',
            'content' => $marker,
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $this->seedAdmin();

        $body = $this->decode($this->buildController()(new ServerRequest('GET', '/api/v1/comments?search=' . urlencode($marker))));
        $comment = $this->rows($body, 'comments')[0];

        self::assertSame('fixture_admin', $comment['author']);
        self::assertSame('main_user', $comment['authorStatus']);
    }

    public function testInvokeFallsBackToTheFreeTextAuthorForAGuestAuthorId(): void
    {
        $marker = 'clct-guest-author-' . uniqid();
        $this->insertComment([
            'author' => 'Jane Visitor',
            'authorId' => 2, // the real guestId
            'anonymousId' => '10.30.1.4',
            'content' => $marker,
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $this->seedAdmin();

        $body = $this->decode($this->buildController()(new ServerRequest('GET', '/api/v1/comments?search=' . urlencode($marker))));

        self::assertSame('Jane Visitor', $this->rows($body, 'comments')[0]['author']);
    }

    public function testInvokeFallsBackToTheFreeTextAuthorForAnAnonymousComment(): void
    {
        $marker = 'clct-anon-author-' . uniqid();
        $this->insertComment([
            'author' => 'Anonymous Commenter',
            'authorId' => null,
            'anonymousId' => '10.30.1.5',
            'content' => $marker,
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $this->seedAdmin();

        $body = $this->decode($this->buildController()(new ServerRequest('GET', '/api/v1/comments?search=' . urlencode($marker))));

        self::assertSame('Anonymous Commenter', $this->rows($body, 'comments')[0]['author']);
    }

    public function testInvokeAppliesTheStatusFilter(): void
    {
        $marker = 'clct-status-' . uniqid();
        $this->insertComment([
            'author' => 'a',
            'authorId' => 3,
            'anonymousId' => '10.30.1.6',
            'content' => $marker . ' validated',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);
        $this->insertComment([
            'author' => 'a',
            'authorId' => 3,
            'anonymousId' => '10.30.1.7',
            'content' => $marker . ' pending',
            'validated' => false,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $this->seedAdmin();

        $body = $this->decode($this->buildController()(new ServerRequest('GET', '/api/v1/comments?search=' . urlencode($marker) . '&status=pending')));
        $comments = $this->rows($body, 'comments');

        self::assertCount(1, $comments);
        self::assertTrue($comments[0]['isPending']);
        self::assertSame($marker . ' pending', $comments[0]['content']);
    }
}
