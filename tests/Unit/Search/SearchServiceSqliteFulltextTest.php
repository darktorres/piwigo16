<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Cache\SearchResultsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\MigrationDependencyFactory;
use Piwigo\Db\SortRenderer;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\QSingleToken;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\LayoutStateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Exercises SearchService::qsearchGetTextTokenSearchSql()'s real SQLite
 * branch (Wave 2 of the SQLite campaign, see Version20260804122300::
 * addSqliteFts()) end to end: real REGEXP UDF, real FTS5 MATCH against
 * the real migrated schema, real inserted rows -- not just asserting on
 * the returned SQL text the way the mysqli/pgsql sibling tests in
 * SearchServiceTest.php do, since this platform's own correctness (the
 * FTS5 external-content-table sync triggers actually firing, the
 * `id IN (SELECT rowid FROM ...)` correlation actually resolving) can't
 * be taken on faith from string assertions alone.
 *
 * Deliberately self-contained, not reusing SearchServiceTest.php's own
 * near-identical searchServiceTestMakeService() -- Pest's --parallel
 * runner schedules whole test FILES onto separate worker processes, so
 * a bare top-level function defined in one file is not reliably callable
 * from another (confirmed live: this file's own tests passed calling a
 * cross-file helper when run standalone, then failed with "Call to
 * undefined function" under the full parallel suite once both files
 * landed in different workers). Every other Unit test file in this
 * codebase already follows the same one-file-one-self-contained-helper-
 * set convention for exactly this reason.
 *
 * DbTransactionTestOverride::rollback() as searchServiceSqliteTestRepo()'s
 * first line, same reason as DbConnectionTest.php's own sqlite3 test --
 * without it, DbConnection::build() would transparently return the
 * global Unit-suite override's real mysqli/pgsql test connection instead
 * of a genuine sqlite3 one. This file owns no other real-DB fixture
 * state needing that override's protection, unlike SearchServiceTest.php's
 * own 100+ tests, so ending it early here is safe.
 */
function searchServiceSqliteTestFilterState(): FilterState
{
    $filterState = Kernel::container()->get(FilterState::class);
    if (! $filterState instanceof FilterState) {
        throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
    }

    return $filterState;
}

function searchServiceSqliteTestUserService(): UserService
{
    $userService = Kernel::container()->get(UserService::class);
    if (! $userService instanceof UserService) {
        throw new LogicException('Container returned an unexpected type for ' . UserService::class);
    }

    return $userService;
}

function searchServiceSqliteTestTagService(): TagService
{
    $tagService = Kernel::container()->get(TagService::class);
    if (! $tagService instanceof TagService) {
        throw new LogicException('Container returned an unexpected type for ' . TagService::class);
    }

    return $tagService;
}

function searchServiceSqliteTestImageService(): ImageService
{
    $imageService = Kernel::container()->get(ImageService::class);
    if (! $imageService instanceof ImageService) {
        throw new LogicException('Container returned an unexpected type for ' . ImageService::class);
    }

    return $imageService;
}

function searchServiceSqliteTestPreferencesService(): PreferencesService
{
    $preferencesService = Kernel::container()->get(PreferencesService::class);
    if (! $preferencesService instanceof PreferencesService) {
        throw new LogicException('Container returned an unexpected type for ' . PreferencesService::class);
    }

    return $preferencesService;
}

function searchServiceSqliteTestSearchResultsCachePool(): SearchResultsCachePool
{
    $searchResultsCachePool = Kernel::container()->get(SearchResultsCachePool::class);
    if (! $searchResultsCachePool instanceof SearchResultsCachePool) {
        throw new LogicException('Container returned an unexpected type for ' . SearchResultsCachePool::class);
    }

    return $searchResultsCachePool;
}

/**
 * Builds a real SearchService with $repo as its SearchRepository and
 * every other collaborator built off the normal `.env.test`-driven
 * connection -- safe only because qsearchGetTextTokenSearchSql() itself,
 * the one method this file exercises, touches nothing but $this->repo.
 */
function searchServiceSqliteTestService(SearchRepository $repo): SearchService
{
    $conn = DbConnection::build();
    $accessLevelChecker = new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());

    return new SearchService(
        $accessLevelChecker,
        $repo,
        new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), searchServiceSqliteTestFilterState(), $accessLevelChecker),
        new CategoryService(
            LangTestFactory::get(),
            new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()),
            new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()), CurrentUserTestFactory::get(), searchServiceSqliteTestFilterState(), $accessLevelChecker),
            CurrentConfigTestFactory::get(),
            new EventDispatcher(),
            TranslatorTestFactory::get(),
            $accessLevelChecker),
        HtmlServiceTestFactory::build(),
        new RedirectService(LangTestFactory::get(), searchServiceSqliteTestUserService(), EventDispatcherTestFactory::get(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get())),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
        EventDispatcherTestFactory::get(),
        CurrentUserTestFactory::get(),
        CurrentConfigTestFactory::get(),
        new SortRenderer($conn),
        searchServiceSqliteTestTagService(),
        searchServiceSqliteTestImageService(),
        searchServiceSqliteTestUserService(),
        searchServiceSqliteTestPreferencesService(),
        searchServiceSqliteTestSearchResultsCachePool(),
    );
}

/**
 * @return array{0: SearchRepository, 1: Connection}
 */
function searchServiceSqliteTestRepo(): array
{
    DbTransactionTestOverride::rollback();

    // Save+restore, not a blind unset -- this process's real env already
    // carries .env.test's own PIWIGO_DB_DRIVER/PIWIGO_DB_BASE (mysqli/
    // piwigo17_2_test), and every other test in this same worker process
    // (this file included, via searchServiceSqliteTestService()'s own
    // DbConnection::build() call) needs those back exactly as they were,
    // not unset outright.
    $originalDriver = getenv('PIWIGO_DB_DRIVER');
    $originalBase = getenv('PIWIGO_DB_BASE');

    putenv('PIWIGO_DB_DRIVER=sqlite3');
    putenv('PIWIGO_DB_BASE=:memory:');

    $conn = DbConnection::build();

    putenv($originalDriver === false ? 'PIWIGO_DB_DRIVER' : 'PIWIGO_DB_DRIVER=' . $originalDriver);
    putenv($originalBase === false ? 'PIWIGO_DB_BASE' : 'PIWIGO_DB_BASE=' . $originalBase);

    $em = EntityManagerFactory::build($conn);
    $depFactory = MigrationDependencyFactory::build($em);
    $input = new ArrayInput([
        'version' => 'latest',
        '--allow-no-migration' => true,
    ]);
    $input->setInteractive(false);
    $exitCode = new MigrateCommand($depFactory)
        ->run($input, new BufferedOutput());
    if ($exitCode !== 0) {
        throw new RuntimeException('searchServiceSqliteTestRepo(): migration run failed');
    }

    return [new SearchRepository($em), $conn];
}

beforeEach(function (): void {
    // searchServiceSqliteTestService() resolves several collaborators via
    // Kernel::container()->get(...) -- same minimal boot as
    // PermissionServiceTest.php's own established precedent, this file
    // needs nothing from CurrentUser/CurrentConfig beyond their
    // container-resolvable defaults.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
});

afterEach(function (): void {
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    LangTestFactory::get()->reset();
    TranslatorTestFactory::get()->reset();
    Kernel::reset();
});

test('isSqlite() reports true for a real sqlite3 connection', function (): void {
    // Not also asserting a real .env.test-backed repo's isSqlite() ===
    // false here -- once Kernel::boot() has run (this file's own
    // beforeEach()), DbConnection::dbCredentials() resolves
    // DbCredentials::class as a container SINGLETON, cached at first
    // resolution for the rest of this Kernel's lifetime; a later
    // putenv() swap has no effect on it. Real requests never swap
    // PIWIGO_DB_DRIVER mid-process, so this isn't a real gap -- every
    // mysqli/pgsql-branch test throughout SearchServiceTest.php already
    // exercises isSqlite() === false implicitly (those tests take the
    // isPostgres()/mysqli branches, not this one).
    [$sqliteRepo] = searchServiceSqliteTestRepo();

    expect($sqliteRepo->isSqlite())
        ->toBeTrue()
        ->and($sqliteRepo->isPostgres())
        ->toBeFalse();
});

test('qsearchGetTextTokenSearchSql() REGEXP branch matches a real row on sqlite3', function (): void {
    [$repo, $conn] = searchServiceSqliteTestRepo();
    $conn->executeStatement("INSERT INTO categories (id, name, uppercats) VALUES (1, 'Mountain Landscape', '1')");

    // QST_WILDCARD_BEGIN forces useFt=false regardless of term length,
    // same REGEXP-fallback trigger as this method's mysqli/pgsql sibling
    // tests in SearchServiceTest.php.
    $token = new QSingleToken('ountain', QSingleToken::QST_WILDCARD_BEGIN, null);
    $service = searchServiceSqliteTestService($repo);

    [$clauses, $values] = $service->qsearchGetTextTokenSearchSql($token, ['name'], 'categories_fts');

    expect($clauses)
        ->toBe(['name REGEXP ?'])
        ->and($values)
        ->toBe(['ountain\\b']);

    $count = $conn->fetchOne(
        'SELECT COUNT(*) FROM categories WHERE (' . implode(' OR ', $clauses) . ')',
        $values
    );
    expect($count)
        ->toBe(1);
});

test('qsearchGetTextTokenSearchSql() FTS5 branch finds a real row and avoids the documented ngram false-positive class', function (): void {
    [$repo, $conn] = searchServiceSqliteTestRepo();
    $conn->executeStatement("INSERT INTO categories (id, name, uppercats) VALUES (1, 'families', '1')");
    $conn->executeStatement("INSERT INTO categories (id, name, uppercats) VALUES (2, 'Nested Sub Album', '2')");

    $token = new QSingleToken('families', 0, null);
    $service = searchServiceSqliteTestService($repo);

    [$clauses, $values] = $service->qsearchGetTextTokenSearchSql($token, ['name', 'comment'], 'categories_fts');

    expect($clauses)
        ->toBe(['id IN (SELECT rowid FROM categories_fts WHERE categories_fts MATCH ?) AND (name LIKE ? OR comment LIKE ?)'])
        ->and($values)
        ->toBe(['"families"', '%families%', '%families%']);

    $rows = $conn->fetchFirstColumn(
        'SELECT id FROM categories WHERE (' . implode(' OR ', $clauses) . ')',
        $values
    );
    expect($rows)
        ->toBe([1]);
});

test('qsearchGetTextTokenSearchSql() FTS5 branch wraps a quoted term and appends a star for a trailing wildcard', function (): void {
    [$repo] = searchServiceSqliteTestRepo();
    $service = searchServiceSqliteTestService($repo);

    $quoted = $service->qsearchGetTextTokenSearchSql(new QSingleToken('nature', QSingleToken::QST_QUOTED, null), ['name', 'comment'], 'categories_fts');
    expect($quoted[1])->toBe(['"nature"', '%nature%', '%nature%']);

    $wildcard = $service->qsearchGetTextTokenSearchSql(new QSingleToken('travel', QSingleToken::QST_WILDCARD_END, null), ['name', 'comment'], 'categories_fts');
    expect($wildcard[1])->toBe(['"travel"*', 'travel%', 'travel%']);
});

test('qsearchGetTextTokenSearchSql() FTS5 branch syncs through a real UPDATE/DELETE via the trigger set', function (): void {
    [$repo, $conn] = searchServiceSqliteTestRepo();
    $conn->executeStatement("INSERT INTO images (id, name, author) VALUES (1, 'Vacation Photos', 'Jane Doe')");

    $service = searchServiceSqliteTestService($repo);
    $searchFor = function (string $term) use ($service, $conn): array {
        [$clauses, $values] = $service->qsearchGetTextTokenSearchSql(new QSingleToken($term, 0, null), ['name', 'comment'], 'images_fts');

        return $conn->fetchFirstColumn('SELECT id FROM images WHERE (' . implode(' OR ', $clauses) . ')', $values);
    };

    expect($searchFor('vacation'))
        ->toBe([1]);

    $conn->executeStatement("UPDATE images SET name = 'Winter Trip' WHERE id = 1");
    expect($searchFor('vacation'))
        ->toBe([])
        ->and($searchFor('winter'))
        ->toBe([1]);

    $conn->executeStatement('DELETE FROM images WHERE id = 1');
    expect($searchFor('winter'))
        ->toBe([]);
});
