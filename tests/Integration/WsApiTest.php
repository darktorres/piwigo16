<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

/**
 * Integration tests for the Piwigo web service API.
 *
 * Requires the local Apache instance at PIWIGO_BASE_URL (set via .env.local)
 * to be running. Each test resets PIWIGO_DB_BASE and loads the 16.x fixture
 * before exercising the WS endpoints.
 */
final class WsApiTest extends IntegrationTestCase
{
    private const FIXTURE = __DIR__ . '/../../dev/fixtures/piwigo-16.x.sql';

    private string $cookieJar;

    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->cookieJar = sys_get_temp_dir() . '/piwigo_ws_test_' . getmypid() . '.txt';
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->writeDatabaseConfig();
    }

    protected function tearDown(): void
    {
        $this->removeDatabaseConfig();
        if (file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    // ---- Tests ---------------------------------------------------------------

    public function test_get_version_returns_ok(): void
    {
        $data = $this->apiGet('pwg.getVersion');
        self::assertSame('ok', $data['stat'], 'pwg.getVersion must return stat=ok');
        self::assertMatchesRegularExpression('/^\d+\.\d+/', (string) $data['result']);
    }

    public function test_session_login_with_valid_credentials(): void
    {
        $data = $this->apiPost('pwg.session.login', [
            'username' => 'fixture_admin',
            'password' => 'fixture_admin',
        ]);
        self::assertSame('ok', $data['stat'], 'login must succeed with fixture credentials');
        self::assertTrue($data['result'] ?? false, 'login result must be truthy');
    }

    public function test_session_get_status_after_login(): void
    {
        $this->loginAsAdmin();
        $data = $this->apiGet('pwg.session.getStatus');
        self::assertSame('ok', $data['stat']);
        self::assertSame('fixture_admin', $data['result']['username'] ?? null);
    }

    public function test_categories_get_list_returns_albums(): void
    {
        $data = $this->apiGet('pwg.categories.getList');
        self::assertSame('ok', $data['stat']);
        $cats = $data['result']['categories']['_content'] ?? $data['result']['categories'] ?? [];
        self::assertIsArray($cats);
        self::assertGreaterThan(0, count($cats), 'fixture must have at least one album');
    }

    public function test_tags_get_list_returns_tags(): void
    {
        $data = $this->apiGet('pwg.tags.getList');
        self::assertSame('ok', $data['stat']);
        $tags = $data['result']['tags']['_content'] ?? $data['result']['tags'] ?? [];
        self::assertIsArray($tags);
        self::assertGreaterThan(0, count($tags), 'fixture must have at least one tag');
    }

    public function test_images_search_returns_results(): void
    {
        $data = $this->apiGet('pwg.images.search', ['query' => 'a', 'per_page' => 100]);
        self::assertSame('ok', $data['stat']);
        $images = $data['result']['images']['_content'] ?? $data['result']['images'] ?? [];
        self::assertIsArray($images);
    }

    public function test_admin_only_users_list_requires_auth(): void
    {
        // Piwigo may return HTTP 401 or JSON stat='fail' for unauthenticated admin calls.
        $url = $this->baseUrl . '/ws.php?method=pwg.users.getList&format=json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true]);
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        $decoded = json_decode($body, true);
        $stat = is_array($decoded) ? ($decoded['stat'] ?? null) : null;
        self::assertTrue(
            $status === 401 || $stat === 'fail',
            "users.getList must require authentication (HTTP $status, stat=" . ($stat ?? 'N/A') . ")"
        );
    }

    public function test_users_list_accessible_as_admin(): void
    {
        $this->loginAsAdmin();
        $data = $this->apiGet('pwg.users.getList');
        self::assertSame('ok', $data['stat']);
        $users = $data['result']['users']['_content'] ?? $data['result']['users'] ?? [];
        self::assertIsArray($users);
        $usernames = array_column($users, 'username');
        self::assertContains('fixture_admin', $usernames, 'admin user must appear in list');
    }

    public function test_category_add_and_delete_lifecycle(): void
    {
        $this->loginAsAdmin();

        $create = $this->apiPost('pwg.categories.add', ['name' => 'WS API Test Album']);
        self::assertSame('ok', $create['stat']);
        $id = (int) ($create['result']['id'] ?? 0);
        self::assertGreaterThan(0, $id);

        $list = $this->apiGet('pwg.categories.getAdminList');
        self::assertSame('ok', $list['stat']);

        $delete = $this->apiPost('pwg.categories.delete', [
            'category_id' => $id,
            'photo_deletion_mode' => 'delete_orphans',
        ]);
        self::assertSame('ok', $delete['stat']);
    }

    // ---- Helpers ------------------------------------------------------------

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
    private function apiGet(string $method, array $params = []): array
    {
        $params['method'] = $method;
        $params['format'] = 'json';
        $url = $this->baseUrl . '/ws.php?' . http_build_query($params);
        return $this->curlRequest($url, false, []);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<mixed>
     */
    private function apiPost(string $method, array $params = []): array
    {
        $url = $this->baseUrl . '/ws.php?format=json';
        $params['method'] = $method;
        return $this->curlRequest($url, true, $params);
    }

    /**
     * @param array<string,mixed> $postFields
     * @return array<mixed>
     */
    private function curlRequest(string $url, bool $post, array $postFields): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
        ];
        if ($post) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
        }
        curl_setopt_array($ch, $opts);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertSame(200, $status, "Expected HTTP 200 from $url");
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, "Expected JSON response from $url, got: " . substr($body, 0, 200));
        /** @var array<mixed> $decoded */
        return $decoded;
    }
}
