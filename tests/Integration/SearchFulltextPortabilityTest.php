<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Cache\SearchResultsCachePool;
use Piwigo\Category\CategoryEntity;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\CategoryStatus;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SortRenderer;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\QSingleToken;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * Covers qsearchGetTextTokenSearchSql()'s real per-platform FULLTEXT/
 * REGEXP branches end to end -- built clause + real execution against
 * real inserted rows, on whichever engine .env.test currently points at
 * (via the shared DbConnection::build()), the same "run against whatever's
 * configured, no driver-specific skip" approach MigrationUpgradePathTest.php
 * uses.
 *
 * Does not use the shared fixture-loading path (resetDatabase()/
 * loadFixture() are mysqli-hardcoded) -- inserts and cleans up its own
 * disposable category rows directly against whatever schema is already
 * there (the committed MySQL fixture on the default .env.test, or a
 * freshly Migrator-built schema when manually pointed at Postgres), the
 * same "don't touch the real baseline" shape MigrationUpgradePathTest.php/
 * SchemaDumpServiceTest.php use.
 */
final class SearchFulltextPortabilityTest extends IntegrationTestCase
{
    private Connection $conn;

    private EntityManagerInterface $em;

    private SearchService $service;

    /**
     * @var list<int>
     */
    private array $insertedCategoryIds = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        Kernel::boot();

        $this->conn = DbConnection::build();
        $this->em = EntityManagerFactory::build($this->conn);
        $repo = new SearchRepository($this->em);

        $filterState = Kernel::container()->get(FilterState::class);
        if (! $filterState instanceof FilterState) {
            throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
        }

        $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());
        $searchResultsCachePool = Kernel::container()->get(SearchResultsCachePool::class);
        if (! $searchResultsCachePool instanceof SearchResultsCachePool) {
            throw new LogicException('Container returned an unexpected type for ' . SearchResultsCachePool::class);
        }
        $permissionService = new PermissionService(new PermissionRepository($this->em), $this->em->getRepository(GroupEntity::class), new CategoryRepository($this->em, CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), $filterState, $accessLevelChecker);
        $categoryService = new CategoryService(
            LangTestFactory::get(),
            new CategoryRepository($this->em, CurrentConfigTestFactory::get()),
            $permissionService,
            CurrentConfigTestFactory::get(),
            new EventDispatcher(),
            TranslatorTestFactory::get(),
            $accessLevelChecker,
            new UserRepository($this->em, new EventDispatcher(), CurrentConfigTestFactory::get()),
        );

        $userService = new UserService(LangTestFactory::get(), new UserRepository($this->em, EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get()), $this->em->getRepository(GroupEntity::class), new ActivityService($this->em->getRepository(ActivityEntity::class)), HtmlServiceTestFactory::build(), new SessionService($this->em->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get(), new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), new InstallationFlag(), new ProcessCache(), CurrentPathsTestFactory::get(), $this->em, $permissionService, $categoryService, new PasswordService(new PasswordRepository($this->em), new DeploymentPolicy()));

        $this->service = new SearchService(
            $accessLevelChecker,
            $repo,
            $permissionService,
            $categoryService,
            HtmlServiceTestFactory::build(),
            new RedirectService(LangTestFactory::get(), $userService, EventDispatcherTestFactory::get(), PageStateTestFactory::get()),
            new SessionService($this->em->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
            EventDispatcherTestFactory::get(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            new SortRenderer($this->conn),
            new TagService(LangTestFactory::get(), $this->em->getRepository(TagEntity::class), $permissionService, new ActivityService($this->em->getRepository(ActivityEntity::class)), EventDispatcherTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), new CurrentLogger(), new ImageService($this->em->getRepository(ImageEntity::class), new ActivityService($this->em->getRepository(ActivityEntity::class)), new SessionService($this->em->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get(), $categoryService)),
            $userService,
            new PreferencesService(new UserRepository($this->em, EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get()),
            $searchResultsCachePool,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->insertedCategoryIds as $id) {
            $this->conn->executeStatement('DELETE FROM categories WHERE id = ?', [$id]);
        }

        parent::tearDown();
    }

    private function insertCategory(string $name, ?string $comment): int
    {
        $category = new CategoryEntity(
            name: $name,
            idUppercat: null,
            comment: $comment,
            dir: null,
            rank: null,
            status: CategoryStatus::Public,
            site: null,
            visible: true,
            representativePicture: null,
            uppercats: '1',
            commentable: true,
            globalRank: null,
            imageOrder: null,
            permalink: null,
            lastmodified: SqlDateTime::from('2026-08-01 00:00:00'),
        );
        $this->em->persist($category);
        $this->em->flush();

        self::assertInstanceOf(CategoryId::class, $category->id);
        $this->insertedCategoryIds[] = $category->id->value;

        return $category->id->value;
    }

    /**
     * @return list<int>
     */
    private function runSearch(QSingleToken $token): array
    {
        [$clauses, $values] = $this->service->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);
        self::assertNotSame([], $clauses);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT id FROM categories WHERE id IN (' . implode(',', $this->insertedCategoryIds) . ') AND (' . implode(' OR ', $clauses) . ')',
            $values
        );

        return array_map(
            static fn (array $row): int => is_numeric($row['id']) ? (int) $row['id'] : 0,
            $rows
        );
    }

    public function testPlainFulltextSearchMatchesAWordInTheName(): void
    {
        $mountainId = $this->insertCategory('Mountain View Sunset', 'a beautiful blue sky day');
        $oceanId = $this->insertCategory('Ocean Waves', 'calm water');

        $matches = $this->runSearch(new QSingleToken('Mountain', 0, null));

        self::assertSame([$mountainId], $matches);
        self::assertNotContains($oceanId, $matches);
    }

    public function testQuotedPhraseFulltextSearchMatchesAnAdjacentPhraseInTheComment(): void
    {
        $mountainId = $this->insertCategory('Mountain View Sunset', 'a beautiful blue sky day');
        $this->insertCategory('Ocean Waves', 'a blue distant sky somewhere');

        $matches = $this->runSearch(new QSingleToken('blue sky', QSingleToken::QST_QUOTED, null));

        // Only the adjacent "blue sky" phrase matches -- "blue distant sky"
        // has the same 2 words present but not adjacent.
        self::assertSame([$mountainId], $matches);
    }

    public function testWildcardEndFulltextSearchMatchesAPrefix(): void
    {
        $mountainId = $this->insertCategory('Mountain View Sunset', 'a beautiful blue sky day');
        $oceanId = $this->insertCategory('Ocean Waves', 'calm water');

        $matches = $this->runSearch(new QSingleToken('Moun', QSingleToken::QST_WILDCARD_END, null));

        self::assertSame([$mountainId], $matches);
        self::assertNotContains($oceanId, $matches);
    }

    /**
     * A short term (<=3 chars) always falls back to the REGEXP/~*
     * word-boundary branch, never FULLTEXT. On Postgres this requires the
     * case-insensitive `~*` operator, not a bare `~` -- see
     * qsearchGetTextTokenSearchSql()'s own docblock.
     */
    public function testShortTermRegexpSearchMatchesAWholeWordCaseInsensitivelyButNotASubstring(): void
    {
        $catId = $this->insertCategory('Cat Show 2026', 'feline exhibition');
        $decoyId = $this->insertCategory('Data Export', 'concatenate values here');

        $matches = $this->runSearch(new QSingleToken('cat', 0, null));

        self::assertSame([$catId], $matches, 'must match the whole word "Cat" case-insensitively, not the "cat" substring inside "concatenate"');
        self::assertNotContains($decoyId, $matches);
    }
}
