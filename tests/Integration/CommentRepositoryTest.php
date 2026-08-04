<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Comment\CommentApiCriteria;
use Piwigo\Comment\CommentRepository;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Permission\SqlCondition;

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

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Comment\CommentEntity::class);
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

        self::assertGreaterThan(0, $id->value);
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
        self::assertFalse($this->repo->findAuthorId(CommentId::from(999999)));
    }

    public function test_find_author_id_returns_the_numeric_author_id_as_string(): void
    {
        self::assertSame('1', $this->repo->findAuthorId(CommentId::from(1)));
    }

    public function test_validate_marks_the_given_comments_validated(): void
    {
        $id = $this->insertFixtureComment(['validated' => false]);

        $this->repo->validate([$id]);

        self::assertSame(1, $this->fetchValidated($id));
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
        self::assertTrue($this->repo->usernameExists('fixture_admin'));
        // users.username is utf8mb4_bin (case-sensitive) -- fixed in
        // Migrations\Version20260711150858, whose combined ALTER TABLE
        // (CONVERT TO CHARACTER SET + MODIFY ... COLLATE utf8mb4_bin in one
        // statement) turned out not to work: CONVERT TO CHARACTER SET
        // silently won, leaving this and 8 other columns case-insensitive
        // utf8mb4_unicode_ci. This assertion previously expected that bug's
        // behavior (a case-insensitive match) as if it were correct; not a
        // property of this query, but of the schema it queries.
        self::assertFalse($this->repo->usernameExists('FIXTURE_ADMIN'));
        self::assertFalse($this->repo->usernameExists('does-not-exist'));
    }

    public function test_count_for_image_counts_only_validated_by_default(): void
    {
        // fixture: image 2 has comment 2 (validated); image 4 has only
        // comment 5 (validated='false', pending moderation). Neither image
        // is ever touched by insertFixtureComment() (hardcoded to
        // image_id 1), so both stay deterministic across this class's
        // full test run regardless of test order.
        self::assertSame(1, $this->repo->countForImage(2, true));
        self::assertSame(1, $this->repo->countForImage(2, false));
        self::assertSame(0, $this->repo->countForImage(4, true));
        self::assertSame(1, $this->repo->countForImage(4, false));
    }

    public function test_count_for_image_returns_zero_for_an_image_with_no_comments(): void
    {
        self::assertSame(0, $this->repo->countForImage(999999, false));
    }

    public function test_find_summaries_for_image_returns_the_matching_summary(): void
    {
        // fixture: image 2 has comment 2 (validated), untouched by
        // insertFixtureComment() (hardcoded to image_id 1) -- deterministic
        // across this class's full test run regardless of test order.
        $summaries = $this->repo->findSummariesForImage(2, false, 10, 0);

        self::assertCount(1, $summaries);
        self::assertSame(2, $summaries[0]->id->value);
        self::assertSame('regular_user', $summaries[0]->author);
        self::assertSame('Another perspective on this photo.', $summaries[0]->content);
    }

    public function test_find_summaries_for_image_excludes_unvalidated_when_restricted(): void
    {
        // fixture: image 4 has only comment 5, unvalidated.
        self::assertSame([], $this->repo->findSummariesForImage(4, true, 10, 0));
        self::assertCount(1, $this->repo->findSummariesForImage(4, false, 10, 0));
    }

    public function test_find_summaries_for_image_respects_the_limit(): void
    {
        $this->repo->insert(['author' => 'fsfi_a', 'authorId' => 1, 'anonymousId' => '10.30.0.30', 'content' => 'fsfi content A', 'validated' => true, 'imageId' => 5, 'websiteUrl' => null, 'email' => null]);
        $this->repo->insert(['author' => 'fsfi_b', 'authorId' => 1, 'anonymousId' => '10.30.0.31', 'content' => 'fsfi content B', 'validated' => true, 'imageId' => 5, 'websiteUrl' => null, 'email' => null]);

        self::assertCount(2, $this->repo->findSummariesForImage(5, false, 10, 0));
        self::assertCount(1, $this->repo->findSummariesForImage(5, false, 1, 0));
    }

    public function test_find_for_image_returns_matching_rows_joined_with_user_email(): void
    {
        // fixture: image 2 has comment 2, authored by regular_user, whose
        // own mail_address is NULL -- this exercises the LEFT JOIN's own
        // "known user, no email on file" case, not "unknown/anonymous
        // author" (author_id IS NULL), which findForImage() also allows.
        $rows = $this->repo->findForImage(2, true, 'ASC', 10, 0);

        self::assertCount(1, $rows);
        self::assertEquals(CommentId::from(2), $rows[0]->id);
        self::assertSame(3, $rows[0]->authorId);
        self::assertNull($rows[0]->userEmail);
    }

    public function test_find_for_image_excludes_unvalidated_when_restricted(): void
    {
        // fixture: image 4 has only comment 5, which is unvalidated.
        self::assertSame([], $this->repo->findForImage(4, true, 'ASC', 10, 0));
    }

    public function test_find_for_image_includes_unvalidated_when_not_restricted(): void
    {
        $rows = $this->repo->findForImage(4, false, 'ASC', 10, 0);

        self::assertCount(1, $rows);
        self::assertEquals(CommentId::from(5), $rows[0]->id);
    }

    public function test_find_for_image_respects_limit_and_offset(): void
    {
        // fixture: image 3 has comment 3; two more validated comments
        // added here so pagination has 3 total rows to split across pages.
        // All 3 may share the same PIWIGO_TEST_NOW-derived date, so this
        // only asserts page sizes and total distinct coverage, not a
        // specific row per page (no tiebreaker column to rely on).
        $this->repo->insert(['author' => 'pager_a', 'authorId' => 1, 'anonymousId' => '10.20.0.1', 'content' => 'Page test A', 'validated' => true, 'imageId' => 3, 'websiteUrl' => null, 'email' => null]);
        $this->repo->insert(['author' => 'pager_b', 'authorId' => 1, 'anonymousId' => '10.20.0.2', 'content' => 'Page test B', 'validated' => true, 'imageId' => 3, 'websiteUrl' => null, 'email' => null]);

        self::assertSame(3, $this->repo->countForImage(3, true));

        $firstPage = $this->repo->findForImage(3, true, 'ASC', 2, 0);
        $secondPage = $this->repo->findForImage(3, true, 'ASC', 2, 2);

        self::assertCount(2, $firstPage);
        self::assertCount(1, $secondPage);
        $allIds = array_map(
            static fn (CommentId $id): int => $id->value,
            array_merge(array_column($firstPage, 'id'), array_column($secondPage, 'id'))
        );
        self::assertCount(3, array_unique($allIds));
    }

    public function test_find_for_image_returns_empty_for_an_image_with_no_comments(): void
    {
        self::assertSame([], $this->repo->findForImage(999999, false, 'ASC', 10, 0));
    }

    public function test_count_validated_by_image_ids_short_circuits_on_an_empty_list(): void
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
    public function test_count_validated_by_image_ids_keys_the_result_by_image_id(): void
    {
        $beforeCounts = $this->repo->countValidatedByImageIds([1]);
        $before = $beforeCounts === [] ? 0 : array_values($beforeCounts)[0];

        $this->insertFixtureComment(['validated' => true]);
        $this->insertFixtureComment(['validated' => false]);

        $counts = $this->repo->countValidatedByImageIds([1, 999999]);

        // Only image 1 has any validated comments -- 999999 is absent
        // entirely (not present with a zero count), so a single-entry
        // result is exactly image 1's own count.
        self::assertCount(1, $counts);
        self::assertSame($before + 1, array_values($counts)[0]);
    }

    /**
     * SQL-modernization audit: countAvailableWithConditions()'s own
     * $whereClauses moved from raw trusted-SQL strings to SqlCondition
     * fragments (see CommentRepository.php's own docblock) -- this and
     * the tests below are its first direct coverage; none of the tests
     * above exercise these 6 methods at all.
     */
    public function test_count_available_with_conditions_counts_matching_rows_across_the_join(): void
    {
        $id = $this->insertFixtureComment(['validated' => true]);

        $matchingCount = $this->repo->countAvailableWithConditions([
            new SqlCondition('com.id = :id', ['id' => $id->value], ['id' => ParameterType::INTEGER]),
        ]);
        $nonMatchingCount = $this->repo->countAvailableWithConditions([
            new SqlCondition('com.id = :id', ['id' => 999999], ['id' => ParameterType::INTEGER]),
        ]);

        self::assertSame(1, $matchingCount);
        self::assertSame(0, $nonMatchingCount);
    }

    public function test_count_available_with_conditions_combines_multiple_fragments_with_and(): void
    {
        $id = $this->insertFixtureComment(['validated' => true]);

        $count = $this->repo->countAvailableWithConditions([
            new SqlCondition('com.id = :id', ['id' => $id->value], ['id' => ParameterType::INTEGER]),
            new SqlCondition('com.validated = false'),
        ]);

        self::assertSame(0, $count);
    }

    public function test_find_all_with_conditions_paginates_and_reports_the_real_total_via_found_rows(): void
    {
        $first = $this->repo->insert(['author' => 'fawc_a', 'authorId' => 1, 'anonymousId' => '10.30.0.1', 'content' => 'fawc content A', 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);
        $second = $this->repo->insert(['author' => 'fawc_b', 'authorId' => 1, 'anonymousId' => '10.30.0.2', 'content' => 'fawc content B', 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);

        $condition = new SqlCondition('com.id IN (:ids)', ['ids' => [$first->value, $second->value]], ['ids' => ArrayParameterType::INTEGER]);

        $firstPage = $this->repo->findAllWithConditions([$condition], 'com.id', 'ASC', 1, 0);
        $secondPage = $this->repo->findAllWithConditions([$condition], 'com.id', 'ASC', 1, 1);
        $allAtOnce = $this->repo->findAllWithConditions([$condition], 'com.id', 'ASC', 'all', 0);

        self::assertCount(1, $firstPage->rows);
        self::assertSame(2, $firstPage->total);
        self::assertCount(1, $secondPage->rows);
        self::assertSame(2, $secondPage->total);
        self::assertNotSame($firstPage->rows[0]['comment_id'], $secondPage->rows[0]['comment_id']);
        self::assertCount(2, $allAtOnce->rows);
    }

    /**
     * Regression: Controller\CommentsController's own author-search
     * fragment used to splice the raw request value straight into the
     * query (`'... = \'' . $author_search . '\' ...'`), a live SQL
     * injection. Builds the exact SqlCondition shape that controller now
     * builds, with a classic `' OR '1'='1` payload as the bound value --
     * confirms it's treated as an inert literal (matches nothing, no SQL
     * error) rather than widening the WHERE clause to match everything.
     */
    public function test_find_all_with_conditions_treats_an_injection_payload_as_an_inert_literal_value(): void
    {
        $this->repo->insert(['author' => 'real_author', 'authorId' => 1, 'anonymousId' => '10.30.0.3', 'content' => 'injection guard content', 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);

        $payload = "nonexistent' OR '1'='1";
        $maliciousCondition = new SqlCondition(
            '(u.username = :authorA OR author = :authorB)',
            ['authorA' => $payload, 'authorB' => $payload],
            ['authorA' => ParameterType::STRING, 'authorB' => ParameterType::STRING],
        );

        $result = $this->repo->findAllWithConditions([$maliciousCondition], 'com.id', 'ASC', 'all', 0);

        // If the payload had broken out of its string literal (the old
        // raw-splice behavior), `OR '1'='1'` would make the WHERE clause
        // match every comment in the fixture, not zero.
        self::assertSame([], $result->rows);
        self::assertSame(0, $result->total);
    }

    public function test_find_summary_counts_reports_validated_and_pending_split(): void
    {
        // A unique `search` marker scopes findSummaryCounts() to exactly
        // these 2 rows (search resets every other filter -- see
        // CommentRepository::buildApiConditions()'s own docblock), same
        // isolation intent the old `id IN (:ids)` condition served before
        // this method took a CommentApiCriteria instead of an arbitrary
        // SqlCondition list.
        $marker = 'fsc-marker-' . uniqid();
        $this->repo->insert(['author' => 'fsc_author', 'authorId' => 1, 'anonymousId' => '10.30.0.6', 'content' => $marker . ' validated', 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);
        $this->repo->insert(['author' => 'fsc_author', 'authorId' => 1, 'anonymousId' => '10.30.0.7', 'content' => $marker . ' pending', 'validated' => false, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);

        $summary = $this->repo->findSummaryCounts(new CommentApiCriteria(search: $marker));

        self::assertNotNull($summary);
        self::assertSame(2, is_numeric($summary['all_comments']) ? (int) $summary['all_comments'] : null);
        self::assertSame(1, is_numeric($summary['validated']) ? (int) $summary['validated'] : null);
        self::assertSame(1, is_numeric($summary['pending']) ? (int) $summary['pending'] : null);
    }

    public function test_find_summary_counts_ignores_status_and_always_reports_the_full_split(): void
    {
        // Deliberate: findSummaryCounts() computes all/validated/pending
        // itself via SUM(), so $criteria->status must NOT narrow the
        // underlying row set the way it does for the 3 sibling methods
        // below -- otherwise a 'validated'-status criteria would report
        // pending=0 unconditionally, defeating the summary's own purpose.
        $marker = 'fscs-marker-' . uniqid();
        $this->repo->insert(['author' => 'fscs_author', 'authorId' => 1, 'anonymousId' => '10.30.0.20', 'content' => $marker . ' validated', 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);
        $this->repo->insert(['author' => 'fscs_author', 'authorId' => 1, 'anonymousId' => '10.30.0.21', 'content' => $marker . ' pending', 'validated' => false, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);

        $summary = $this->repo->findSummaryCounts(new CommentApiCriteria(search: $marker, status: 'validated'));

        self::assertNotNull($summary);
        self::assertSame(2, is_numeric($summary['all_comments']) ? (int) $summary['all_comments'] : null);
        self::assertSame(1, is_numeric($summary['validated']) ? (int) $summary['validated'] : null);
        self::assertSame(1, is_numeric($summary['pending']) ? (int) $summary['pending'] : null);
    }

    public function test_find_list_for_admin_ws_returns_joined_rows_with_username_and_status(): void
    {
        $marker = 'flfaw-marker-' . uniqid();
        $id = $this->repo->insert(['author' => 'flfaw_author', 'authorId' => 1, 'anonymousId' => '10.30.0.8', 'content' => $marker, 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);

        $rows = $this->repo->findListForAdminWs(new CommentApiCriteria(search: $marker), 0, 10);

        self::assertCount(1, $rows);
        self::assertSame($id->value, is_numeric($rows[0]['id']) ? (int) $rows[0]['id'] : null);
        self::assertArrayHasKey('username', $rows[0]);
        self::assertArrayHasKey('status', $rows[0]);
    }

    public function test_find_list_for_admin_ws_applies_the_status_filter(): void
    {
        $marker = 'flfaws-marker-' . uniqid();
        $this->repo->insert(['author' => 'flfaws_author', 'authorId' => 1, 'anonymousId' => '10.30.0.22', 'content' => $marker . ' validated', 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);
        $pendingId = $this->repo->insert(['author' => 'flfaws_author', 'authorId' => 1, 'anonymousId' => '10.30.0.23', 'content' => $marker . ' pending', 'validated' => false, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);

        $rows = $this->repo->findListForAdminWs(new CommentApiCriteria(search: $marker, status: 'pending'), 0, 10);

        self::assertCount(1, $rows);
        self::assertSame($pendingId->value, is_numeric($rows[0]['id']) ? (int) $rows[0]['id'] : null);
    }

    public function test_find_date_range_returns_min_and_max_matching_dates(): void
    {
        $marker = 'fdr-marker-' . uniqid();
        $this->repo->insert(['author' => 'fdr_author', 'authorId' => 1, 'anonymousId' => '10.30.0.9', 'content' => $marker, 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);

        $range = $this->repo->findDateRange(new CommentApiCriteria(search: $marker));

        self::assertNotNull($range);
        self::assertNotNull($range['started_at']);
        self::assertSame($range['started_at'], $range['ended_at']);
    }

    public function test_find_author_counts_groups_by_author_id(): void
    {
        $marker = 'fac-marker-' . uniqid();
        $this->repo->insert(['author' => 'fac_author', 'authorId' => 1, 'anonymousId' => '10.30.0.4', 'content' => $marker . ' A', 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);
        $this->repo->insert(['author' => 'fac_author', 'authorId' => 1, 'anonymousId' => '10.30.0.5', 'content' => $marker . ' B', 'validated' => true, 'imageId' => 1, 'websiteUrl' => null, 'email' => null]);

        $rows = $this->repo->findAuthorCounts(new CommentApiCriteria(search: $marker));

        self::assertCount(1, $rows);
        self::assertSame(1, is_numeric($rows[0]['author_id']) ? (int) $rows[0]['author_id'] : null);
        self::assertSame(2, is_numeric($rows[0]['nb_authors']) ? (int) $rows[0]['nb_authors'] : null);
    }

    public function test_find_author_counts_ignores_the_author_id_filter(): void
    {
        // Real regression coverage for CommentRepository::findAuthorCounts()'s
        // own documented behavior: $criteria->authorId must NOT narrow the
        // author breakdown down to a single author (that would defeat the
        // "how many comments per author" point of this method) -- mirrors
        // the original Ws\PwgComments::getList()'s own
        // unset($where_clauses['author_id']) intent, now as real code.
        //
        // Isolated via imageId (fixture image 5 has zero fixture/other-test
        // comments), not `search` -- a non-empty search resets every
        // filter including authorId for every method (see
        // buildApiConditions()'s own docblock), which would make this
        // pass even if findAuthorCounts() DID honor authorId. imageId
        // doesn't trigger that reset, so authorId's own inclusion/exclusion
        // is what's actually under test here.
        $this->repo->insert(['author' => 'faci_author_one', 'authorId' => 1, 'anonymousId' => '10.30.0.24', 'content' => 'faci content one', 'validated' => true, 'imageId' => 5, 'websiteUrl' => null, 'email' => null]);
        $this->repo->insert(['author' => 'faci_author_three', 'authorId' => 3, 'anonymousId' => '10.30.0.25', 'content' => 'faci content three', 'validated' => true, 'imageId' => 5, 'websiteUrl' => null, 'email' => null]);

        // authorId: 1 would, if honored, exclude author_id=3's row --
        // findAuthorCounts() must still report both.
        $rows = $this->repo->findAuthorCounts(new CommentApiCriteria(authorId: 1, imageId: 5));

        $authorIds = array_map(
            static fn (array $row): ?int => is_numeric($row['author_id']) ? (int) $row['author_id'] : null,
            $rows
        );
        sort($authorIds);
        self::assertSame([1, 3], $authorIds);
    }

    public function test_count_all_and_count_unvalidated_reflect_a_freshly_inserted_pending_comment(): void
    {
        $before_all = $this->repo->countAll();
        $before_unvalidated = $this->repo->countUnvalidated();

        $this->insertFixtureComment(['validated' => false]);

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
            ->from(Tables::comments())
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
            ->from(Tables::comments())
            ->where('id = :id')
            ->setParameter('id', $commentId->value)
            ->executeQuery()
            ->fetchOne();

        return is_bool($value) || is_numeric($value) ? (int) (bool) $value : null;
    }
}
