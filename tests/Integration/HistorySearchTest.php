<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

/**
 * Snapshot tests for `pwg.history.search` — locks down summary numbers
 * and pagination behavior of `Piwigo\Ws\Method\GeneralEndpoints::historySearch`
 * before the §1.1 LIMIT/OFFSET refactor (ROADMAP.md §1.1). The refactor
 * splits the in-PHP aggregate loop into 6 dedicated SQL queries; these
 * tests assert the externally observable summary block stays identical.
 */
final class HistorySearchTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../dev/fixtures/piwigo-16.x.sql';

    /** @var non-empty-string */
    private string $cookieJar = '/tmp/piwigo_history_search_default.txt';

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->requireBaseUrl();
        $this->cookieJar = sys_get_temp_dir() . '/piwigo_history_search_' . (int) getmypid() . '.txt';
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->markTestInstalled();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    // ---- Tests ---------------------------------------------------------------

    public function test_history_search_summary_numbers_are_stable(): void
    {
        $this->seedSummarySnapshot();
        $this->loginAsAdmin();

        $data = $this->apiPost('pwg.history.search', [
            'start' => '2026-01-01',
            'end'   => '2026-01-01',
        ]);
        self::assertSame('ok', $data['stat']);
        $result = $data['result'];
        self::assertIsArray($result);

        $lines = $result['lines'] ?? [];
        self::assertIsArray($lines);
        self::assertCount(12, $lines, 'all 12 seeded rows must appear on page 0 (default page size 300)');

        self::assertSame(1, $result['maxPage'], 'maxPage = ceil(12 / 300) = 1');

        $summary = $result['summary'] ?? [];
        self::assertIsArray($summary);

        // FILESIZE = ceil(total_filesize_in_KiB / 1024). Seeded high rows:
        // image 1 (1024) + image 2 (2048) + image 4 (8192) + image 5 (16384)
        // + image 5 (16384) + image 999 (missing, contributes 0) = 44032 KiB.
        // ceil(44032 / 1024) = 43.
        self::assertSame(43, $summary['FILESIZE']);

        // Plural strings vary by locale; assert the substituted count.
        self::assertIsString($summary['NB_LINES']);
        self::assertStringContainsString('12', $summary['NB_LINES']);
        self::assertIsString($summary['GUESTS']);
        self::assertStringContainsString('3', $summary['GUESTS']);
        self::assertIsString($summary['USERS']);
        self::assertStringContainsString('6', $summary['USERS'], 'USERS = nb_members (3) + nb_guests (3)');

        // MEMBERS is a list of single-entry [username => userId] maps,
        // one per non-guest user that appeared in the filtered set.
        $members = $summary['MEMBERS'] ?? [];
        self::assertIsArray($members);
        self::assertCount(3, $members, 'admin + regular_user + power_user');

        // SORTED_MEMBERS is an associative map keyed by display username,
        // value = hit count, with 'guest' explicitly unset.
        $sorted = $summary['SORTED_MEMBERS'] ?? [];
        self::assertIsArray($sorted);
        self::assertArrayNotHasKey('guest', $sorted);
        self::assertSame(3, count($sorted));
        self::assertContains('fixture_admin', array_keys($sorted));
        self::assertContains('regular_user', array_keys($sorted));
        self::assertContains('power_user', array_keys($sorted));
    }

    public function test_history_search_descending_date_time_order(): void
    {
        $this->seedSummarySnapshot();
        $this->loginAsAdmin();

        $data = $this->apiPost('pwg.history.search', [
            'start' => '2026-01-01',
            'end'   => '2026-01-01',
        ]);
        self::assertSame('ok', $data['stat']);
        $result = $data['result'];
        self::assertIsArray($result);
        $lines = $result['lines'] ?? [];
        self::assertIsArray($lines);
        self::assertCount(12, $lines);

        // All seeds are on the same date with distinct ascending TIME values
        // (09:00:00 .. 09:11:00). Descending order means lines[0]['TIME'] is
        // the latest seed and lines[count-1]['TIME'] is the earliest.
        $times = [];
        foreach ($lines as $line) {
            self::assertIsArray($line);
            self::assertIsString($line['TIME']);
            $times[] = $line['TIME'];
        }
        $sorted = $times;
        rsort($sorted, SORT_STRING);
        self::assertSame($sorted, $times, 'lines must be sorted by (date, time) DESC');
        self::assertSame('09:11:00', $times[0]);
        self::assertSame('09:00:00', $times[count($times) - 1]);
    }

    public function test_history_search_pagination_returns_distinct_pages(): void
    {
        // Page size is hardcoded to 300 in current historySearch (lines 746
        // and 749). Seed 305 rows so page 0 has 300 and page 1 has 5.
        $this->seedPaginationDataset(305);
        $this->loginAsAdmin();

        $page0 = $this->apiPost('pwg.history.search', ['pageNumber' => 0]);
        self::assertSame('ok', $page0['stat']);
        $r0 = $page0['result'];
        self::assertIsArray($r0);
        self::assertIsArray($r0['lines']);
        self::assertCount(300, $r0['lines']);
        self::assertSame(2, $r0['maxPage']);

        $page1 = $this->apiPost('pwg.history.search', ['pageNumber' => 1]);
        self::assertSame('ok', $page1['stat']);
        $r1 = $page1['result'];
        self::assertIsArray($r1);
        self::assertIsArray($r1['lines']);
        self::assertCount(5, $r1['lines']);
        self::assertSame(2, $r1['maxPage']);

        // Summary numbers are computed across the whole filtered set, so
        // they must match between pages.
        $s0 = $r0['summary'];
        $s1 = $r1['summary'];
        self::assertIsArray($s0);
        self::assertIsArray($s1);
        self::assertSame($s0['NB_LINES'], $s1['NB_LINES']);
        self::assertSame($s0['FILESIZE'], $s1['FILESIZE']);
        self::assertSame($s0['USERS'], $s1['USERS']);

        // Distinct seed times mean no overlap in TIME values between pages.
        $extractTime = static function (mixed $l): string {
            return is_array($l) && is_string($l['TIME'] ?? null) ? $l['TIME'] : '';
        };
        $times0 = array_map($extractTime, $r0['lines']);
        $times1 = array_map($extractTime, $r1['lines']);
        self::assertSame([], array_intersect($times0, $times1), 'page 0 and page 1 must not share rows');
    }

    // ---- Seeders -------------------------------------------------------------

    /**
     * 12-row deterministic snapshot covering: 4 distinct user_ids
     * (admin/guest/regular/power), 3 distinct guest IPs, mixed image_type
     * including 'high' rows whose image_id either resolves (filesize
     * contributes) or doesn't (one row points at deleted image_id 999).
     * All rows on 2026-01-01 with distinct ascending times.
     */
    private function seedSummarySnapshot(): void
    {
        $db = $this->newMysqliBound();
        // Set deterministic filesize for fixture images 1..5.
        self::assertTrue($db->query(
            'UPDATE piwigo_images SET filesize = CASE id'
            . " WHEN 1 THEN 1024"
            . " WHEN 2 THEN 2048"
            . " WHEN 3 THEN 4096"
            . " WHEN 4 THEN 8192"
            . " WHEN 5 THEN 16384"
            . ' END WHERE id BETWEEN 1 AND 5'
        ));
        // 12 rows. Columns: date, time, user_id, IP, section, category_id,
        // search_id, tag_ids, image_id, image_type.
        $rows = [
            ['09:00:00', 1, '10.0.0.1',     'NULL',          1,    "'picture'"],
            ['09:01:00', 2, '10.0.0.10',    'NULL',          1,    "'high'"],
            ['09:02:00', 2, '10.0.0.11',    'NULL',          2,    "'high'"],
            ['09:03:00', 2, '10.0.0.10',    'NULL',          3,    "'other'"],
            ['09:04:00', 3, '192.168.1.10', 'NULL',          2,    "'picture'"],
            ['09:05:00', 4, '192.168.1.20', 'NULL',          4,    "'high'"],
            ['09:06:00', 4, '192.168.1.20', 'NULL',          5,    "'high'"],
            ['09:07:00', 2, '10.0.0.12',    "'categories'",  'NULL', 'NULL'],
            ['09:08:00', 3, '192.168.1.10', 'NULL',          4,    "'picture'"],
            ['09:09:00', 1, '10.0.0.1',     "'categories'",  'NULL', 'NULL'],
            ['09:10:00', 4, '192.168.1.21', 'NULL',          5,    "'high'"],
            ['09:11:00', 2, '10.0.0.10',    'NULL',          999,  "'high'"],
        ];
        $values = [];
        foreach ($rows as $r) {
            [$time, $userId, $ip, $section, $imageId, $imageType] = $r;
            $values[] = sprintf(
                "('2026-01-01','%s',%d,'%s',%s,NULL,NULL,NULL,%s,%s)",
                $time,
                $userId,
                $ip,
                $section,
                $imageId === 'NULL' ? 'NULL' : (string) $imageId,
                $imageType
            );
        }
        $sql = 'INSERT INTO piwigo_history (date,time,user_id,IP,section,category_id,search_id,tag_ids,image_id,image_type) VALUES '
             . implode(',', $values);
        self::assertTrue($db->query($sql), 'history seed insert failed: ' . $db->error);
        $db->close();
    }

    /**
     * Seeds N admin-user rows on 2026-02-01 with distinct ascending times
     * — used to exercise pagination boundaries (300-row hardcoded page).
     */
    private function seedPaginationDataset(int $count): void
    {
        $db = $this->newMysqliBound();
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $h = sprintf('%02d', intdiv($i, 3600) % 24);
            $m = sprintf('%02d', intdiv($i, 60) % 60);
            $s = sprintf('%02d', $i % 60);
            $values[] = "('2026-02-01','{$h}:{$m}:{$s}',1,'10.0.0.1',NULL,NULL,NULL,NULL,NULL,'picture')";
        }
        $sql = 'INSERT INTO piwigo_history (date,time,user_id,IP,section,category_id,search_id,tag_ids,image_id,image_type) VALUES '
             . implode(',', $values);
        self::assertTrue($db->query($sql), 'pagination seed insert failed: ' . $db->error);
        $db->close();
    }

    private function newMysqliBound(): \mysqli
    {
        if (str_starts_with($this->dbHost, '/')) {
            return new \mysqli('localhost', $this->dbUser, $this->dbPass, $this->dbName, 0, $this->dbHost);
        }
        return new \mysqli($this->dbHost, $this->dbUser, $this->dbPass, $this->dbName, $this->dbPort);
    }

    // ---- HTTP helpers (mirror WsApiTest pattern) -----------------------------

    private function loginAsAdmin(): void
    {
        $data = $this->apiPost('pwg.session.login', [
            'username' => 'fixture_admin',
            'password' => 'fixture_admin',
        ]);
        self::assertSame('ok', $data['stat'], 'admin login failed during test setup');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<mixed>
     */
    private function apiPost(string $method, array $params = []): array
    {
        $url = $this->baseUrl . '/index.php?/ws&format=json';
        $params['method'] = $method;
        $chRaw = curl_init($url);
        self::assertNotFalse($chRaw, 'curl_init failed');
        $ch = $chRaw;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_HTTPHEADER     => self::TEST_HEADER,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
        ]);
        $execResult = curl_exec($ch);
        $body = is_string($execResult) ? $execResult : '';
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        self::assertSame(200, $status, "Expected HTTP 200 from $url");
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, "Expected JSON response from $url, got: " . substr($body, 0, 200));
        /** @var array<mixed> $decoded */
        return $decoded;
    }
}
