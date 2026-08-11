<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Cache\CachePools;
use Piwigo\Db\DbConnection;
use RuntimeException;

final class WsHistoryTest extends ContractTestCase
{
    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM history');
        $this->conn->executeStatement("DELETE FROM config WHERE param IN ('history_admin', 'history_guest')");
        CachePools::config()->clear();
        parent::tearDown();
    }

    /**
     * Enables history logging for the admin session (fixture default: disabled).
     */
    private function enableHistoryForAdmin(): void
    {
        $this->upsertConfig('history_admin', 'true');
        CachePools::config()->clear();
    }

    /**
     * Enables history logging for guest (unauthenticated) visitors.
     */
    private function enableHistoryForGuest(): void
    {
        $this->upsertConfig('history_guest', 'true');
        CachePools::config()->clear();
    }

    public function testActivityGetListResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.activity.getList', $response);
    }

    public function testActivityGetListContainsResultLines(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList');

        $result = $response['result'];
        if (! is_array($result)) {
            self::fail('pwg.activity.getList result is not an array');
        }

        self::assertArrayHasKey('result_lines', $result);
        self::assertIsArray($result['result_lines']);
    }

    public function testActivityGetListForbiddenForGuest(): void
    {
        $response = $this->ws('pwg.activity.getList');

        self::assertSame('fail', $response['stat']);
    }

    public function testActivityGetListInvalidDateMinReturnsError(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList', [
            'date_min' => 'not-a-date',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid date_min', $response['message']);
    }

    public function testActivityGetListInvalidDateMaxReturnsError(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList', [
            'date_max' => 'not-a-date',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid date_max', $response['message']);
    }

    public function testActivityGetListFiltersByActionAndObject(): void
    {
        // Generate a real, fresh 'photo'/'edit' activity row (getActivityList's
        // WHERE always excludes object='system', so a genuine non-system row
        // is needed to exercise the object/action filter branches for real).
        $this->wsAdmin('pwg.images.setPrivacyLevel', [
            'image_id' => [1],
            'level' => 0,
        ]);

        $response = $this->wsAdmin('pwg.activity.getList', [
            'object' => 'photo',
            'action' => 'edit',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['result_lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        foreach ($lines as $line) {
            self::assertIsArray($line);
            self::assertSame('photo', $line['object']);
            self::assertSame('edit', $line['action']);
        }
    }

    public function testActivityGetListFiltersByDateRange(): void
    {
        $this->wsAdmin('pwg.images.setPrivacyLevel', [
            'image_id' => [1],
            'level' => 0,
        ]);
        // ActivityService::record() timestamps via Env::now(), which is
        // frozen to PIWIGO_TEST_NOW (.env.test) in test mode -- the real
        // wall-clock date() would exclude this activity in test mode.
        $frozenToday = substr((string) getenv('PIWIGO_TEST_NOW'), 0, 10);
        self::assertNotSame('', $frozenToday);

        $response = $this->wsAdmin('pwg.activity.getList', [
            'date_min' => $frozenToday,
            'date_max' => $frozenToday,
            'object' => 'photo',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['result_lines']);
        self::assertNotEmpty($result['result_lines']);
    }

    public function testActivityGetListFiltersByObjectId(): void
    {
        $this->wsAdmin('pwg.images.setPrivacyLevel', [
            'image_id' => [1],
            'level' => 0,
        ]);
        $this->wsAdmin('pwg.images.setPrivacyLevel', [
            'image_id' => [2],
            'level' => 0,
        ]);

        $response = $this->wsAdmin('pwg.activity.getList', [
            'object' => 'photo',
            'id' => 1,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['result_lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        foreach ($lines as $line) {
            self::assertIsArray($line);
            self::assertIsArray($line['object_id']);
            foreach ($line['object_id'] as $objectId) {
                self::assertSame('1', $objectId);
            }
        }
    }

    /**
     * getActivityList()'s `if (isset($param['uid'])) { $where .= 'AND
     * performed_by = ' . $param['uid']; }` -- distinct from the
     * object_id ($param['id']) filter tested above: `uid` filters on
     * *who performed* the action, not which object it targeted.
     */
    public function testActivityGetListFiltersByPerformerUid(): void
    {
        $adminId = $this->conn->fetchOne("SELECT id FROM users WHERE username = 'fixture_admin'");
        self::assertIsNumeric($adminId);

        // Generates a real, fresh 'photo'/'edit' row performed by
        // fixture_admin (wsAdmin() always logs in as fixture_admin first).
        $this->wsAdmin('pwg.images.setPrivacyLevel', [
            'image_id' => [1],
            'level' => 0,
        ]);

        $response = $this->wsAdmin('pwg.activity.getList', [
            'object' => 'photo',
            'action' => 'edit',
            'uid' => $adminId,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['result_lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        foreach ($lines as $line) {
            self::assertIsArray($line);
            self::assertSame((string) $adminId, $line['user_id']);
        }
    }

    public function testActivityGetListExcludesLoginsWhenConnectionsDisplayIsNone(): void
    {
        // Config values are always json_decode()'d on read
        // (ConfigService::hydrate()) -- a bare 'none' isn't valid JSON, so
        // json_decode() silently returns null and the value never
        // overrides the 'all' default; it must be stored quoted, same as
        // every other string config value this suite writes directly.
        $this->upsertConfig('activity_display_connections', '"none"');
        CachePools::config()->clear();

        try {
            // wsAdmin() itself performs a real pwg.session.login, which
            // AuthService::login() records as a 'user'/'login' activity row
            // -- the exact row this config value is meant to hide.
            $response = $this->wsAdmin('pwg.activity.getList', [
                'object' => 'user',
                'action' => 'login',
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertSame([], $result['result_lines']);
        } finally {
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'activity_display_connections'");
            CachePools::config()->clear();
        }
    }

    /**
     * The 'admins_only' setting is a distinct branch from 'none' above --
     * it keeps a login/logout row when its object_id (the user who
     * logged in/out) is an admin, and excludes it otherwise, via a
     * `NOT (action IN ('login','logout') AND object_id NOT IN (<admin
     * ids>))` filter (UserRepository::findAdminIds()).
     */
    public function testActivityGetListAdminsOnlyKeepsAdminLoginsButExcludesNonAdminLogins(): void
    {
        $this->upsertConfig('activity_display_connections', '"admins_only"');
        CachePools::config()->clear();

        try {
            $adminId = $this->conn->fetchOne("SELECT id FROM users WHERE username = 'fixture_admin'");
            self::assertIsNumeric($adminId);
            $regularUserId = $this->conn->fetchOne("SELECT id FROM users WHERE username = 'regular_user'");
            self::assertIsNumeric($regularUserId);

            // fixture_admin (webmaster status) logs in first -- its own
            // 'user'/'login' row's object_id is an admin id, so admins_only
            // must keep it.
            $this->loginAsAdmin();

            // regular_user (status 'normal', not an admin) logs in on the
            // same cookie jar -- its 'user'/'login' row's object_id is not
            // an admin id, so admins_only must exclude it.
            $regularLogin = $this->callWs('pwg.session.login', [
                'username' => 'regular_user',
                'password' => 'regular_user_pass',
            ]);
            self::assertSame('ok', $regularLogin['stat']);

            // pwg.activity.getList is admin_only -- log back in as admin on
            // the same cookie jar to read the results.
            $this->loginAsAdmin();
            $response = $this->callWs('pwg.activity.getList', [
                'object' => 'user',
                'action' => 'login',
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $lines = $result['result_lines'];
            self::assertIsArray($lines);

            $objectIds = [];
            foreach ($lines as $line) {
                self::assertIsArray($line);
                self::assertIsArray($line['object_id']);
                foreach ($line['object_id'] as $objectId) {
                    $objectIds[] = $objectId;
                }
            }

            self::assertContains((string) $adminId, $objectIds, 'admins_only must keep an admin login');
            self::assertNotContains((string) $regularUserId, $objectIds, 'admins_only must exclude a non-admin login');
        } finally {
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'activity_display_connections'");
            CachePools::config()->clear();
        }
    }

    /**
     * getActivityList()'s $username_of enrichment (built from the
     * performed_by/object_id of every fetched row) and its object==='user'
     * per-line 'details.users'/'details.users_string' enrichment loop --
     * neither is exercised by the object/action-filter tests above, which
     * never inspect a line's 'username' or 'details' fields.
     */
    public function testActivityGetListLoginEventEnrichesUsernameAndDetailsUsers(): void
    {
        $adminId = $this->conn->fetchOne("SELECT id FROM users WHERE username = 'fixture_admin'");
        $adminIdString = (string) $adminId;

        // wsAdmin() performs a real pwg.session.login, which AuthService::
        // login() records as a 'user'/'login' row with object_id = the user
        // who just logged in (fixture_admin) but performed_by = the
        // pre-login identity (guest -- login is recorded before CurrentUser
        // is re-resolved to the newly authenticated user, confirmed live
        // via a direct DB query before writing this assertion).
        $response = $this->wsAdmin('pwg.activity.getList', [
            'object' => 'user',
            'action' => 'login',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['result_lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);

        $line = null;
        foreach ($lines as $candidate) {
            self::assertIsArray($candidate);
            if ($candidate['object_id'] === [$adminIdString]) {
                $line = $candidate;
                break;
            }
        }
        self::assertNotNull($line, 'expected a login row whose object_id is fixture_admin');

        // 'username' reflects the performer (performed_by = guest), not the
        // object being logged in.
        self::assertSame('guest', $line['username']);

        $details = $line['details'];
        self::assertIsArray($details);
        self::assertSame(['fixture_admin'], $details['users']);
        self::assertSame('fixture_admin', $details['users_string']);
    }

    /**
     * getActivityList()'s outer `while (count($output_lines) < $page_size
     * and $more_rows_available)` loop fetches up to 10000 rows per DB
     * query (nb_rows_to_fetch) in one go -- the `else { $more_rows_available
     * = true; break; }` arm stops iterating that *already-fetched* PHP
     * array once $page_size (100) concatenated lines have been built,
     * distinct from the query itself running out of rows. 150 real rows
     * with unique session_idx values (so none concatenate into an
     * existing line) reliably exceeds 100 regardless of whatever
     * unrelated 'photo'/'edit' noise earlier Contract test files may have
     * already left in activity (this file's own tearDown() doesn't
     * scope its DELETE to these rows either, so leftover noise never
     * accumulates across this file's own tests).
     */
    public function testActivityGetListCapsASingleBatchAtThePageSize(): void
    {
        $marker = 'pwgcore-pagesize-' . uniqid();
        $rows = [];
        for ($i = 0; $i < 150; $i++) {
            $rows[] = sprintf(
                "('photo', 1, 'edit', '%s-%d', NOW())",
                $marker,
                $i
            );
        }
        $this->conn->executeStatement(
            'INSERT INTO activity (object, object_id, action, session_idx, occured_on) VALUES '
            . implode(', ', $rows)
        );

        $response = $this->wsAdmin('pwg.activity.getList', [
            'object' => 'photo',
            'action' => 'edit',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['result_lines'];
        self::assertIsArray($lines);
        self::assertCount(100, $lines, 'a single batch must stop at page_size (100) even though 150 distinct lines matched');
        self::assertFalse($result['end_page'], 'more matching rows remain beyond this page, so end_page must be false');
    }

    /**
     * getActivityList()'s per-line `object==='user'` enrichment loop:
     * `foreach ($output_line['object_id'] as $user_id) { if (!
     * is_string($user_id)) { continue; } ... }` -- `object_id` is an
     * `int unsigned NOT NULL` column (see activity's own CREATE
     * TABLE), and every element of `$output_line['object_id']` is built
     * from it via `is_scalar($row['object_id']) ? (string) ... : null`
     * (always scalar for a NOT NULL int column) -- `is_string($user_id)`
     * is therefore always true for any row a real DB query can ever
     * produce, and the `continue` is genuinely unreachable through this
     * class's real callers. Not exercised by a test for that reason
     * (same "provably unreachable, not silently skipped" rationale this
     * coverage pass documents rather than works around).
     */
    public function testActivityGetListResetsANonArrayUsersDetailBeforeAppending(): void
    {
        $adminId = $this->conn->fetchOne("SELECT id FROM users WHERE username = 'fixture_admin'");
        $adminIdInt = $adminId;

        // A real 'user' activity row whose `details` JSON already has a
        // 'users' key set to a non-array value -- no real
        // ActivityLoggerInterface::record() call site ever does this
        // (grep-confirmed: every 'user'-object record() call across
        // UserService/GroupService/AuthService/PasswordController/
        // RegisterController/ProfileFormHandler passes 'associated'/no
        // extra details at all, never 'users'), so a raw INSERT is the
        // only way to reach this guard for real -- same "reproduce the
        // only real way this state could exist" rationale as
        // WsHistoryTest's own dangling-image-id/dangling-user-id tests.
        $this->conn->executeStatement(
            "INSERT INTO activity (object, object_id, action, session_idx, occured_on, details) VALUES ('user', ?, 'edit', ?, NOW(), ?)",
            [
                $adminIdInt, 'pwgcore-malformed-users-' . uniqid(), json_encode([
                    'users' => 'not-an-array',
                ])]
        );

        $response = $this->wsAdmin('pwg.activity.getList', [
            'object' => 'user',
            'action' => 'edit',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['result_lines'];
        self::assertIsArray($lines);

        $line = null;
        foreach ($lines as $candidate) {
            self::assertIsArray($candidate);
            if ($candidate['object_id'] === [(string) $adminIdInt]) {
                $line = $candidate;
                break;
            }
        }
        self::assertNotNull($line, 'expected the malformed-details row to appear in the result');

        $details = $line['details'];
        self::assertIsArray($details);
        // The malformed pre-existing string value must be discarded (reset
        // to []), not concatenated onto -- otherwise this would be a
        // non-array 'users' rather than the freshly-appended list.
        self::assertSame(['fixture_admin'], $details['users']);
        self::assertSame('fixture_admin', $details['users_string']);
    }

    public function testHistorySearchResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.history.search');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.history.search', $response);
    }

    public function testHistorySearchContainsLines(): void
    {
        $response = $this->wsAdmin('pwg.history.search');

        $result = $response['result'];
        if (! is_array($result)) {
            self::fail('pwg.history.search result is not an array');
        }

        self::assertArrayHasKey('lines', $result);
        self::assertIsArray($result['lines']);
    }

    public function testHistoryLogRecordsAVisitAndIncrementsTheHitCounter(): void
    {
        $this->enableHistoryForAdmin();
        $beforeHit = $this->conn->fetchOne('SELECT hit FROM images WHERE id = 1');
        self::assertIsNumeric($beforeHit);

        $response = $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
            'cat_id' => 1,
            'section' => 'categories',
            // HistoryService::logVisit() only builds tag_ids when the
            // *section* itself is 'tags' -- tags_string is otherwise
            // silently ignored, not a bug in this 'categories' call.
            'tags_string' => '1,2',
            'is_download' => true,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertNull($response['result']);

        $row = $this->conn->fetchAssociative(
            'SELECT * FROM history WHERE image_id = 1 ORDER BY id DESC LIMIT 1'
        );
        self::assertIsArray($row);
        self::assertSame('high', $row['image_type']);
        self::assertSame('categories', $row['section']);
        self::assertSame(1, $row['category_id']);
        self::assertNull($row['tag_ids']);

        $afterHit = $this->conn->fetchOne('SELECT hit FROM images WHERE id = 1');
        self::assertSame($beforeHit + 1, $afterHit);
    }

    public function testHistoryLogWithTagsSectionStoresTagIds(): void
    {
        $this->enableHistoryForAdmin();

        $response = $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
            'section' => 'tags',
            'tags_string' => '1,2',
        ]);

        self::assertSame('ok', $response['stat']);

        $row = $this->conn->fetchAssociative(
            'SELECT * FROM history WHERE image_id = 1 ORDER BY id DESC LIMIT 1'
        );
        self::assertIsArray($row);
        self::assertSame('tags', $row['section']);
        self::assertSame('1,2', $row['tag_ids']);
    }

    public function testHistoryLogWithAnInvalidSectionStoresNull(): void
    {
        $this->enableHistoryForAdmin();

        $response = $this->wsAdmin('pwg.history.log', [
            'image_id' => 2,
            'section' => 'not-a-real-section',
        ]);

        self::assertSame('ok', $response['stat']);

        $section = $this->conn->fetchOne(
            'SELECT section FROM history WHERE image_id = 2 ORDER BY id DESC LIMIT 1'
        );
        self::assertNull($section);
    }

    public function testHistoryLogRejectsAZeroImageId(): void
    {
        // historyLog()'s own body has an `if ($params['image_id'] !== 0)`
        // branch (skip the hit-counter increment for a non-photo visit),
        // but the WS registration types image_id as WsParamType::ID
        // (INT|POSITIVE|NOTNULL, min_range 1) -- PwgServer::invoke()'s own
        // parameter check rejects 0 before the method body ever runs, so
        // that branch is dead code via this real WS route.
        $response = $this->wsAdmin('pwg.history.log', [
            'image_id' => 0,
            'section' => 'tags',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
    }

    public function testHistorySearchReturnsALoggedVisitWithFullDetails(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
            'section' => 'tags',
            'tags_string' => '1',
            'is_download' => true,
        ]);

        $response = $this->wsAdmin('pwg.history.search');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);

        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('1', $line['IMAGEID']);
        self::assertSame('high', $line['TYPE']);
        self::assertSame('tags', $line['SECTION']);
        self::assertIsString($line['IMAGE']);
        self::assertStringContainsString('<img', $line['IMAGE']);
        self::assertSame(['1'], $line['TAGIDS']);
    }

    public function testHistorySearchFiltersByImageId(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 2,
        ]);

        $response = $this->wsAdmin('pwg.history.search', [
            'image_id' => 2,
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('2', $line['IMAGEID']);
    }

    /**
     * Real bug found live: a real browser client always sends every
     * current_param key, including image_id as the literal empty string
     * when no image filter is active (history.tpl's own `image_id: {if
     * isset($IMAGE_ID)}"{$IMAGE_ID}"{else}""{/if}`) -- unlike this test
     * suite's own wsAdmin() helper, which (like the other tests in this
     * file) simply omits the key entirely when unused, so this exact
     * client shape was never covered before. PwgServer::checkType()
     * deliberately skips its own int/positive coercion for an
     * empty-string param, so '' reached historySearch()'s old `!== 0`
     * guard unconverted, got persisted as image_id: 0 via intval(''),
     * and HistoryRepository::search() later threw
     * "ImageId must be a positive integer, got 0" -- a genuine,
     * always-triggered 500 on admin.php?page=history's default,
     * unfiltered search (confirmed live via a real Playwright
     * reproduction), not a test-only edge case.
     */
    public function testHistorySearchWithAnEmptyStringImageIdDoesNotThrow(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);

        $response = $this->wsAdmin('pwg.history.search', [
            'image_id' => '',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['lines']);
    }

    public function testHistorySearchFiltersByTypes(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
            'is_download' => false,
        ]);
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 2,
            'is_download' => true,
        ]);

        $response = $this->wsAdmin('pwg.history.search', [
            'types' => ['high'],
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('high', $line['TYPE']);
    }

    public function testHistorySearchFiltersByFilename(): void
    {
        $this->enableHistoryForAdmin();
        $file = $this->conn->fetchOne('SELECT file FROM images WHERE id = 1');
        self::assertIsString($file);

        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 2,
        ]);

        $response = $this->wsAdmin('pwg.history.search', [
            'filename' => $file,
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('1', $line['IMAGEID']);
    }

    public function testHistorySearchFiltersByFilenameWithNoMatchingImageReturnsNoLines(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);

        $response = $this->wsAdmin('pwg.history.search', [
            'filename' => 'no-such-file-' . uniqid() . '.jpg',
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame([], $result['lines']);
    }

    public function testHistorySearchFiltersByIp(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);

        $loggedIp = $this->conn->fetchOne('SELECT IP FROM history WHERE image_id = 1 ORDER BY id DESC LIMIT 1');
        self::assertIsString($loggedIp);
        self::assertNotSame('', $loggedIp);

        $matching = $this->wsAdmin('pwg.history.search', [
            'ip' => $loggedIp,
        ]);
        $matchingResult = $matching['result'];
        self::assertIsArray($matchingResult);
        self::assertNotEmpty($matchingResult['lines']);

        $nonMatching = $this->wsAdmin('pwg.history.search', [
            'ip' => '203.0.113.' . random_int(1, 254),
        ]);
        $nonMatchingResult = $nonMatching['result'];
        self::assertIsArray($nonMatchingResult);
        self::assertSame([], $nonMatchingResult['lines']);
    }

    public function testHistorySearchFiltersByDateRange(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);

        $frozenToday = substr((string) getenv('PIWIGO_TEST_NOW'), 0, 10);
        self::assertNotSame('', $frozenToday);

        $matching = $this->wsAdmin('pwg.history.search', [
            'start' => $frozenToday,
            'end' => $frozenToday,
        ]);
        $matchingResult = $matching['result'];
        self::assertIsArray($matchingResult);
        self::assertNotEmpty($matchingResult['lines']);

        $tomorrowTimestamp = strtotime($frozenToday . ' +1 day');
        self::assertNotFalse($tomorrowTimestamp);
        $tomorrow = date('Y-m-d', $tomorrowTimestamp);
        $nonMatching = $this->wsAdmin('pwg.history.search', [
            'start' => $tomorrow,
        ]);
        $nonMatchingResult = $nonMatching['result'];
        self::assertIsArray($nonMatchingResult);
        self::assertSame([], $nonMatchingResult['lines']);
    }

    public function testHistorySearchFiltersByUserIdAndEnrichesTheUsername(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);

        $adminId = $this->conn->fetchOne("SELECT id FROM users WHERE username = 'fixture_admin'");

        $response = $this->wsAdmin('pwg.history.search', [
            'user_id' => $adminId,
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('fixture_admin', $line['USERNAME']);
        self::assertSame((string) $adminId, $line['USERID']);

        $nonMatching = $this->wsAdmin('pwg.history.search', [
            'user_id' => 999999,
        ]);
        $nonMatchingResult = $nonMatching['result'];
        self::assertIsArray($nonMatchingResult);
        self::assertSame([], $nonMatchingResult['lines']);
    }

    public function testHistorySearchEnrichesTheCategoryName(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
            'cat_id' => 1,
            'section' => 'categories',
        ]);

        $expectedName = $this->conn->fetchOne('SELECT name FROM categories WHERE id = 1');
        self::assertIsString($expectedName);

        $response = $this->wsAdmin('pwg.history.search', [
            'image_id' => 1,
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('categories', $line['SECTION']);
        self::assertIsString($line['CATEGORY']);
        self::assertStringContainsString($expectedName, $line['CATEGORY']);
        self::assertIsString($line['FULL_CATEGORY_PATH']);
        self::assertStringContainsString($expectedName, $line['FULL_CATEGORY_PATH']);
    }

    public function testHistorySearchEnrichesTheTagName(): void
    {
        $this->enableHistoryForAdmin();
        $tagName = $this->conn->fetchOne('SELECT name FROM tags WHERE id = 1');
        self::assertIsString($tagName);

        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
            'section' => 'tags',
            'tags_string' => '1',
        ]);

        $response = $this->wsAdmin('pwg.history.search');

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertIsArray($line['TAGS']);
        self::assertContains($tagName, $line['TAGS']);
    }

    public function testHistorySearchCountsAGuestVisitInTheSummary(): void
    {
        $this->enableHistoryForGuest();
        $guestResponse = $this->ws('pwg.history.log', [
            'image_id' => 1,
        ]);
        self::assertSame('ok', $guestResponse['stat']);

        $response = $this->wsAdmin('pwg.history.search');

        $result = $response['result'];
        self::assertIsArray($result);
        $summary = $result['summary'];
        self::assertIsArray($summary);
        self::assertIsString($summary['GUESTS']);
        self::assertStringNotContainsString('0 guest', $summary['GUESTS']);
    }

    /**
     * historySearch()'s "reconstruct rule details for a saved search_id"
     * branch -- pwg.history.log has no search_id parameter at all
     * (confirmed via its WsDefaultMethods registration and
     * HistoryService::logVisit()'s own signature: image_id/image_type/
     * section/category/tagIds only), so the *only* way a real
     * history row ever gets a non-null search_id is a lower-level
     * write this Contract suite doesn't otherwise reach -- writing it
     * directly via SQL, then reading it back through the real WS route, is
     * the same "set up a precondition unreachable via the WS API itself"
     * technique WsImagesMaintenanceTest already uses for a null md5sum.
     *
     * allwords/author/filetypes are used here (not tags/categories/
     * added_by) -- see the sibling test below for why.
     */
    public function testHistorySearchReconstructsDetailsOfASavedSearch(): void
    {
        $this->enableHistoryForAdmin();
        // pwg.history.search is admin_only -- getPwgToken() reads the
        // *current* session's token, so the login must happen first
        // (confirmed live: without it, the session stays guest and the
        // final search call below fails with 401 Access denied).
        $this->loginAsAdmin();
        $token = $this->getPwgToken();

        $searchResponse = $this->callWs('pwg.images.filteredSearch.create', [
            'allwords' => 'mountain lake',
            'authors' => ['Alice'],
            'filetypes' => ['jpg'],
        ]);
        self::assertSame('ok', $searchResponse['stat']);
        $searchResult = $searchResponse['result'];
        self::assertIsArray($searchResult);
        $searchUuid = $searchResult['search_id'];
        self::assertIsString($searchUuid);
        $searchDbId = $this->conn->fetchOne(
            'SELECT id FROM search WHERE search_uuid = ?',
            [$searchUuid]
        );
        self::assertIsNumeric($searchDbId);

        $this->callWs('pwg.history.log', [
            'image_id' => 1,
        ]);
        $this->conn->executeStatement(
            'UPDATE history SET search_id = ? WHERE image_id = 1',
            [$searchDbId]
        );

        $response = $this->callWs('pwg.history.search', [
            'image_id' => 1,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame((string) $searchDbId, $line['SEARCH_ID']);
        $details = $line['SEARCH_DETAILS'];
        self::assertIsArray($details);
        self::assertSame(['mountain', 'lake'], $details['allwords']);
        self::assertSame(['Alice'], $details['author']);
        self::assertSame(['jpg'], $details['filetypes']);
        self::assertNull($details['tags']);
        self::assertNull($details['cat']);
        self::assertNull($details['added_by']);
        self::assertNull($details['date_posted']);
    }

    /**
     * Regression-documenting test for a real, pre-existing bug found while
     * writing the sibling test above: historySearch()'s own
     * tags/cat/added_by reconstruction filters each id list through
     * `array_filter($words, is_string(...))` -- but
     * filteredSearchCreate() registers 'tags'/'categories'/'added_by' with
     * 'type' => WsParamType::ID, so PwgServer::checkType() coerces every
     * element to a real PHP int (filter_var(..., FILTER_VALIDATE_INT)), and
     * json round-tripping through search.rules preserves that int
     * type. is_string() on an int is always false, so the filter empties
     * the list every time regardless of what was actually searched --
     * confirmed live (a real WS call, not a unit test poking internals)
     * before writing this test. Out of scope to fix in a test-writing
     * pass; documented here so a future fix is a deliberate, visible
     * change to this assertion, not a silent one.
     */
    public function testHistorySearchSavedSearchTagsCatAddedByAreAlwaysNullDueToARealBug(): void
    {
        $this->enableHistoryForAdmin();
        $this->loginAsAdmin();
        $token = $this->getPwgToken();

        $searchResponse = $this->callWs('pwg.images.filteredSearch.create', [
            'tags' => [1],
            'categories' => [1],
            'added_by' => [1],
        ]);
        self::assertSame('ok', $searchResponse['stat']);
        $searchResult = $searchResponse['result'];
        self::assertIsArray($searchResult);
        $searchUuid = $searchResult['search_id'];
        self::assertIsString($searchUuid);
        $searchDbId = $this->conn->fetchOne(
            'SELECT id FROM search WHERE search_uuid = ?',
            [$searchUuid]
        );
        self::assertIsNumeric($searchDbId);

        $this->callWs('pwg.history.log', [
            'image_id' => 1,
        ]);
        $this->conn->executeStatement(
            'UPDATE history SET search_id = ? WHERE image_id = 1',
            [$searchDbId]
        );

        $response = $this->callWs('pwg.history.search', [
            'image_id' => 1,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $line = $lines[0];
        self::assertIsArray($line);
        $details = $line['SEARCH_DETAILS'];
        self::assertIsArray($details);
        self::assertNull($details['tags'], 'documents the real bug: should reflect tag 1, always null instead');
        self::assertNull($details['cat'], 'documents the real bug: should reflect category 1, always null instead');
        self::assertNull($details['added_by'], 'documents the real bug: should reflect user 1, always null instead');
    }

    /**
     * historySearch()'s saved-search reconstruction loop has two guards
     * around each `search.rules` row it reads back via
     * SearchRepository::findRulesByIds(): `if ($rules_full === null) {
     * continue; }` (the `rules` column itself is NULL) and `$rules_search
     * = isset($rules_full['fields']) && is_array($rules_full['fields']) ?
     * $rules_full['fields'] : [];` (a non-NULL `rules` JSON blob with no
     * 'fields' key at all). Neither is reachable via the real WS API --
     * SearchRepository::insertSearch() (the only real writer, called from
     * pwg.images.filteredSearch.create) always stores a real
     * `{"fields": {...}}` shape -- so both rows are written directly, the
     * same "reproduce the only real way this state could exist" rationale
     * as this file's own dangling-image-id test. `rules` is `json DEFAULT
     * NULL` (nullable), confirmed against search's own CREATE TABLE.
     */
    public function testHistorySearchGracefullySkipsASavedSearchWithMalformedOrNullRules(): void
    {
        $this->enableHistoryForAdmin();

        $this->conn->executeStatement(
            'INSERT INTO search (search_uuid, rules) VALUES (?, NULL)',
            ['pwgcoretst' . substr(uniqid(), 0, 13)]
        );
        $nullRulesSearchId = (int) $this->conn->lastInsertId();

        $this->conn->executeStatement(
            'INSERT INTO search (search_uuid, rules) VALUES (?, ?)',
            [
                'pwgcoretst' . substr(uniqid(), 0, 13), json_encode([
                    'created_by' => 5,
                ])]
        );
        $noFieldsSearchId = (int) $this->conn->lastInsertId();

        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 2,
        ]);
        $this->conn->executeStatement(
            'UPDATE history SET search_id = ? WHERE image_id = 1',
            [$nullRulesSearchId]
        );
        $this->conn->executeStatement(
            'UPDATE history SET search_id = ? WHERE image_id = 2',
            [$noFieldsSearchId]
        );

        $response = $this->wsAdmin('pwg.history.search');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);

        $byImageId = [];
        foreach ($lines as $line) {
            self::assertIsArray($line);
            $imageId = $line['IMAGEID'];
            self::assertIsString($imageId);
            $byImageId[$imageId] = $line;
        }

        // Neither malformed row crashes the request -- both degrade to an
        // all-null SEARCH_DETAILS rather than propagating the missing/
        // malformed rules shape.
        foreach (['1', '2'] as $imageId) {
            if (! array_key_exists($imageId, $byImageId)) {
                throw new RuntimeException("expected image #{$imageId} in the history search results");
            }
            $details = $byImageId[$imageId]['SEARCH_DETAILS'];
            self::assertIsArray($details);
            self::assertNull($details['allwords']);
            self::assertNull($details['tags']);
            self::assertNull($details['cat']);
            self::assertNull($details['author']);
            self::assertNull($details['added_by']);
            self::assertNull($details['filetypes']);
        }
    }

    /**
     * historySearch()'s `EventDispatcherTestFactory::get()->dispatchChange(new
     * GetHistory([], ...))` fails loud now: a GetHistory handler that returns something other
     * than a GetHistory instance makes dispatchChange() throw \Error,
     * rather than the old triggerChange()'s silent "narrow to [] on a
     * non-array return" fallback this test used to assert. historyGet()
     * (this class's own GetHistory handler, registered at the default
     * priority 50) always returns a real GetHistory instance, so reaching
     * the misbehaving-handler path for real needs a second, higher-
     * priority handler chained after it -- exactly the "a plugin can still
     * override history search behavior by registering its own GetHistory
     * handler at a higher priority" scenario historyGet()'s own docblock
     * describes. A real plugin file + `plugins` activation row
     * (PluginLoader::loadPlugins() include_once()s it on every real
     * request, including ws.php) is the same established technique as
     * tests/Browser/PictureControllerTest.php's own 'user_comment_check'
     * misbehaving-plugin test -- EventDispatcher's own singleton lives in
     * the real Apache-served process, not this Pest process, so it can't
     * be reached by registering a handler here directly. Scoped to a
     * throwaway image_id (never 1-5) so it's a complete no-op for every
     * other concurrent request against this shared dev server while
     * active.
     *
     * Asserts the raw HTTP status only, not a decoded response body:
     * ExceptionHandlerMiddleware catches the uncaught \Error and returns a
     * real 500 (display_errors is off site-wide, so there's no fatal-error
     * text to see even if it weren't caught) -- callWs()/wsAdmin() both
     * assert 2xx/3xx and json_decode()-then-assertIsArray() the body, so
     * neither fits this scenario; same "HTTP-status-only" assertion style
     * as the Browser suite's own misbehaving-handler tests.
     */
    public function testHistorySearchFatalsWhenAPluginGetHistoryHandlerReturnsSomethingOtherThanAGetHistoryInstance(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO images (file, path) VALUES (?, ?)',
            ['pwgcore-gethistory-' . uniqid() . '.jpg', 'upload/pwgcore-gethistory-throwaway.jpg']
        );
        $imageId = (int) $this->conn->lastInsertId();

        $pluginId = 'pwgtest-gethistory-override';
        $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
        $mainFile = $pluginDir . '/main.inc.php';

        try {
            $this->enableHistoryForAdmin();
            $this->wsAdmin('pwg.history.log', [
                'image_id' => $imageId,
            ]);

            // Sanity check first: without the plugin active, the real
            // history row for this throwaway image is genuinely found.
            $before = $this->wsAdmin('pwg.history.search', [
                'image_id' => $imageId,
            ]);
            self::assertSame('ok', $before['stat']);
            $beforeResult = $before['result'];
            self::assertIsArray($beforeResult);
            self::assertNotEmpty($beforeResult['lines']);

            if (! is_dir($pluginDir) && ! mkdir($pluginDir, 0o777, true) && ! is_dir($pluginDir)) {
                throw new RuntimeException('failed to create plugin dir: ' . $pluginDir);
            }
            file_put_contents($mainFile, <<<PHP
                <?php

                declare(strict_types=1);

                /*
                Plugin Name: WsHistoryTest -- get_history Override
                Version: 1.0.0
                Description: Test-only fixture plugin (tests/Contract/WsHistoryTest.php).
                */

                \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addEventHandler(
                    \\Piwigo\\Event\\Ws\\GetHistory::class,
                    static function (\\Piwigo\\Event\\Ws\\GetHistory \$event): mixed {
                        \$fields = \$event->search['fields'] ?? null;
                        \$imageId = is_array(\$fields) ? (\$fields['image_id'] ?? null) : null;
                        if (\$imageId === {$imageId}) {
                            return false;
                        }
                        return \$event;
                    },
                    51
                );

                PHP);

            $this->conn->executeStatement(
                "INSERT INTO plugins (id, state, version) VALUES (?, 'active', '1.0.0')",
                [$pluginId]
            );

            $url = $this->baseUrl . '/ws.php?format=json';
            $ch = curl_init($url);
            self::assertNotFalse($ch, 'curl_init failed');
            $cookieJar = $this->cookieJar();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'method' => 'pwg.history.search',
                'image_id' => $imageId,
            ]));
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
            curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);

            self::assertSame(500, $status, 'a plugin GetHistory handler returning a non-GetHistory instance must fatal (fail loud), not silently degrade');
        } finally {
            $this->conn->executeStatement('DELETE FROM plugins WHERE id = ?', [$pluginId]);
            @unlink($mainFile);
            @rmdir($pluginDir);
            $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        }
    }

    public function testHistorySearchComputesTheTotalFilesizeOfHighTypeImages(): void
    {
        $this->enableHistoryForAdmin();
        $filesize = $this->conn->fetchOne('SELECT filesize FROM images WHERE id = 1');
        self::assertIsNumeric($filesize);

        // historyLog() with is_download=true records image_type='high'
        // (see test_historyLog_records_a_visit_and_increments_the_hit_counter
        // above) -- the only image_type that feeds the FILESIZE summary.
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
            'is_download' => true,
        ]);

        $response = $this->wsAdmin('pwg.history.search', [
            'image_id' => 1,
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        $summary = $result['summary'];
        self::assertIsArray($summary);
        self::assertSame((int) ceil($filesize / 1024), $summary['FILESIZE']);
    }

    /**
     * historySearch()'s per-line image/category formatting has explicit
     * fallback defaults when the referenced image_id/category_id no
     * longer matches a real row: EDIT_IMAGE/IMAGENAME fall back to
     * ""/" unknown filename" when image_infos[$line_image_id]['label']
     * is unset, and CATEGORY/FULL_CATEGORY_PATH fall back to
     * Lang::t('Root') . $line_category_id (here, both null, so just
     * 'Root'). A real dangling image_id can only happen via a raw write
     * (fk_history_image_id is ON DELETE SET NULL, so a normal image
     * deletion just nulls the column instead of leaving a real dangling
     * id) -- same "reproduce the only real way this state has ever
     * existed" rationale as UserServiceTest's own
     * SET FOREIGN_KEY_CHECKS=0 pattern -- confirmed live (a real WS call
     * against the real Apache-served app) before writing this assertion.
     */
    public function testHistorySearchWithADanglingImageIdAndNoCategoryFallsBackToDefaults(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);

        $this->disableForeignKeyChecks($this->conn);
        // `UPDATE ... ORDER BY ... LIMIT` is a MySQL-only extension --
        // Postgres has no ORDER BY/LIMIT clause on UPDATE at all. The
        // portable form targets the exact row via a subquery instead
        // (evaluated once against the pre-mutation table state on both
        // platforms, same as the MySQL form's own single-statement
        // atomicity). The extra derived-table wrapper (`SELECT id FROM
        // (SELECT id FROM ... ) AS t`) is load-bearing on MySQL
        // specifically -- MySQL rejects a subquery that references the
        // very table being updated ("You can't specify target table ...
        // for update in FROM clause") unless it's first materialized as
        // a derived table; Postgres has no such restriction but accepts
        // this form identically.
        $this->conn->executeStatement(
            'UPDATE history SET image_id = 999999, category_id = NULL, section = NULL '
            . 'WHERE id = (SELECT id FROM (SELECT id FROM history WHERE image_id = 1 ORDER BY id DESC LIMIT 1) AS t)'
        );
        $this->enableForeignKeyChecks($this->conn);

        $response = $this->wsAdmin('pwg.history.search', [
            'image_id' => 999999,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $line = $lines[0];
        self::assertIsArray($line);

        self::assertSame('999999', $line['IMAGEID']);
        self::assertSame('', $line['EDIT_IMAGE']);
        self::assertSame(' unknown filename', $line['IMAGENAME']);
        self::assertIsString($line['IMAGE']);
        self::assertStringContainsString('unknown', $line['IMAGE']);

        self::assertSame('Root', $line['CATEGORY']);
        self::assertSame('Root', $line['FULL_CATEGORY_PATH']);
    }

    /**
     * historySearch()'s per-line pagination-window guard, faithfully
     * preserved from the pre-rewrite legacy include/ws_functions/pwg.php
     * (`if ($i <= $first_line && $i >= $last_line) { continue; }`,
     * grep-confirmed identical at piwigo16/include/ws_functions/pwg.php:941)
     * -- with the real default nb_logs_page (300), $first_line
     * ($page_start+1) and $last_line ($page_start+300) can never both
     * bound the same $i, so this `continue` is realistically dead under
     * the shipped default. nb_logs_page=1 is the only value where
     * $first_line===$last_line (both equal $page_start+1), reaching it
     * for real: with the default $page_start=0, exactly the line with
     * $i===1 -- the chronologically *oldest* of the matched rows
     * (historyCompare() sorts ascending by date+time) -- gets skipped.
     * A real, if odd, legacy pagination quirk, not a rewrite regression.
     */
    public function testHistorySearchPaginationWindowSkipsOneLineWhenNbLogsPageIsOne(): void
    {
        $this->enableHistoryForAdmin();
        $this->upsertConfig('nb_logs_page', '1');
        CachePools::config()->clear();

        try {
            $this->wsAdmin('pwg.history.log', [
                'image_id' => 1,
            ]);
            $this->wsAdmin('pwg.history.log', [
                'image_id' => 2,
            ]);

            $response = $this->wsAdmin('pwg.history.search');

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $lines = $result['lines'];
            self::assertIsArray($lines);
            // Both rows are real, distinct, matched lines -- with the
            // default nb_logs_page (300) both would appear; here exactly
            // one is dropped by the pagination-window guard.
            self::assertCount(1, $lines);
            $line = $lines[0];
            self::assertIsArray($line);
            self::assertContains($line['IMAGEID'], ['1', '2']);
        } finally {
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'nb_logs_page'");
            CachePools::config()->clear();
        }
    }

    /**
     * historySearch()'s per-line `if (isset($username_of[$user_id_key]))
     * {...} else { $user_string .= $user_id_key; }` -- the else arm (raw
     * id instead of a resolved username) is only reachable for a user_id
     * getUsernamesByIds() can't resolve. history.user_id is
     * `mediumint unsigned NOT NULL` with `fk_history_user_id ... ON
     * DELETE CASCADE` (deleting a user deletes their own history rows
     * too), so no genuine WS-API-driven history row can ever carry an
     * unresolvable user_id -- same "reproduce the only real way this
     * state could exist" raw-SQL-with-FK-checks-off technique as this
     * file's own dangling-image-id test above.
     */
    public function testHistorySearchWithAnUnrecognizedUserIdFallsBackToTheRawId(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
        ]);

        $this->disableForeignKeyChecks($this->conn);
        // Portable derived-table subquery form -- see the dangling-image-id
        // test above for why `UPDATE ... ORDER BY ... LIMIT` itself isn't,
        // and why the extra derived-table wrapper is needed on MySQL
        // specifically.
        $this->conn->executeStatement(
            'UPDATE history SET user_id = 999999 '
            . 'WHERE id = (SELECT id FROM (SELECT id FROM history WHERE image_id = 1 ORDER BY id DESC LIMIT 1) AS t)'
        );
        $this->enableForeignKeyChecks($this->conn);

        try {
            $response = $this->wsAdmin('pwg.history.search', [
                'image_id' => 1,
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $lines = $result['lines'];
            self::assertIsArray($lines);
            self::assertNotEmpty($lines);
            $line = $lines[0];
            self::assertIsArray($line);
            self::assertSame('999999', $line['USERID']);
            self::assertSame('#unknown', $line['USERNAME']);
            self::assertIsString($line['USER']);
            self::assertStringStartsWith('999999&nbsp;<a href="', $line['USER']);
        } finally {
            // Restore a resolvable user_id before this file's own tearDown()
            // deletes the row, and before any later test in this file reads
            // history back -- avoids leaving a permanently dangling FK-off
            // row behind for a test that runs after this one. fixture_admin
            // (id 1, seeded by every Contract test's own shared fixture) is
            // always present -- no assertion needed in a `finally` cleanup.
            $this->disableForeignKeyChecks($this->conn);
            $this->conn->executeStatement(
                'UPDATE history SET user_id = (SELECT id FROM users' . " WHERE username = 'fixture_admin') WHERE user_id = 999999"
            );
            $this->enableForeignKeyChecks($this->conn);
        }
    }

    /**
     * historySearch()'s per-line image formatting is entirely skipped when
     * a history row's image_id is null -- IMAGE/IMAGENAME/IMAGEID/
     * EDIT_IMAGE all stay at their initial '' default (`if
     * ($line_image_id !== null)` never runs at all) -- distinct from the
     * "dangling image_id" test above, where line_image_id is a real,
     * non-null (but orphaned) value. A real image_id-less history row can
     * only come from a genuine category/album browse
     * (GalleryController::index()'s own logVisit() call passes
     * section/category only, no image_id argument at all) --
     * pwg.history.log always requires a real positive image_id
     * (WsParamType::ID), so the WS API itself can never produce one; a
     * real page visit is needed, confirmed live before writing this
     * assertion.
     */
    public function testHistorySearchWithAnAlbumBrowseRowLeavesImageFieldsEmpty(): void
    {
        $this->enableHistoryForAdmin();
        $this->loginAsAdmin();

        $cookieJar = $this->cookieJar();
        $ch = curl_init($this->baseUrl . '/index.php');
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        self::assertSame(200, $status);

        $row = $this->conn->fetchAssociative(
            "SELECT id FROM history WHERE image_id IS NULL AND section = 'categories' ORDER BY id DESC LIMIT 1"
        );
        self::assertIsArray($row, 'expected the /index.php visit above to log a real image_id-less history row');

        $response = $this->wsAdmin('pwg.history.search');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);

        $line = null;
        foreach ($lines as $candidate) {
            self::assertIsArray($candidate);
            if ($candidate['SECTION'] === 'categories' && $candidate['IMAGEID'] === '') {
                $line = $candidate;
                break;
            }
        }
        self::assertNotNull($line, 'expected a category-browse row with no image_id');

        self::assertSame('', $line['IMAGE']);
        self::assertSame('', $line['IMAGENAME']);
        self::assertSame('', $line['EDIT_IMAGE']);
    }

    /**
     * historySearch()'s display_thumbnail cookie: emptyValue('')
     * short-circuits InputValidator::validate()'s mandatory=false check
     * (no [Hacking attempt] error, unlike the invalid non-empty value
     * tested below), but the `$param['display_thumbnail'] !== ''` guard
     * around the setCookieVar() call means an explicit empty value clears
     * the cookie (CookieService::setCookieVar(..., null, ...)) instead of
     * persisting a chosen value -- every other call in this file omits
     * the param entirely, which uses the non-empty
     * 'display_thumbnail_classic' default and always takes the opposite
     * branch. Raw curl (not callWs()) to inspect the real Set-Cookie
     * header.
     */
    public function testHistorySearchWithAnEmptyDisplayThumbnailClearsTheCookie(): void
    {
        $this->loginAsAdmin();
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'method=pwg.history.search&display_thumbnail=');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertIsString($response);
        self::assertSame(200, $status);
        self::assertStringContainsString('Set-Cookie: pwg_display_thumbnail=deleted;', $response);
    }

    /**
     * InputValidator::validate()'s rejection path (Validation\InputValidator::
     * fatalError()) renders a real HTML "unrecoverable error" page via
     * HtmlService -- HTTP 500, not the JSON stat=fail envelope every other
     * error in this method uses -- so this can't go through
     * wsAdmin()/callWs() (which expect JSON back).
     */
    private function assertHistorySearchIsAHackingAttempt(string $inputParamName, string $rawPostFields): void
    {
        $this->loginAsAdmin();
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'method=pwg.history.search&' . $rawPostFields);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertIsString($body);
        self::assertSame(500, $status);
        self::assertStringContainsString('[Hacking attempt]', $body);
        self::assertStringContainsString('"' . $inputParamName . '"', $body);
    }

    public function testHistorySearchInvalidTypesReturnsError(): void
    {
        $this->assertHistorySearchIsAHackingAttempt('types', 'types%5B%5D=not-a-real-type');
    }

    public function testHistorySearchInvalidDateFormatReturnsError(): void
    {
        $this->assertHistorySearchIsAHackingAttempt('start', 'start=not-a-date');
    }

    public function testHistorySearchInvalidDisplayThumbnailReturnsError(): void
    {
        $this->assertHistorySearchIsAHackingAttempt('display_thumbnail', 'display_thumbnail=bogus');
    }

    /**
     * Regression test for a real, pre-existing, documented-but-unfixed
     * bug (see WsDefaultMethods::register()'s own inline comment on the
     * 'pwg.activity.downloadLog' registration): its callback string
     * 'ws_activity_downloadLog' has never actually been defined anywhere
     * in this codebase, confirmed there via a full-repo grep before the
     * legacy include/ws_functions/pwg.php file was even deleted. Calling
     * this method (admin_only, so an authenticated call is required to
     * even reach the broken callback -- PwgServer::invoke()'s own
     * admin_only gate runs before parameter checks or the call itself)
     * therefore always throws a real, uncaught `Call to undefined
     * function` Error -- PwgServer::invoke() has no try/catch around its
     * own call_user_func_array(), so this reaches PHP as an unhandled
     * fatal. Raw curl (not wsAdmin()/callWs(), which assert HTTP status
     * < 500) -- same shape as assertHistorySearchIsAHackingAttempt()
     * above. Out of scope to fix here: flagged in the source as its own
     * follow-up task, not a test-writing-pass concern -- this test only
     * documents the current, real behavior so a future fix is a
     * deliberate, visible change to this test, not a silent one.
     *
     * display_errors is off in the real Apache-served environment, so the
     * body is just the generic "Internal Server Error" text, not a
     * verbose trace containing the undefined function's name -- correct,
     * secure production behavior, confirmed live rather than assumed.
     */
    public function testActivityDownloadLogFatalsOnItsOwnPreExistingUndefinedCallback(): void
    {
        $this->loginAsAdmin();
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'method=pwg.activity.downloadLog');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertIsString($body);
        self::assertSame(500, $status);
        self::assertStringContainsString('Internal Server Error', $body);
    }
}
