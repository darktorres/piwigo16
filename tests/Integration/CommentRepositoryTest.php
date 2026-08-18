<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use LogicException;
use Override;
use Piwigo\Comment\CommentApiCriteria;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentRepository;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\SqlCondition;
use Piwigo\Sort\CommentSortField;

final class CommentRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CommentRepository $repo;

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

        $this->conn = DbConnection::build();
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(CommentEntity::class);
    }

    public function testInsertCreatesANewCommentAndReturnsItsId(): void
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

        self::assertGreaterThan(0, $id->value);
        self::assertSame('A brand new comment.', $this->fetchContent($id));
    }

    public function testDeleteRemovesCommentsRegardlessOfAuthorWhenAuthorIdIsNull(): void
    {
        $id = $this->insertFixtureComment([
            'authorId' => 1,
        ]);

        $deleted = $this->repo->delete([$id], null);

        self::assertSame(1, $deleted);
        self::assertNull($this->fetchContent($id));
    }

    public function testDeleteRestrictedToAuthorIdDoesNothingForADifferentAuthor(): void
    {
        $id = $this->insertFixtureComment([
            'authorId' => 3,
        ]);

        $deleted = $this->repo->delete([$id], 999);

        self::assertSame(0, $deleted);
        self::assertNotNull($this->fetchContent($id));
    }

    public function testDeleteRestrictedToAuthorIdRemovesAMatchingComment(): void
    {
        $id = $this->insertFixtureComment([
            'authorId' => 3,
        ]);

        $deleted = $this->repo->delete([$id], 3);

        self::assertSame(1, $deleted);
    }

    public function testDeleteReturnsZeroForEmptyIds(): void
    {
        self::assertSame(0, $this->repo->delete([], null));
    }

    public function testUpdateChangesContentAndWebsiteUrl(): void
    {
        $id = $this->insertFixtureComment();

        $updated = $this->repo->update(
            $id,
            [
                'content' => 'Edited content.',
                'websiteUrl' => 'http://edited.test',
                'validated' => true,
            ],
            null
        );

        self::assertTrue($updated);
        self::assertSame('Edited content.', $this->fetchContent($id));
    }

    public function testUpdateRestrictedToAuthorIdDoesNothingForADifferentAuthor(): void
    {
        $id = $this->insertFixtureComment([
            'authorId' => 3,
        ]);

        $updated = $this->repo->update(
            $id,
            [
                'content' => 'Should not apply.',
                'websiteUrl' => null,
                'validated' => true,
            ],
            999
        );

        self::assertFalse($updated);
        self::assertNotSame('Should not apply.', $this->fetchContent($id));
    }

    public function testFindAuthorIdReturnsFalseForAMissingComment(): void
    {
        self::assertFalse($this->repo->findAuthorId(CommentId::from(999999)));
    }

    public function testFindAuthorIdReturnsTheNumericAuthorIdAsString(): void
    {
        self::assertSame('1', $this->repo->findAuthorId(CommentId::from(1)));
    }

    public function testValidateMarksTheGivenCommentsValidated(): void
    {
        $id = $this->insertFixtureComment([
            'validated' => false,
        ]);

        $this->repo->validate([$id]);

        self::assertSame(1, $this->fetchValidated($id));
    }

    public function testValidateIsANoOpForEmptyIds(): void
    {
        $id = $this->insertFixtureComment([
            'validated' => false,
        ]);

        $before = $this->fetchValidated($id);
        $this->repo->validate([]);

        self::assertSame($before, $this->fetchValidated($id));
    }

    public function testCountRecentCommentsCountsWithinTheFloodWindow(): void
    {
        // author_id has an FK onto users, so a real fixture user id
        // is needed -- 4 (power_user), not reused by insertFixtureComment()
        // (which only ever uses 1 or 3), so no other test's disposable rows
        // inflate this count. Fixture comments 3 and 4 (also author_id 4)
        // are seeded at the same uniform timestamp every fixture row uses
        // (2026-08-01 00:00:00, matching PIWIGO_TEST_NOW) -- pushed safely
        // into the past here, scoped to this test only, so only the fresh
        // insert below counts as "recent".
        $this->conn->executeStatement(
            "UPDATE comments SET date = '2026-01-01 00:00:00' WHERE author_id = 4"
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

    public function testCountRecentCommentsRestrictsToTheAnonymousIdPrefix(): void
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

    public function testUsernameExistsMatchesAnExistingUsername(): void
    {
        self::assertTrue($this->repo->usernameExists('fixture_admin'));
        // users.username is a case-sensitive (utf8mb4_bin) column, unlike
        // most other varchar columns in this schema -- a case-differing
        // lookup does not match.
        self::assertFalse($this->repo->usernameExists('FIXTURE_ADMIN'));
        self::assertFalse($this->repo->usernameExists('does-not-exist'));
    }

    public function testCountForImageCountsOnlyValidatedByDefault(): void
    {
        // fixture: image 2 has comment 2 (validated); image 4 has only
        // comment 5 (validated='false', pending moderation). Neither image
        // is ever touched by insertFixtureComment() (hardcoded to
        // image_id 1), so both stay deterministic across this class's
        // full test run regardless of test order.
        self::assertSame(1, $this->repo->countForImage(ImageId::from(2), true));
        self::assertSame(1, $this->repo->countForImage(ImageId::from(2), false));
        self::assertSame(0, $this->repo->countForImage(ImageId::from(4), true));
        self::assertSame(1, $this->repo->countForImage(ImageId::from(4), false));
    }

    public function testCountForImageReturnsZeroForAnImageWithNoComments(): void
    {
        self::assertSame(0, $this->repo->countForImage(ImageId::from(999999), false));
    }

    public function testFindSummariesForImageReturnsTheMatchingSummary(): void
    {
        // fixture: image 2 has comment 2 (validated), untouched by
        // insertFixtureComment() (hardcoded to image_id 1) -- deterministic
        // across this class's full test run regardless of test order.
        $summaries = $this->repo->findSummariesForImage(ImageId::from(2), false, 10, 0);

        self::assertCount(1, $summaries);
        self::assertSame(2, $summaries[0]->id->value);
        self::assertSame('regular_user', $summaries[0]->author);
        self::assertSame('Another perspective on this photo.', $summaries[0]->content);
    }

    public function testFindSummariesForImageExcludesUnvalidatedWhenRestricted(): void
    {
        // fixture: image 4 has only comment 5, unvalidated.
        self::assertSame([], $this->repo->findSummariesForImage(ImageId::from(4), true, 10, 0));
        self::assertCount(1, $this->repo->findSummariesForImage(ImageId::from(4), false, 10, 0));
    }

    public function testFindSummariesForImageRespectsTheLimit(): void
    {
        $this->repo->insert([
            'author' => 'fsfi_a',
            'authorId' => 1,
            'anonymousId' => '10.30.0.30',
            'content' => 'fsfi content A',
            'validated' => true,
            'imageId' => 5,
            'websiteUrl' => null,
            'email' => null,
        ]);
        $this->repo->insert([
            'author' => 'fsfi_b',
            'authorId' => 1,
            'anonymousId' => '10.30.0.31',
            'content' => 'fsfi content B',
            'validated' => true,
            'imageId' => 5,
            'websiteUrl' => null,
            'email' => null,
        ]);

        self::assertCount(2, $this->repo->findSummariesForImage(ImageId::from(5), false, 10, 0));
        self::assertCount(1, $this->repo->findSummariesForImage(ImageId::from(5), false, 1, 0));
    }

    /**
     * Comments sharing one timestamp must still page as a stable partition:
     * every row exactly once across successive offsets, none repeated, none
     * dropped.
     *
     * `comments.date` is a datetime, so a bulk import or two posts in the
     * same second collide readily. Ordering by `date` alone is not a total
     * order, and the engine's choice among equal keys is *unspecified* --
     * so before the `c.id` tiebreaker this assertion could pass or fail by
     * luck. It pins the contract rather than reproducing a fixed failure.
     */
    public function testFindSummariesForImagePagesStablyWhenDatesCollide(): void
    {
        $commentIds = [];
        foreach (['pc_a', 'pc_b', 'pc_c'] as $author) {
            $commentIds[] = $this->repo->insert([
                'author' => $author,
                'authorId' => 1,
                'anonymousId' => '10.40.0.40',
                'content' => 'collision ' . $author,
                'validated' => true,
                'imageId' => 3,
                'websiteUrl' => null,
                'email' => null,
            ]);
        }

        try {
            $ids = array_map(static fn (CommentId $id): int => $id->value, $commentIds);

            // Force an exact timestamp collision -- insert() stamps "now",
            // which may or may not land in the same second.
            $this->conn->executeStatement(
                'UPDATE comments SET date = :date WHERE id IN (:ids)',
                [
                    'date' => '2020-01-01 00:00:00',
                    'ids' => $ids,
                ],
                [
                    'ids' => ArrayParameterType::INTEGER,
                ],
            );

            $paged = [];
            for ($offset = 0; $offset < 3; $offset++) {
                $page = $this->repo->findSummariesForImage(ImageId::from(3), false, 1, $offset);
                self::assertCount(1, $page, "offset {$offset} returned no row");
                $paged[] = $page[0]->id->value;
            }

            sort($ids);
            $sortedPaged = $paged;
            sort($sortedPaged);

            self::assertSame($ids, $sortedPaged, 'paging repeated or dropped a row');
            self::assertSame($ids, $paged, 'equal dates must fall back to ascending id');
        } finally {
            // This suite shares one database across tests (per-test
            // transaction isolation is Unit-only), and sibling tests assert
            // exact per-image comment counts -- so these rows must not
            // outlive this test.
            $this->repo->delete($commentIds, null);
        }
    }

    public function testFindForImageReturnsMatchingRowsJoinedWithUserEmail(): void
    {
        // fixture: image 2 has comment 2, authored by regular_user, whose
        // own mail_address is NULL -- this exercises the LEFT JOIN's own
        // "known user, no email on file" case, not "unknown/anonymous
        // author" (author_id IS NULL), which findForImage() also allows.
        $rows = $this->repo->findForImage(ImageId::from(2), true, 'ASC', 10, 0);

        self::assertCount(1, $rows);
        self::assertEquals(CommentId::from(2), $rows[0]->id);
        self::assertSame(3, $rows[0]->authorId);
        self::assertNull($rows[0]->userEmail);
    }

    public function testFindForImageExcludesUnvalidatedWhenRestricted(): void
    {
        // fixture: image 4 has only comment 5, which is unvalidated.
        self::assertSame([], $this->repo->findForImage(ImageId::from(4), true, 'ASC', 10, 0));
    }

    public function testFindForImageIncludesUnvalidatedWhenNotRestricted(): void
    {
        $rows = $this->repo->findForImage(ImageId::from(4), false, 'ASC', 10, 0);

        self::assertCount(1, $rows);
        self::assertEquals(CommentId::from(5), $rows[0]->id);
    }

    public function testFindForImageRespectsLimitAndOffset(): void
    {
        // fixture: image 3 has comment 3; two more validated comments
        // added here so pagination has 3 total rows to split across pages.
        // All 3 may share the same PIWIGO_TEST_NOW-derived date, so this
        // only asserts page sizes and total distinct coverage, not a
        // specific row per page (no tiebreaker column to rely on).
        $this->repo->insert([
            'author' => 'pager_a',
            'authorId' => 1,
            'anonymousId' => '10.20.0.1',
            'content' => 'Page test A',
            'validated' => true,
            'imageId' => 3,
            'websiteUrl' => null,
            'email' => null,
        ]);
        $this->repo->insert([
            'author' => 'pager_b',
            'authorId' => 1,
            'anonymousId' => '10.20.0.2',
            'content' => 'Page test B',
            'validated' => true,
            'imageId' => 3,
            'websiteUrl' => null,
            'email' => null,
        ]);

        self::assertSame(3, $this->repo->countForImage(ImageId::from(3), true));

        $firstPage = $this->repo->findForImage(ImageId::from(3), true, 'ASC', 2, 0);
        $secondPage = $this->repo->findForImage(ImageId::from(3), true, 'ASC', 2, 2);

        self::assertCount(2, $firstPage);
        self::assertCount(1, $secondPage);
        $allIds = array_map(
            static fn (CommentId $id): int => $id->value,
            array_merge(array_column($firstPage, 'id'), array_column($secondPage, 'id'))
        );
        self::assertCount(3, array_unique($allIds));
    }

    public function testFindForImageReturnsEmptyForAnImageWithNoComments(): void
    {
        self::assertSame([], $this->repo->findForImage(ImageId::from(999999), false, 'ASC', 10, 0));
    }

    public function testCountValidatedByImageIdsShortCircuitsOnAnEmptyList(): void
    {
        self::assertSame([], $this->repo->countValidatedByImageIds([]));
    }

    /**
     * Asserts the *delta* rather than an absolute count -- other tests in
     * this class also insert disposable image_id=1 comments and the
     * fixture is loaded once per class, not reset per test, so the
     * pre-existing count isn't a fixed number. Adds one validated and one
     * unvalidated comment and confirms exactly the validated one is
     * reflected, keyed by the string image id, with a requested-but-empty
     * image id absent entirely (not present with a zero count).
     */
    public function testCountValidatedByImageIdsKeysTheResultByImageId(): void
    {
        $beforeCounts = $this->repo->countValidatedByImageIds([1]);
        $before = $beforeCounts === [] ? 0 : array_first($beforeCounts);

        $this->insertFixtureComment([
            'validated' => true,
        ]);
        $this->insertFixtureComment([
            'validated' => false,
        ]);

        $counts = $this->repo->countValidatedByImageIds([1, 999999]);

        // Only image 1 has any validated comments -- 999999 is absent
        // entirely (not present with a zero count), so a single-entry
        // result is exactly image 1's own count.
        self::assertCount(1, $counts);
        self::assertSame($before + 1, array_first($counts));
    }

    /**
     * countAvailableWithConditions()'s own $whereClauses are SqlCondition
     * fragments, not raw trusted-SQL strings (see CommentRepository.php's
     * own docblock).
     */
    public function testCountAvailableWithConditionsCountsMatchingRowsAcrossTheJoin(): void
    {
        $id = $this->insertFixtureComment([
            'validated' => true,
        ]);

        $matchingCount = $this->repo->countAvailableWithConditions([
            SqlCondition::fromRawSql('com.id = :id', [
                'id' => $id->value,
            ], [
                'id' => ParameterType::INTEGER,
            ]),
        ]);
        $nonMatchingCount = $this->repo->countAvailableWithConditions([
            SqlCondition::fromRawSql('com.id = :id', [
                'id' => 999999,
            ], [
                'id' => ParameterType::INTEGER,
            ]),
        ]);

        self::assertSame(1, $matchingCount);
        self::assertSame(0, $nonMatchingCount);
    }

    public function testCountAvailableWithConditionsCombinesMultipleFragmentsWithAnd(): void
    {
        $id = $this->insertFixtureComment([
            'validated' => true,
        ]);

        $count = $this->repo->countAvailableWithConditions([
            SqlCondition::fromRawSql('com.id = :id', [
                'id' => $id->value,
            ], [
                'id' => ParameterType::INTEGER,
            ]),
            SqlCondition::fromRawSql('com.validated = false'),
        ]);

        self::assertSame(0, $count);
    }

    public function testFindAllWithConditionsPaginatesAndReportsTheRealTotalViaFoundRows(): void
    {
        $first = $this->repo->insert([
            'author' => 'fawc_a',
            'authorId' => 1,
            'anonymousId' => '10.30.0.1',
            'content' => 'fawc content A',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);
        $second = $this->repo->insert([
            'author' => 'fawc_b',
            'authorId' => 1,
            'anonymousId' => '10.30.0.2',
            'content' => 'fawc content B',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $condition = SqlCondition::fromRawSql('com.id IN (:ids)', [
            'ids' => [$first->value, $second->value],
        ], [
            'ids' => ArrayParameterType::INTEGER,
        ]);

        $firstPage = $this->repo->findAllWithConditions([$condition], CommentSortField::ImageId, 'ASC', 1, 0);
        $secondPage = $this->repo->findAllWithConditions([$condition], CommentSortField::ImageId, 'ASC', 1, 1);
        $allAtOnce = $this->repo->findAllWithConditions([$condition], CommentSortField::ImageId, 'ASC', 'all', 0);

        self::assertCount(1, $firstPage->rows);
        self::assertSame(2, $firstPage->total);
        self::assertCount(1, $secondPage->rows);
        self::assertSame(2, $secondPage->total);
        self::assertNotSame($firstPage->rows[0]->commentId, $secondPage->rows[0]->commentId);
        self::assertCount(2, $allAtOnce->rows);
    }

    /**
     * Controller\CommentsController's own author-search fragment binds the
     * request value as a SqlCondition parameter rather than splicing it
     * into the query text. Builds the exact SqlCondition shape the
     * controller builds, with a classic `' OR '1'='1` payload as the bound
     * value -- confirms it's treated as an inert literal (matches nothing,
     * no SQL error) rather than widening the WHERE clause to match
     * everything.
     */
    public function testFindAllWithConditionsTreatsAnInjectionPayloadAsAnInertLiteralValue(): void
    {
        $this->repo->insert([
            'author' => 'real_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.3',
            'content' => 'injection guard content',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $payload = "nonexistent' OR '1'='1";
        $maliciousCondition = SqlCondition::fromRawSql(
            '(u.username = :authorA OR com.author = :authorB)',
            [
                'authorA' => $payload,
                'authorB' => $payload,
            ],
            [
                'authorA' => ParameterType::STRING,
                'authorB' => ParameterType::STRING,
            ],
        );

        $result = $this->repo->findAllWithConditions([$maliciousCondition], CommentSortField::ImageId, 'ASC', 'all', 0);

        // If the payload broke out of its string literal, `OR '1'='1'`
        // would make the WHERE clause match every comment in the fixture,
        // not zero.
        self::assertSame([], $result->rows);
        self::assertSame(0, $result->total);
    }

    public function testFindSummaryCountsReportsValidatedAndPendingSplit(): void
    {
        // A unique `search` marker scopes findSummaryCounts() to exactly
        // these 2 rows (search resets every other filter -- see
        // CommentRepository::buildApiConditions()'s own docblock).
        $marker = 'fsc-marker-' . uniqid();
        $this->repo->insert([
            'author' => 'fsc_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.6',
            'content' => $marker . ' validated',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);
        $this->repo->insert([
            'author' => 'fsc_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.7',
            'content' => $marker . ' pending',
            'validated' => false,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $summary = $this->repo->findSummaryCounts(new CommentApiCriteria(search: $marker));

        self::assertNotNull($summary);
        self::assertSame(2, $summary->allComments);
        self::assertSame(1, $summary->validated);
        self::assertSame(1, $summary->pending);
    }

    public function testFindSummaryCountsIgnoresStatusAndAlwaysReportsTheFullSplit(): void
    {
        // Deliberate: findSummaryCounts() computes all/validated/pending
        // itself via SUM(), so $criteria->status must NOT narrow the
        // underlying row set the way it does for the 3 sibling methods
        // below -- otherwise a 'validated'-status criteria would report
        // pending=0 unconditionally, defeating the summary's own purpose.
        $marker = 'fscs-marker-' . uniqid();
        $this->repo->insert([
            'author' => 'fscs_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.20',
            'content' => $marker . ' validated',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);
        $this->repo->insert([
            'author' => 'fscs_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.21',
            'content' => $marker . ' pending',
            'validated' => false,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $summary = $this->repo->findSummaryCounts(new CommentApiCriteria(search: $marker, status: 'validated'));

        self::assertNotNull($summary);
        self::assertSame(2, $summary->allComments);
        self::assertSame(1, $summary->validated);
        self::assertSame(1, $summary->pending);
    }

    public function testFindListReturnsJoinedRowsWithUsernameAndStatus(): void
    {
        $marker = 'flfaw-marker-' . uniqid();
        $id = $this->repo->insert([
            'author' => 'flfaw_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.8',
            'content' => $marker,
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $rows = $this->repo->findList(new CommentApiCriteria(search: $marker), 0, 10);

        self::assertCount(1, $rows);
        self::assertSame($id->value, is_numeric($rows[0]['id']) ? (int) $rows[0]['id'] : null);
        self::assertArrayHasKey('username', $rows[0]);
        self::assertArrayHasKey('status', $rows[0]);
    }

    public function testFindListAppliesTheStatusFilter(): void
    {
        $marker = 'flfaws-marker-' . uniqid();
        $this->repo->insert([
            'author' => 'flfaws_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.22',
            'content' => $marker . ' validated',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);
        $pendingId = $this->repo->insert([
            'author' => 'flfaws_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.23',
            'content' => $marker . ' pending',
            'validated' => false,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $rows = $this->repo->findList(new CommentApiCriteria(search: $marker, status: 'pending'), 0, 10);

        self::assertCount(1, $rows);
        self::assertSame($pendingId->value, is_numeric($rows[0]['id']) ? (int) $rows[0]['id'] : null);
    }

    public function testFindDateRangeReturnsMinAndMaxMatchingDates(): void
    {
        $marker = 'fdr-marker-' . uniqid();
        $this->repo->insert([
            'author' => 'fdr_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.9',
            'content' => $marker,
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $range = $this->repo->findDateRange(new CommentApiCriteria(search: $marker));

        self::assertNotNull($range);
        self::assertNotNull($range->startedAt);
        self::assertSame($range->startedAt, $range->endedAt);
    }

    public function testFindAuthorCountsGroupsByAuthorId(): void
    {
        $marker = 'fac-marker-' . uniqid();
        $this->repo->insert([
            'author' => 'fac_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.4',
            'content' => $marker . ' A',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);
        $this->repo->insert([
            'author' => 'fac_author',
            'authorId' => 1,
            'anonymousId' => '10.30.0.5',
            'content' => $marker . ' B',
            'validated' => true,
            'imageId' => 1,
            'websiteUrl' => null,
            'email' => null,
        ]);

        $rows = $this->repo->findAuthorCounts(new CommentApiCriteria(search: $marker));

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['author_id']);
        self::assertSame(2, $rows[0]['nb_authors']);
    }

    public function testFindAuthorCountsIgnoresTheAuthorIdFilter(): void
    {
        // CommentRepository::findAuthorCounts()'s own documented behavior:
        // $criteria->authorId must NOT narrow the author breakdown down to
        // a single author (that would defeat the "how many comments per
        // author" point of this method).
        //
        // Isolated via imageId (fixture image 5 has zero fixture/other-test
        // comments), not `search` -- a non-empty search resets every
        // filter including authorId for every method (see
        // buildApiConditions()'s own docblock), which would make this
        // pass even if findAuthorCounts() DID honor authorId. imageId
        // doesn't trigger that reset, so authorId's own inclusion/exclusion
        // is what's actually under test here.
        $this->repo->insert([
            'author' => 'faci_author_one',
            'authorId' => 1,
            'anonymousId' => '10.30.0.24',
            'content' => 'faci content one',
            'validated' => true,
            'imageId' => 5,
            'websiteUrl' => null,
            'email' => null,
        ]);
        $this->repo->insert([
            'author' => 'faci_author_three',
            'authorId' => 3,
            'anonymousId' => '10.30.0.25',
            'content' => 'faci content three',
            'validated' => true,
            'imageId' => 5,
            'websiteUrl' => null,
            'email' => null,
        ]);

        // authorId: 1 would, if honored, exclude author_id=3's row --
        // findAuthorCounts() must still report both.
        $rows = $this->repo->findAuthorCounts(new CommentApiCriteria(authorId: UserId::from(1), imageId: ImageId::from(5)));

        $authorIds = array_map(
            static fn (array $row): ?int => $row['author_id'],
            $rows
        );
        sort($authorIds);
        self::assertSame([1, 3], $authorIds);
    }

    public function testCountAllAndCountUnvalidatedReflectAFreshlyInsertedPendingComment(): void
    {
        $before_all = $this->repo->countAll();
        $before_unvalidated = $this->repo->countUnvalidated();

        $this->insertFixtureComment([
            'validated' => false,
        ]);

        self::assertSame($before_all + 1, $this->repo->countAll());
        self::assertSame($before_unvalidated + 1, $this->repo->countUnvalidated());
    }

    /**
     * Inserts a fresh, disposable comment for destructive tests (delete/
     * update/validate) so they never depend on -- or on run order relative
     * to -- fixture rows shared with other tests in this class (the
     * fixture is loaded once per class, not reset per test).
     *
     * @param array{authorId?: int, validated?: bool} $overrides
     */
    private function insertFixtureComment(array $overrides = []): CommentId
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

    private function fetchContent(CommentId $commentId): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('content')
            ->from('comments')
            ->where('id = :id')
            ->setParameter('id', $commentId->value)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    private function fetchValidated(CommentId $commentId): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('validated')
            ->from('comments')
            ->where('id = :id')
            ->setParameter('id', $commentId->value)
            ->executeQuery()
            ->fetchOne();

        return is_bool($value) || is_numeric($value) ? (int) (bool) $value : null;
    }
}
