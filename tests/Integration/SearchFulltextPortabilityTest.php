<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Users\UserService;
use Piwigo\Core\Lang;
use Piwigo\Users\UserRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Group\GroupEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\FilterState;
use LogicException;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Lang\Translator;
use Piwigo\Core\CurrentLogger;
use Piwigo\Db\Tables;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Category\CategoryEntity;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\CategoryStatus;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\QSingleToken;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;

/**
 * Covers qsearchGetTextTokenSearchSql()'s real per-platform FULLTEXT/
 * REGEXP branches (Piwigo\Db\* Phase F of the pgsql-support pass) end to
 * end -- built clause + real execution against real inserted rows, on
 * whichever engine .env.test currently points at (via the shared
 * DbConnection::build()), same "run against whatever's configured, no
 * driver-specific skip" approach MigrationUpgradePathTest.php already
 * established.
 *
 * Deliberately does NOT extend the shared fixture-loading path
 * (resetDatabase()/loadFixture() are still mysqli-hardcoded, Phase G) --
 * inserts and cleans up its own disposable category rows directly against
 * whatever schema is already there (the committed MySQL fixture on the
 * default .env.test, or a freshly Migrator-built schema when manually
 * pointed at Postgres), the same "don't touch the real baseline" shape
 * MigrationUpgradePathTest.php/SchemaDumpServiceTest.php already use.
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

        $mailer = Kernel::container()->get(MailService::class);
        self::assertInstanceOf(MailService::class, $mailer);
        $userService = new UserService(Lang::current(), new UserRepository($this->em, EventDispatcher::get(), CurrentConfig::current()), $this->em->getRepository(GroupEntity::class), $mailer, new ActivityService($this->em->getRepository(ActivityEntity::class)), HtmlServiceTestFactory::build(), $this->conn, new SessionService($this->em->getRepository(SessionEntity::class), CurrentConfig::current()), EventDispatcher::get(), new DeploymentPolicy(), CurrentUser::current(), CurrentConfig::current(), new InstallationFlag(), new ProcessCache(), CurrentPaths::get());

        $filterState = Kernel::container()->get(FilterState::class);
        if (! $filterState instanceof FilterState) {
            throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
        }

        $accessLevelChecker = new AccessLevelChecker(CurrentUser::current(), CurrentConfig::current());
        $this->service = new SearchService(
            $accessLevelChecker,
            $repo,
            new PermissionService(new PermissionRepository($this->em), $this->em->getRepository(GroupEntity::class), new CategoryRepository($this->em, CurrentConfig::current()), CurrentUser::current(), $filterState, $accessLevelChecker),
            new CategoryService(
                Lang::current(),
                new CategoryRepository($this->em, CurrentConfig::current()),
                new PermissionService(new PermissionRepository($this->em), $this->em->getRepository(GroupEntity::class), new CategoryRepository($this->em, CurrentConfig::current()), CurrentUser::current(), $filterState, $accessLevelChecker),
                CurrentConfig::current(),
                new EventDispatcher(),
                Translator::get(),
                $accessLevelChecker,
            ),
            $mailer,
            HtmlServiceTestFactory::build(),
            new RedirectService(Lang::current(), $userService),
            new SessionService($this->em->getRepository(SessionEntity::class), CurrentConfig::current()),
            EventDispatcher::get(),
            CurrentUser::current(),
            Lang::current(),
            CurrentConfig::current(),
            new CurrentLogger(),
            new DeploymentPolicy(),
            CurrentPaths::get(),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->insertedCategoryIds as $id) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ?', [$id]);
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
            siteId: null,
            visible: true,
            representativePictureId: null,
            uppercats: '1',
            commentable: true,
            globalRank: null,
            imageOrder: null,
            permalink: null,
            lastmodified: '2026-08-01 00:00:00',
        );
        $this->em->persist($category);
        $this->em->flush();

        self::assertIsInt($category->id);
        $this->insertedCategoryIds[] = $category->id;

        return $category->id;
    }

    /**
     * @return list<int>
     */
    private function runSearch(QSingleToken $token): array
    {
        [$clauses, $values] = $this->service->qsearchGetTextTokenSearchSql($token, ['name', 'comment']);
        self::assertNotSame([], $clauses);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT id FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $this->insertedCategoryIds) . ') AND (' . implode(' OR ', $clauses) . ')',
            $values
        );

        return array_map(
            static fn (array $row): int => is_numeric($row['id']) ? (int) $row['id'] : 0,
            $rows
        );
    }

    public function test_plain_fulltext_search_matches_a_word_in_the_name(): void
    {
        $mountainId = $this->insertCategory('Mountain View Sunset', 'a beautiful blue sky day');
        $oceanId = $this->insertCategory('Ocean Waves', 'calm water');

        $matches = $this->runSearch(new QSingleToken('Mountain', 0, null));

        self::assertSame([$mountainId], $matches);
        self::assertNotContains($oceanId, $matches);
    }

    public function test_quoted_phrase_fulltext_search_matches_an_adjacent_phrase_in_the_comment(): void
    {
        $mountainId = $this->insertCategory('Mountain View Sunset', 'a beautiful blue sky day');
        $this->insertCategory('Ocean Waves', 'a blue distant sky somewhere');

        $matches = $this->runSearch(new QSingleToken('blue sky', QSingleToken::QST_QUOTED, null));

        // Only the adjacent "blue sky" phrase matches -- "blue distant sky"
        // has the same 2 words present but not adjacent.
        self::assertSame([$mountainId], $matches);
    }

    public function test_wildcard_end_fulltext_search_matches_a_prefix(): void
    {
        $mountainId = $this->insertCategory('Mountain View Sunset', 'a beautiful blue sky day');
        $oceanId = $this->insertCategory('Ocean Waves', 'calm water');

        $matches = $this->runSearch(new QSingleToken('Moun', QSingleToken::QST_WILDCARD_END, null));

        self::assertSame([$mountainId], $matches);
        self::assertNotContains($oceanId, $matches);
    }

    /**
     * A short term (<=3 chars) always falls back to the REGEXP/~*
     * word-boundary branch, never FULLTEXT -- and is exactly the
     * historically-risky case (the MySQL ngram-stopword bug Version
     * 20260804122300's own SET SESSION fix addresses; the case-
     * insensitivity gap this Postgres branch itself needed a real ~*
     * fix for, not a bare ~ -- see qsearchGetTextTokenSearchSql()'s own
     * docblock).
     */
    public function test_short_term_regexp_search_matches_a_whole_word_case_insensitively_but_not_a_substring(): void
    {
        $catId = $this->insertCategory('Cat Show 2026', 'feline exhibition');
        $decoyId = $this->insertCategory('Data Export', 'concatenate values here');

        $matches = $this->runSearch(new QSingleToken('cat', 0, null));

        self::assertSame([$catId], $matches, 'must match the whole word "Cat" case-insensitively, not the "cat" substring inside "concatenate"');
        self::assertNotContains($decoyId, $matches);
    }
}
