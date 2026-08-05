<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use JsonSchema\Validator;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Base for WS API contract tests.
 *
 * Loads the fixture once per test process (static flag shared across all
 * subclasses). Each test gets its own cookie jar so login state is isolated.
 *
 * Two entry points:
 *   ws()      — anonymous call (guest session)
 *   wsAdmin() — auto-logins as fixture_admin before the call
 *
 * assertMatchesSchema() validates the decoded response against a JSON Schema
 * file in tests/Contract/schemas/<name>.json using justinrainbow/json-schema.
 */
abstract class ContractTestCase extends IntegrationTestCase
{
    /**
     * Some legacy code paths (e.g. comment posting) read
     * $_SERVER['HTTP_USER_AGENT'] unguarded — real HTTP clients always send
     * one, so the test client does too rather than special-casing curl.
     */
    protected const string USER_AGENT = 'PiwigoContractTests/1.0';

    private static bool $fixtureReady = false;

    private string $cookieJar = '';

    #[Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->requireBaseUrl();

        if (!self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            $this->markTestInstalled();
            self::$fixtureReady = true;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pwg_ct_');
        self::assertIsString($tmp);
        $this->cookieJar = $tmp;
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->cookieJar !== '' && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    /**
     * Returns the path to the per-test cookie jar (for raw curl calls).
     * @return non-empty-string
     */
    protected function cookieJar(): string
    {
        // setUp() always populates this from tempnam() before any test body
        // runs
        assert($this->cookieJar !== '');
        return $this->cookieJar;
    }

    /**
     * Anonymous WS call (guest).
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function ws(string $method, array $params = []): array
    {
        return $this->callWs($method, $params);
    }

    /** Establishes an admin session on the current cookie jar via pwg.session.login. */
    protected function loginAsAdmin(): void
    {
        $this->callWs('pwg.session.login', [
            'username' => 'fixture_admin',
            'password' => 'fixture_admin',
        ]);
    }

    /**
     * Establishes an admin session by POSTing to identification.php.
     * This sets $_SESSION['connected_with'] = 'pwg_ui', which is required by
     * methods that call ApiKeyService::connectedWithPwgUi() (e.g. pwg.users.api_key.*).
     *
     * The page requires an existing session cookie before it will accept a POST,
     * so we GET it first to seed the cookie jar, then POST the credentials.
     */
    protected function loginAsAdminViaUI(): void
    {
        $url = $this->baseUrl . '/identification.php';

        // Step 1: GET the page so PHP starts a session and sets the cookie.
        $ch = curl_init($url);
        self::assertNotFalse($ch, 'curl_init failed');
        $userAgent = self::USER_AGENT;
        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        curl_exec($ch);
        unset($ch);

        // Step 2: POST credentials. identification.php checks $_POST['login']
        // and requires the session cookie established in step 1.
        $ch = curl_init($url);
        self::assertNotFalse($ch, 'curl_init failed');
        $userAgent = self::USER_AGENT;
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'login'    => '1',
            'username' => 'fixture_admin',
            'password' => 'fixture_admin',
        ]));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertIsString($body, 'identification.php returned no body');
        self::assertSame(302, $status, sprintf('UI login failed — expected redirect, got HTTP %d: %s', $status, $body));
    }

    /**
     * WS call authenticated as fixture_admin.
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function wsAdmin(string $method, array $params = []): array
    {
        $this->loginAsAdmin();

        return $this->callWs($method, $params);
    }

    /**
     * Returns the pwg_token for the current session.
     * Must be called after loginAsAdmin() or wsAdmin().
     */
    protected function getPwgToken(): string
    {
        $status = $this->callWs('pwg.session.getStatus', []);
        $result = $status['result'] ?? null;
        if (!is_array($result)) {
            return '';
        }

        $token = $result['pwg_token'] ?? '';
        return is_string($token) ? $token : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function assertMatchesSchema(string $schemaName, array $data): void
    {
        $path = __DIR__ . '/schemas/' . $schemaName . '.json';
        self::assertFileExists($path, 'Schema file missing: ' . $path);

        $schema = json_decode((string) file_get_contents($path));
        self::assertIsObject($schema, sprintf('Schema %s is not valid JSON', $schemaName));

        // justinrainbow/json-schema requires stdClass, not array
        $subject = json_decode((string) json_encode($data));

        $validator = new Validator();
        $validator->validate($subject, $schema);

        if (!$validator->isValid()) {
            /**
             * justinrainbow/json-schema's BaseConstraint::getErrors() is
             * declared to return plain `array`, but every element is built
             * by addError() with this exact shape (see
             * vendor/justinrainbow/json-schema/src/JsonSchema/Constraints/BaseConstraint.php).
             * @var list<array{property: string, pointer: string, message: string, constraint: array{name: string, params: array<string, mixed>}, context: int}> $errors
             */
            $errors = $validator->getErrors();
            $lines  = array_map(
                static fn (array $e): string => sprintf('  [%s] %s', $e['property'], $e['message']),
                $errors
            );
            self::fail(
                "Response does not match schema '{$schemaName}':\n" . implode("\n", $lines)
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function callWs(string $method, array $params): array
    {
        $url = $this->baseUrl . '/ws.php?format=json';

        $ch = curl_init($url);
        self::assertNotFalse($ch, 'curl_init failed');

        $userAgent = self::USER_AGENT;
        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['method' => $method], $params)));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // curl_close() is deprecated in PHP 8.4+

        self::assertIsString($body, sprintf('WS call to %s returned no body', $method));
        self::assertLessThan(500, $status, sprintf('WS %s returned server error HTTP %d: %s', $method, $status, $body));

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, sprintf('WS %s response is not valid JSON (HTTP %d): %s', $method, $status, $body));

        // ws.php's JSON envelope is always a JSON object (stat/result/err
        // keys), never a JSON array, so the decoded top level is always
        // string-keyed.
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Inserts (or updates, if already present) a single `config` row --
     * every Contract test file that seeds a config flag before exercising
     * a WS method needs exactly this upsert shape.
     *
     * pgsql support pass: real bug found live -- `ON DUPLICATE KEY UPDATE`
     * is MySQL-only syntax ("syntax error at or near 'DUPLICATE'"),
     * repeated identically (raw SQL, independently) across 11 different
     * Contract test files. Consolidated into this one shared, portable
     * helper rather than fixing each site's own copy separately -- a
     * genuine DRY fix for an already-duplicated pattern, not a workaround
     * papering over a bug (the MySQL branch is byte-identical to what
     * every site already had). Postgres's portable equivalent is
     * `ON CONFLICT (param) DO UPDATE SET ...` (`param` is config's own
     * primary key).
     */
    protected function upsertConfig(string $param, string $value): void
    {
        $conn = DbConnection::build();
        $onConflict = $this->dbDriver === 'pgsql'
            ? 'ON CONFLICT (param) DO UPDATE SET value = EXCLUDED.value'
            : 'ON DUPLICATE KEY UPDATE value = VALUES(value)';
        $conn->executeStatement(
            'INSERT INTO ' . Tables::config() . " (param, value) VALUES (?, ?) {$onConflict}",
            [$param, $value]
        );
    }

    /**
     * Same as callWs(), but without the HTTP-status sanity check --
     * PwgError's constructor mirrors any WS err code >= 400 onto the real
     * HTTP response status (HtmlService::setStatusHeader()), so a
     * deliberately-triggered WS error >= 500 (e.g. WsError::INVALID_METHOD
     * = 501, or the many business-rule `new PwgError(500, ...)` returns
     * across the Ws\Pwg* classes) is a real, correct HTTP 500/501 response,
     * not the server-crashed condition callWs()'s guard exists to catch.
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function callWsAllowingServerError(string $method, array $params): array
    {
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['method' => $method], $params)));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
