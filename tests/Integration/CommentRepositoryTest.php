<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class CommentRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CommentRepository $repo;

    private \Doctrine\DBAL\Connection $conn;

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

        $this->conn = DbConnection::build();
        $this->repo = new CommentRepository($this->conn);
    }

    public function test_insert_creates_a_new_comment_and_returns_its_id(): void
    {
        $id = $this->repo->insert([
            'author' => 'new_author',
            'authorId' => 2,
            'anonymousId' => '10.0.0',
            'content' => 'A brand new comment.',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => 'http://example.test',
            'email' => 'new@example.test',
        ]);

        self::assertGreaterThan(0, $id);
        self::assertSame('A brand new comment.', $this->fetchContent($id));
    }

    public function test_delete_removes_comments_regardless_of_author_when_author_id_is_null(): void
    {
        $id = $this->insertFixtureComment(['authorId' => 1]);

        $deleted = $this->repo->delete([$id], null);

        self::assertSame(1, $deleted);
        self::assertNull($this->fetchContent($id));
    }

    public function test_delete_restricted_to_author_id_does_nothing_for_a_different_author(): void
    {
        $id = $this->insertFixtureComment(['authorId' => 3]);

        $deleted = $this->repo->delete([$id], 999);

        self::assertSame(0, $deleted);
        self::assertNotNull($this->fetchContent($id));
    }

    public function test_delete_restricted_to_author_id_removes_a_matching_comment(): void
    {
        $id = $this->insertFixtureComment(['authorId' => 3]);

        $deleted = $this->repo->delete([$id], 3);

        self::assertSame(1, $deleted);
    }

    public function test_delete_returns_zero_for_empty_ids(): void
    {
        self::assertSame(0, $this->repo->delete([], null));
    }

    public function test_update_changes_content_and_website_url(): void
    {
        $id = $this->insertFixtureComment();

        $updated = $this->repo->update(
            $id,
            ['content' => 'Edited content.', 'websiteUrl' => 'http://edited.test', 'validated' => true],
            null
        );

        self::assertTrue($updated);
        self::assertSame('Edited content.', $this->fetchContent($id));
    }

    public function test_update_restricted_to_author_id_does_nothing_for_a_different_author(): void
    {
        $id = $this->insertFixtureComment(['authorId' => 3]);

        $updated = $this->repo->update(
            $id,
            ['content' => 'Should not apply.', 'websiteUrl' => null, 'validated' => true],
            999
        );

        self::assertFalse($updated);
        self::assertNotSame('Should not apply.', $this->fetchContent($id));
    }

    public function test_find_author_id_returns_false_for_a_missing_comment(): void
    {
        self::assertFalse($this->repo->findAuthorId(999999));
    }

    public function test_find_author_id_returns_the_numeric_author_id_as_string(): void
    {
        self::assertSame('1', $this->repo->findAuthorId(1));
    }

    public function test_validate_marks_the_given_comments_validated(): void
    {
        $id = $this->insertFixtureComment(['validated' => false]);

        $this->repo->validate([$id]);

        self::assertSame('true', $this->fetchValidated($id));
    }

    public function test_validate_is_a_no_op_for_empty_ids(): void
    {
        $id = $this->insertFixtureComment(['validated' => false]);

        $before = $this->fetchValidated($id);
        $this->repo->validate([]);

        self::assertSame($before, $this->fetchValidated($id));
    }

    public function test_count_recent_comments_counts_within_the_flood_window(): void
    {
        // author_id has an FK onto piwigo_users, so a real fixture user id
        // is needed -- 4 (power_user), not reused by insertFixtureComment()
        // (which only ever uses 1 or 3), so no other test's disposable rows
        // inflate this count. Fixture comments 3 and 4 (also author_id 4)
        // are seeded at the same uniform timestamp every fixture row uses
        // (2026-08-01 00:00:00, matching PIWIGO_TEST_NOW) -- pushed safely
        // into the past here, scoped to this test only, so only the fresh
        // insert below counts as "recent".
        $this->conn->executeStatement(
            "UPDATE " . Tables::comments() . " SET date = '2026-01-01 00:00:00' WHERE author_id = 4"
        );

        $this->repo->insert([
            'author' => 'power_user',
            'authorId' => 4,
            'anonymousId' => '10.0.1',
            'content' => 'Just posted.',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        self::assertSame(1, $this->repo->countRecentComments(4, null, 3600));
        self::assertSame(0, $this->repo->countRecentComments(4, null, 0));
    }

    public function test_count_recent_comments_restricts_to_the_anonymous_id_prefix(): void
    {
        // anonymous_id stores the full, untrimmed IP (matches
        // CommentService::insertComment()'s own real usage); the prefix
        // filter re-adds ".%" to the trimmed 3-octet form to match any
        // host on that subnet.
        $this->repo->insert([
            'author' => 'guest',
            'authorId' => 2,
            'anonymousId' => '10.0.2.55',
            'content' => 'Anonymous post.',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        self::assertSame(1, $this->repo->countRecentComments(2, '10.0.2', 3600));
        self::assertSame(0, $this->repo->countRecentComments(2, '10.0.99', 3600));
    }

    public function test_username_exists_matches_an_existing_username(): void
    {
        self::assertTrue($this->repo->usernameExists('username', 'fixture_admin'));
        // the `users` table uses a `_ci` (case-insensitive) collation, same
        // as the original's own plain `=` comparison -- not a property of
        // this query, but of the schema it queries.
        self::assertTrue($this->repo->usernameExists('username', 'FIXTURE_ADMIN'));
        self::assertFalse($this->repo->usernameExists('username', 'does-not-exist'));
    }

    public function test_clear_nb_comments_cache_resets_every_row(): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userCache())
            ->set('nb_available_comments', '5')
            ->executeStatement();

        $this->repo->clearNbCommentsCache();

        $value = $this->conn->createQueryBuilder()
            ->select('nb_available_comments')
            ->from(Tables::userCache())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertNull($value);
    }

    /**
     * Inserts a fresh, disposable comment for destructive tests (delete/
     * update/validate) so they never depend on -- or on run order relative
     * to -- fixture rows shared with other tests in this class (the
     * fixture is loaded once per class, not reset per test).
     *
     * @param array{authorId?: int, validated?: bool} $overrides
     */
    private function insertFixtureComment(array $overrides = []): int
    {
        return $this->repo->insert([
            'author' => 'disposable_author',
            'authorId' => $overrides['authorId'] ?? 1,
            'anonymousId' => '10.10.10.10',
            'content' => 'Disposable comment for a destructive test.',
            'validated' => $overrides['validated'] ?? true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);
    }

    private function fetchContent(int $commentId): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('content')
            ->from(Tables::comments())
            ->where('id = :id')
            ->setParameter('id', $commentId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    private function fetchValidated(int $commentId): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('validated')
            ->from(Tables::comments())
            ->where('id = :id')
            ->setParameter('id', $commentId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }
}
