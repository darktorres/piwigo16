<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Comment\CommentRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see CommentRepository}. The fixture
 * seeds one comment (id 1, image_id 1, author 'fixture_admin', validated).
 */
final class CommentRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private CommentRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new CommentRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_countAll_returns_fixture_comment_count(): void
    {
        self::assertSame(1, $this->repo->countAll());
    }

    public function test_countUnvalidated_returns_zero_for_validated_fixture(): void
    {
        self::assertSame(0, $this->repo->countUnvalidated());
    }

    public function test_insert_round_trips_and_increments_count(): void
    {
        $newId = $this->repo->insert([
            'author'       => 'integration_test_author',
            'author_id'    => 1,
            'anonymous_id' => '127.0.0.1',
            'content'      => 'Integration-test comment',
            'image_id'     => 1,
            'validated'    => true,
        ]);

        self::assertGreaterThan(1, $newId);
        self::assertSame(2, $this->repo->countAll());

        $deleted = $this->repo->delete($newId);
        self::assertSame(1, $deleted);
        self::assertSame(1, $this->repo->countAll());
    }

    /**
     * fk_comments_image_id is ON DELETE CASCADE — removing the parent image
     * must remove its comments.
     */
    public function test_image_delete_cascades_to_comments(): void
    {
        self::assertSame(1, $this->repo->countAll(), 'fixture precondition');

        $this->conn->executeStatement('DELETE FROM piwigo_images WHERE id = 1');

        self::assertSame(0, $this->repo->countAll(), 'fk_comments_image_id CASCADE removed the orphan comment');
    }
}
