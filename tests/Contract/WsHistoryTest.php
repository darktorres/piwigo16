<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class WsHistoryTest extends ContractTestCase
{
    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::history());
        $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param IN ('history_admin', 'history_guest')");
        \Piwigo\Cache\CachePools::config()->clear();
        parent::tearDown();
    }

    /** Enables history logging for the admin session (fixture default: disabled). */
    private function enableHistoryForAdmin(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('history_admin', 'true')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();
    }

    /** Enables history logging for guest (unauthenticated) visitors. */
    private function enableHistoryForGuest(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('history_guest', 'true')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();
    }
    public function test_activityGetList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.activity.getList', $response);
    }

    public function test_activityGetList_contains_result_lines(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList');

        $result = $response['result'];
        if (!is_array($result)) {
            self::fail('pwg.activity.getList result is not an array');
        }

        self::assertArrayHasKey('result_lines', $result);
        self::assertIsArray($result['result_lines']);
    }

    public function test_activityGetList_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.activity.getList');

        self::assertSame('fail', $response['stat']);
    }

    public function test_activityGetList_invalid_date_min_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList', ['date_min' => 'not-a-date']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid date_min', $response['message']);
    }

    public function test_activityGetList_invalid_date_max_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList', ['date_max' => 'not-a-date']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid date_max', $response['message']);
    }

    public function test_activityGetList_filters_by_action_and_object(): void
    {
        // Generate a real, fresh 'photo'/'edit' activity row (getActivityList's
        // WHERE always excludes object='system', so a genuine non-system row
        // is needed to exercise the object/action filter branches for real).
        $this->wsAdmin('pwg.images.setPrivacyLevel', ['image_id' => [1], 'level' => 0]);

        $response = $this->wsAdmin('pwg.activity.getList', ['object' => 'photo', 'action' => 'edit']);

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

    public function test_activityGetList_filters_by_date_range(): void
    {
        $this->wsAdmin('pwg.images.setPrivacyLevel', ['image_id' => [1], 'level' => 0]);
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

    public function test_activityGetList_filters_by_object_id(): void
    {
        $this->wsAdmin('pwg.images.setPrivacyLevel', ['image_id' => [1], 'level' => 0]);
        $this->wsAdmin('pwg.images.setPrivacyLevel', ['image_id' => [2], 'level' => 0]);

        $response = $this->wsAdmin('pwg.activity.getList', ['object' => 'photo', 'id' => 1]);

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

    public function test_activityGetList_excludes_logins_when_connections_display_is_none(): void
    {
        // Config values are always json_decode()'d on read
        // (ConfigService::hydrate()) -- a bare 'none' isn't valid JSON, so
        // json_decode() silently returns null and the value never
        // overrides the 'all' default; it must be stored quoted, same as
        // every other string config value this suite writes directly.
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('activity_display_connections', '\"none\"')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        try {
            // wsAdmin() itself performs a real pwg.session.login, which
            // AuthService::login() records as a 'user'/'login' activity row
            // -- the exact row this config value is meant to hide.
            $response = $this->wsAdmin('pwg.activity.getList', ['object' => 'user', 'action' => 'login']);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertSame([], $result['result_lines']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'activity_display_connections'");
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }

    public function test_historySearch_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.history.search');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.history.search', $response);
    }

    public function test_historySearch_contains_lines(): void
    {
        $response = $this->wsAdmin('pwg.history.search');

        $result = $response['result'];
        if (!is_array($result)) {
            self::fail('pwg.history.search result is not an array');
        }

        self::assertArrayHasKey('lines', $result);
        self::assertIsArray($result['lines']);
    }

    public function test_historyLog_records_a_visit_and_increments_the_hit_counter(): void
    {
        $this->enableHistoryForAdmin();
        $beforeHit = $this->conn->fetchOne('SELECT hit FROM ' . Tables::images() . ' WHERE id = 1');
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
            'SELECT * FROM ' . Tables::history() . ' WHERE image_id = 1 ORDER BY id DESC LIMIT 1'
        );
        self::assertIsArray($row);
        self::assertSame('high', $row['image_type']);
        self::assertSame('categories', $row['section']);
        self::assertIsNumeric($row['category_id']);
        self::assertSame(1, (int) $row['category_id']);
        self::assertNull($row['tag_ids']);

        $afterHit = $this->conn->fetchOne('SELECT hit FROM ' . Tables::images() . ' WHERE id = 1');
        self::assertIsNumeric($afterHit);
        self::assertSame((int) $beforeHit + 1, (int) $afterHit);
    }

    public function test_historyLog_with_tags_section_stores_tag_ids(): void
    {
        $this->enableHistoryForAdmin();

        $response = $this->wsAdmin('pwg.history.log', [
            'image_id' => 1,
            'section' => 'tags',
            'tags_string' => '1,2',
        ]);

        self::assertSame('ok', $response['stat']);

        $row = $this->conn->fetchAssociative(
            'SELECT * FROM ' . Tables::history() . ' WHERE image_id = 1 ORDER BY id DESC LIMIT 1'
        );
        self::assertIsArray($row);
        self::assertSame('tags', $row['section']);
        self::assertSame('1,2', $row['tag_ids']);
    }

    public function test_historyLog_with_an_invalid_section_stores_null(): void
    {
        $this->enableHistoryForAdmin();

        $response = $this->wsAdmin('pwg.history.log', [
            'image_id' => 2,
            'section' => 'not-a-real-section',
        ]);

        self::assertSame('ok', $response['stat']);

        $section = $this->conn->fetchOne(
            'SELECT section FROM ' . Tables::history() . ' WHERE image_id = 2 ORDER BY id DESC LIMIT 1'
        );
        self::assertNull($section);
    }

    public function test_historyLog_rejects_a_zero_image_id(): void
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

    public function test_historySearch_returns_a_logged_visit_with_full_details(): void
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

    public function test_historySearch_filters_by_image_id(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', ['image_id' => 1]);
        $this->wsAdmin('pwg.history.log', ['image_id' => 2]);

        $response = $this->wsAdmin('pwg.history.search', ['image_id' => 2]);

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('2', $line['IMAGEID']);
    }

    public function test_historySearch_filters_by_types(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', ['image_id' => 1, 'is_download' => false]);
        $this->wsAdmin('pwg.history.log', ['image_id' => 2, 'is_download' => true]);

        $response = $this->wsAdmin('pwg.history.search', ['types' => ['high']]);

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('high', $line['TYPE']);
    }

    public function test_historySearch_filters_by_filename(): void
    {
        $this->enableHistoryForAdmin();
        $file = $this->conn->fetchOne('SELECT file FROM ' . Tables::images() . ' WHERE id = 1');
        self::assertIsString($file);

        $this->wsAdmin('pwg.history.log', ['image_id' => 1]);
        $this->wsAdmin('pwg.history.log', ['image_id' => 2]);

        $response = $this->wsAdmin('pwg.history.search', ['filename' => $file]);

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('1', $line['IMAGEID']);
    }

    public function test_historySearch_filters_by_filename_with_no_matching_image_returns_no_lines(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', ['image_id' => 1]);

        $response = $this->wsAdmin('pwg.history.search', ['filename' => 'no-such-file-' . uniqid() . '.jpg']);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame([], $result['lines']);
    }

    public function test_historySearch_filters_by_ip(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', ['image_id' => 1]);

        $loggedIp = $this->conn->fetchOne('SELECT IP FROM ' . Tables::history() . ' WHERE image_id = 1 ORDER BY id DESC LIMIT 1');
        self::assertIsString($loggedIp);
        self::assertNotSame('', $loggedIp);

        $matching = $this->wsAdmin('pwg.history.search', ['ip' => $loggedIp]);
        $matchingResult = $matching['result'];
        self::assertIsArray($matchingResult);
        self::assertNotEmpty($matchingResult['lines']);

        $nonMatching = $this->wsAdmin('pwg.history.search', ['ip' => '203.0.113.' . random_int(1, 254)]);
        $nonMatchingResult = $nonMatching['result'];
        self::assertIsArray($nonMatchingResult);
        self::assertSame([], $nonMatchingResult['lines']);
    }

    public function test_historySearch_filters_by_date_range(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', ['image_id' => 1]);

        $frozenToday = substr((string) getenv('PIWIGO_TEST_NOW'), 0, 10);
        self::assertNotSame('', $frozenToday);

        $matching = $this->wsAdmin('pwg.history.search', ['start' => $frozenToday, 'end' => $frozenToday]);
        $matchingResult = $matching['result'];
        self::assertIsArray($matchingResult);
        self::assertNotEmpty($matchingResult['lines']);

        $tomorrowTimestamp = strtotime($frozenToday . ' +1 day');
        self::assertNotFalse($tomorrowTimestamp);
        $tomorrow = date('Y-m-d', $tomorrowTimestamp);
        $nonMatching = $this->wsAdmin('pwg.history.search', ['start' => $tomorrow]);
        $nonMatchingResult = $nonMatching['result'];
        self::assertIsArray($nonMatchingResult);
        self::assertSame([], $nonMatchingResult['lines']);
    }

    public function test_historySearch_filters_by_user_id_and_enriches_the_username(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', ['image_id' => 1]);

        $adminId = $this->conn->fetchOne("SELECT id FROM " . Tables::users() . " WHERE username = 'fixture_admin'");
        self::assertIsNumeric($adminId);

        $response = $this->wsAdmin('pwg.history.search', ['user_id' => (int) $adminId]);

        $result = $response['result'];
        self::assertIsArray($result);
        $lines = $result['lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $line = $lines[0];
        self::assertIsArray($line);
        self::assertSame('fixture_admin', $line['USERNAME']);
        self::assertSame((string) (int) $adminId, $line['USERID']);

        $nonMatching = $this->wsAdmin('pwg.history.search', ['user_id' => 999999]);
        $nonMatchingResult = $nonMatching['result'];
        self::assertIsArray($nonMatchingResult);
        self::assertSame([], $nonMatchingResult['lines']);
    }

    public function test_historySearch_enriches_the_category_name(): void
    {
        $this->enableHistoryForAdmin();
        $this->wsAdmin('pwg.history.log', ['image_id' => 1, 'cat_id' => 1, 'section' => 'categories']);

        $expectedName = $this->conn->fetchOne('SELECT name FROM ' . Tables::categories() . ' WHERE id = 1');
        self::assertIsString($expectedName);

        $response = $this->wsAdmin('pwg.history.search', ['image_id' => 1]);

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

    public function test_historySearch_enriches_the_tag_name(): void
    {
        $this->enableHistoryForAdmin();
        $tagName = $this->conn->fetchOne('SELECT name FROM ' . Tables::tags() . ' WHERE id = 1');
        self::assertIsString($tagName);

        $this->wsAdmin('pwg.history.log', ['image_id' => 1, 'section' => 'tags', 'tags_string' => '1']);

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

    public function test_historySearch_counts_a_guest_visit_in_the_summary(): void
    {
        $this->enableHistoryForGuest();
        $guestResponse = $this->ws('pwg.history.log', ['image_id' => 1]);
        self::assertSame('ok', $guestResponse['stat']);

        $response = $this->wsAdmin('pwg.history.search');

        $result = $response['result'];
        self::assertIsArray($result);
        $summary = $result['summary'];
        self::assertIsArray($summary);
        self::assertIsString($summary['GUESTS']);
        self::assertStringNotContainsString('0 guest', $summary['GUESTS']);
    }

    public function test_historySearch_computes_the_total_filesize_of_high_type_images(): void
    {
        $this->enableHistoryForAdmin();
        $filesize = $this->conn->fetchOne('SELECT filesize FROM ' . Tables::images() . ' WHERE id = 1');
        self::assertIsNumeric($filesize);

        // historyLog() with is_download=true records image_type='high'
        // (see test_historyLog_records_a_visit_and_increments_the_hit_counter
        // above) -- the only image_type that feeds the FILESIZE summary.
        $this->wsAdmin('pwg.history.log', ['image_id' => 1, 'is_download' => true]);

        $response = $this->wsAdmin('pwg.history.search', ['image_id' => 1]);

        $result = $response['result'];
        self::assertIsArray($result);
        $summary = $result['summary'];
        self::assertIsArray($summary);
        self::assertIsNumeric($summary['FILESIZE']);
        self::assertSame((int) ceil((int) $filesize / 1024), (int) $summary['FILESIZE']);
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
        $ch  = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        assert($cookieJar !== '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'method=pwg.history.search&' . $rawPostFields);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertIsString($body);
        self::assertSame(500, $status);
        self::assertStringContainsString('[Hacking attempt]', $body);
        self::assertStringContainsString('"' . $inputParamName . '"', $body);
    }

    public function test_historySearch_invalid_types_returns_error(): void
    {
        $this->assertHistorySearchIsAHackingAttempt('types', 'types%5B%5D=not-a-real-type');
    }

    public function test_historySearch_invalid_date_format_returns_error(): void
    {
        $this->assertHistorySearchIsAHackingAttempt('start', 'start=not-a-date');
    }

    public function test_historySearch_invalid_display_thumbnail_returns_error(): void
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
    public function test_activity_downloadLog_fatals_on_its_own_pre_existing_undefined_callback(): void
    {
        $this->loginAsAdmin();
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        assert($cookieJar !== '');
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
