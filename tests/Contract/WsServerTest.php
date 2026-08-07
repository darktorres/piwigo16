<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Cache\CachePools;
use Doctrine\DBAL\Connection;
use Piwigo\Core\WsError;
use Piwigo\Core\WsParamType;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

/**
 * Ws\PwgServer -- the generic WS dispatcher itself (invoke()'s own gates,
 * checkType()'s scalar bool/float branches, the reflection.* methods,
 * isAuthorizedMethodForAPIKEY()), reached through ws.php rather than any
 * one pwg.* domain method's own test file.
 *
 * checkType()'s array-of-bool/array-of-float branches are genuinely
 * unreachable through any real WS request: no real WS method registration
 * in WsDefaultMethods.php combines WsParamFlag::ACCEPT_ARRAY/FORCE_ARRAY
 * with WsParamType::BOOL or WsParamType::FLOAT (confirmed via a full grep
 * of that file). checkType() is still a real public static method of the
 * class though, so test_checkType_accepts_an_array_of_booleans() and its
 * siblings below call it directly with genuine array inputs instead of
 * going through the (nonexistent) WS route -- closes real line coverage
 * without pretending a fake WS method registration exists.
 *
 * run()'s own "no request handler" branch (`! $this->_requestHandler
 * instanceof PwgRequestHandler`) is similarly unreachable in practice
 * through ws.php: WsInitializer::init() hardcodes $requestFormat = 'rest'
 * and always constructs a PwgRestRequestHandler for it, so
 * _requestHandler is never null by the time run() checks it through the
 * real ws.php entry point. test_run_without_a_request_handler_returns_unknown_request_format()
 * below reaches it by constructing a real PwgServer directly and calling
 * setEncoder() without ever calling setHandler() -- a real object in a
 * state WsInitializer itself never produces, not a mock of the class
 * under test.
 *
 * Ws\WsInitializer::init()'s own `case 'xmlrpc': ... break;` (its
 * response-encoder switch) is already exercised for real by
 * WsAlternateFormatsTest's test_format_xmlrpc_encodes_a_successful_response()
 * -- its trailing `break;` (the last case in the switch, nothing but the
 * closing `}` after it) is provably eliminated by OPcache's real
 * jump-elision optimizer on the live Apache-served process this suite
 * runs against, same root cause (and same live PCOV-based confirmation
 * method) as this project's own documented "OPcache constant-array-folding
 * coverage artifact" precedent; not a gap in test coverage. See
 * WsCommentsTest's own class docblock for the full writeup.
 *
 * Ws\WsInitializer::init()'s own memoization early-return (`if (self::$server
 * instanceof PwgServer) { return self::$server; }`) needs init() to run
 * twice inside one real request -- see test_init_reuses_the_memoized_server_below.
 */
final class WsServerTest extends ContractTestCase
{
    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    /**
     * Reached through the real ws.php entry point: WsFormatRequest::
     * fromArray() leaves the encoder null for any `?format=` value
     * WsInitializer::init()'s own switch doesn't recognize
     * (rest/php/json/xmlrpc), so PwgServer::setEncoder() genuinely
     * receives a null encoder for a malformed real request -- not a
     * synthetic construction. run() calls die(0) in this branch, so this
     * uses a raw curl call against the live server rather than an
     * in-process call (which would terminate the test runner itself).
     */
    public function test_run_with_an_unrecognized_response_format_reports_the_error_and_stops(): void
    {
        $url = $this->baseUrl . '/ws.php?format=not-a-real-format&' . http_build_query([
            'method' => 'pwg.getVersion',
        ]);
        $ch = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertIsString($body);
        self::assertSame(400, $status);
        self::assertStringContainsString('Cannot process your request. Unknown response format.', $body);
        self::assertStringContainsString('Request format: rest Response format: not-a-real-format', $body);
        // var_export()'s own output of PwgServer's own shallow request/
        // response debug state -- confirms the die(0) branch really ran to
        // completion rather than stopping earlier. Not a full
        // var_export($this): PwgServer's own DI-injected collaborators
        // (accessControl's own chain reaches HtmlService/MailService/
        // UrlService and every one of their own collaborators) would make
        // a full var_export($this) exhaust the request's memory limit.
        self::assertStringContainsString("'_requestFormat' => 'rest'", $body);
        self::assertStringContainsString("'_responseFormat' => 'not-a-real-format'", $body);
        self::assertStringContainsString("'_methods' => \n  array (\n  )", $body);
    }

    /**
     * See this file's own class docblock: unreachable through the real
     * ws.php entry point (WsInitializer::init() always calls setHandler()
     * before setEncoder()), so this resolves a real PwgServer from the
     * container directly and calls setEncoder() without ever calling
     * setHandler() -- genuinely exercises run()'s own guard with a real
     * request-handler-less object, not a mock of the class under test. No
     * HTTP call needed: unlike the "unknown response format" branch above,
     * this one returns instead of calling die(), so it's safe to invoke
     * in-process.
     *
     * PwgError's own constructor mirrors a >= 400 code onto a real HTTP
     * status via PresentationAccessor::htmlService(), which needs
     * Kernel::boot() -- every other test in this file reaches that
     * through the live Apache process's own bootstrap instead, but this
     * one calls run() directly in the PHPUnit CLI process, so it
     * boots/resets the Kernel locally, matching
     * Integration\ContainerSmokeTest's own boot()-then-reset() pattern. A
     * real Paths is required, not a bare boot: PwgServer constructor-
     * injects AccessControl, whose own chain
     * (-> RedirectService -> Lang) resolves Paths, whose value the
     * container can't guess without one -- same rationale as
     * WsTopLevelTest::test_getMissingDerivatives_with_an_empty_gallery_returns_an_empty_array_early()'s
     * own identical fix.
     */
    public function test_run_without_a_request_handler_returns_unknown_request_format(): void
    {
        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        $body = false;
        try {
            $server = Kernel::container()->get(PwgServer::class);
            self::assertInstanceOf(PwgServer::class, $server);
            $encoder = new PwgJsonEncoder();
            $server->setEncoder('json', $encoder);

            ob_start();
            try {
                $server->run();
            } finally {
                $body = ob_get_clean();
            }
        } finally {
            Kernel::reset();
        }

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('fail', $decoded['stat']);
        self::assertSame(400, $decoded['err']);
        self::assertSame('Unknown request format', $decoded['message']);
    }

    /**
     * WsInitializer::init()'s own memoization branch. Bootstrap\
     * UserBootstrap's own `pwg.images.uploadAsync` username/password
     * credential branch (confirmed live via reading its source) calls
     * WsInitializer::init() once to build a PwgServer for
     * PwgCore::sessionLogin() -- ahead of the normal WsController::
     * __invoke()'s own init() call for the real method dispatch, in the
     * *same* request -- the only real route where init() runs twice per
     * process. A fresh, never-logged-in cookie jar (this test's own,
     * created by setUp()) means the credential branch's own `username !==
     * null and password !== null` gate is what triggers this, not
     * pre-existing session state.
     *
     * uploadAsync's own registered params (chunk/chunk_sum/chunks/
     * original_sum/filename) are deliberately not sent -- the credential
     * branch runs during bootstrap, entirely before PwgServer::invoke()'s
     * own per-method param validation, so a "Missing parameters: ..."
     * fail response from the *second* init()'d PwgServer is a perfectly
     * fine, non-500 outcome; only the credential login itself needs to
     * succeed for real.
     */
    public function test_init_reuses_the_memoized_server_when_called_twice_in_the_same_request(): void
    {
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);
        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'method' => 'pwg.images.uploadAsync',
            'username' => 'fixture_admin',
            'password' => 'fixture_admin',
        ]));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertIsString($body);
        self::assertLessThan(500, $status, sprintf('uploadAsync credential request returned HTTP %d: %s', $status, $body));

        // If UserBootstrap's own uploadAsync-credentials branch hadn't
        // logged in for real (the first of the 2 real
        // WsInitializer::init() calls this request makes), $_SESSION
        // ['connected_with'] would never have been set to
        // 'pwg.images.uploadAsync', and this follow-up call (same cookie
        // jar) would see a guest session instead.
        $status2 = $this->callWs('pwg.session.getStatus', []);
        self::assertSame('ok', $status2['stat']);
        $result = $status2['result'];
        self::assertIsArray($result);
        self::assertSame('pwg.images.uploadAsync', $result['connected_with']);
    }

    public function test_invoke_with_an_unknown_method_name_returns_invalid_method(): void
    {
        // PwgError's constructor mirrors this WS err (501) onto the real
        // HTTP status -- callWs()'s generic "< 500" sanity guard would
        // wrongly reject this well-formed 501, so this uses the
        // guard-free variant instead (see its own docblock).
        $response = $this->callWsAllowingServerError('pwg.not.a.real.method', []);

        self::assertSame('fail', $response['stat']);
        self::assertSame(501, $response['err']);
        self::assertSame('Method name is not valid', $response['message']);
    }

    public function test_invoke_a_post_only_method_via_get_returns_405(): void
    {
        $url = $this->baseUrl . '/ws.php?format=json&' . http_build_query([
            'method' => 'pwg.images.addComment',
            'image_id' => 1,
            'author' => 'x',
            'content' => 'y',
            'key' => 'z',
        ]);
        $ch = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertIsString($body);
        self::assertSame(405, $status);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('fail', $decoded['stat']);
        self::assertSame(405, $decoded['err']);
        self::assertSame('This method requires HTTP POST', $decoded['message']);
    }

    /**
     * invoke()'s "parameter provided but empty" branch: pwg.images.rate's
     * 'rate' param has no WsParamFlag::OPTIONAL, so an explicitly-sent but
     * empty value is treated the same as a missing one.
     */
    public function test_invoke_with_a_required_param_sent_empty_returns_missing_param(): void
    {
        $response = $this->ws('pwg.images.rate', [
            'image_id' => 1,
            'rate' => '',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1002, $response['err']);
        self::assertSame('Missing parameters: rate', $response['message']);
    }

    /**
     * invoke()'s own "must be scalar" gate: pwg.images.rate's 'rate' param
     * has no WsParamFlag::ACCEPT_ARRAY/FORCE_ARRAY, so an array value is
     * rejected by invoke() itself, before checkType() (and its own
     * scalar-only float coercion) ever runs.
     */
    public function test_invoke_with_an_array_value_for_a_scalar_only_param_returns_invalid_param(): void
    {
        $response = $this->ws('pwg.images.rate', [
            'image_id' => 1,
            'rate' => [3, 4],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('rate must be scalar', $response['message']);
    }

    public function test_invoke_when_guest_access_is_disabled_returns_access_denied_for_a_non_session_method(): void
    {
        $this->upsertConfig('guest_access', 'false');
        CachePools::config()->clear();

        try {
            $response = $this->ws('pwg.getVersion');

            self::assertSame('fail', $response['stat']);
            self::assertSame(401, $response['err']);
            self::assertSame('Access denied', $response['message']);

            // 'pwg.session.*' methods are explicitly exempted by
            // WsHelper::isInvokeAllowed() -- still reachable even with
            // guest access disabled site-wide.
            $status = $this->ws('pwg.session.getStatus');
            self::assertSame('ok', $status['stat']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'guest_access'");
            CachePools::config()->clear();
        }
    }

    /**
     * WsController::__invoke()'s own gate, reached before WsInitializer::
     * init()/PwgServer::run() ever construct a request handler -- this is
     * genuinely different from the guest_access-disabled case above (that
     * one is a normal WS JSON 'fail'/401 envelope produced from inside
     * PwgServer::invoke()). Here, HtmlService::pageForbidden() throws
     * ResponseReadyException with a real HTML 403 body before any WS
     * dispatch happens at all, so a plain callWs()/ws() JSON-decode
     * wouldn't apply -- this uses a raw curl call and inspects the HTTP
     * status/body directly, matching test_invoke_a_post_only_method_via_get_returns_405()'s
     * own style above.
     */
    public function test_invoke_when_web_services_are_disabled_returns_a_forbidden_page(): void
    {
        $this->upsertConfig('allow_web_services', 'false');
        CachePools::config()->clear();

        try {
            $url = $this->baseUrl . '/ws.php?format=json&' . http_build_query([
                'method' => 'pwg.getVersion',
            ]);
            $ch = curl_init($url);
            self::assertNotFalse($ch);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);

            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);

            self::assertIsString($body);
            self::assertSame(403, $status);
            self::assertStringContainsString('Web services are disabled', $body);
            self::assertStringContainsString('Forbidden', $body);

            // Confirms this really is HtmlService::pageForbidden()'s HTML
            // redirect page, not the WS JSON envelope -- json_decode()'ing
            // it would not produce the usual stat/err/message shape.
            self::assertNull(json_decode($body, true));
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'allow_web_services'");
            CachePools::config()->clear();
        }
    }

    public function test_checkType_rejects_a_non_boolean_scalar(): void
    {
        // pwg.users.setInfo's 'expand' param is WsParamType::BOOL, no
        // ACCEPT_ARRAY/FORCE_ARRAY -- a plain scalar checkType() call.
        $this->loginAsAdmin();
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.setInfo', [
            'user_id' => 1,
            'expand' => 'banana',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('expand must be a boolean', $response['message']);
    }

    public function test_checkType_rejects_a_non_float_scalar(): void
    {
        $response = $this->ws('pwg.images.rate', [
            'image_id' => 1,
            'rate' => 'not-a-number',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('rate must be a float', $response['message']);
    }

    /**
     * checkType()'s array-of-bool branch -- see this file's own class
     * docblock for why this calls the real public static method directly
     * rather than through a WS request.
     */
    public function test_checkType_accepts_an_array_of_booleans(): void
    {
        $param = ['1', 'false', 'yes'];
        $result = PwgServer::checkType($param, WsParamType::BOOL, 'flags');

        self::assertNull($result);
        self::assertSame([true, false, true], $param);
    }

    public function test_checkType_rejects_an_array_containing_a_non_boolean(): void
    {
        $param = ['1', 'not-a-boolean'];
        $result = PwgServer::checkType($param, WsParamType::BOOL, 'flags');

        self::assertInstanceOf(PwgError::class, $result);
        self::assertSame(WsError::INVALID_PARAM, $result->code());
        self::assertSame('flags must only contain booleans', $result->message());
    }

    /**
     * checkType()'s array-of-float branch. WsParamType::POSITIVE (no
     * NOTNULL) sets $opts['options']['min_range'] = 0, so a valid,
     * non-negative float array passes through unchanged -- this also
     * exercises the `isset($opts['options']['min_range']) and $value <
     * ...` half of the multi-line condition on a successful element
     * (filter_var() succeeds, so PHP must evaluate that second operand of
     * the `or` to determine the overall result), not just the
     * filter_var()-fails half covered by
     * test_checkType_rejects_a_non_float_scalar()'s array-less scalar
     * case above.
     */
    public function test_checkType_accepts_an_array_of_positive_floats(): void
    {
        $param = ['1.5', '0'];
        $result = PwgServer::checkType($param, WsParamType::FLOAT | WsParamType::POSITIVE, 'ratios');

        self::assertNull($result);
        self::assertSame([1.5, 0.0], $param);
    }

    /**
     * Same array-of-float branch as above, but the min_range check itself
     * (not filter_var()'s own parse failure) is what trips the error --
     * the only way to reach the `$value < $opts['options']['min_range']`
     * half of the condition with a *true* result.
     */
    public function test_checkType_rejects_an_array_containing_a_float_below_min_range(): void
    {
        $param = ['1.5', '-3.5'];
        $result = PwgServer::checkType($param, WsParamType::FLOAT | WsParamType::POSITIVE, 'ratios');

        self::assertInstanceOf(PwgError::class, $result);
        self::assertSame(WsError::INVALID_PARAM, $result->code());
        self::assertSame('ratios must only contain positive floats', $result->message());
    }

    public function test_reflection_getMethodList_includes_every_non_hidden_method(): void
    {
        $response = $this->ws('reflection.getMethodList');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $methods = $result['methods'];
        self::assertIsArray($methods);
        self::assertContains('pwg.getVersion', $methods);
        self::assertContains('reflection.getMethodList', $methods);
        self::assertContains('reflection.getMethodDetails', $methods);
        self::assertGreaterThan(50, count($methods));
    }

    public function test_reflection_getMethodDetails_with_an_unknown_method_returns_error(): void
    {
        $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'not.a.real.method']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Requested method does not exist', $response['message']);
    }

    public function test_reflection_getMethodDetails_describes_a_method_with_no_params(): void
    {
        $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'pwg.getVersion']);

        self::assertSame('ok', $response['stat']);
        self::assertSame([
            'name' => 'pwg.getVersion',
            'description' => 'Returns the Piwigo version.',
            'params' => [],
            'options' => [],
        ], $response['result']);
    }

    public function test_reflection_getMethodDetails_describes_every_param_type_flag_combination(): void
    {
        $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'pwg.images.setRank']);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame(['admin_only' => true, 'post_only' => true], $result['options']);
        $params = $result['params'];
        self::assertIsArray($params);
        self::assertSame([
            ['name' => 'image_id', 'optional' => false, 'acceptArray' => true, 'type' => 'int positive notnull'],
            ['name' => 'category_id', 'optional' => false, 'acceptArray' => false, 'type' => 'int positive notnull'],
            ['name' => 'rank', 'optional' => true, 'acceptArray' => false, 'type' => 'int positive notnull'],
        ], $params);
    }

    public function test_reflection_getMethodDetails_describes_defaultValue_maxValue_info_and_float(): void
    {
        $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'pwg.images.search']);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $params = $result['params'];
        self::assertIsArray($params);
        $byName = array_column($params, null, 'name');

        self::assertSame(
            ['name' => 'per_page', 'optional' => true, 'acceptArray' => false, 'type' => 'int positive', 'defaultValue' => 100, 'maxValue' => 500],
            $byName['per_page']
        );
        self::assertSame(
            ['name' => 'order', 'optional' => true, 'acceptArray' => false, 'type' => 'mixed', 'info' => 'id, file, name, hit, rating_score, date_creation, date_available, random'],
            $byName['order']
        );
        self::assertSame(
            ['name' => 'f_min_rate', 'optional' => true, 'acceptArray' => false, 'type' => 'float'],
            $byName['f_min_rate']
        );
    }

    /**
     * ws_getMethodDetails()'s WsParamType::BOOL branch -- not exercised by
     * test_reflection_getMethodDetails_describes_every_param_type_flag_combination()
     * (pwg.images.setRank, all-int params) or
     * ..._defaultValue_maxValue_info_and_float() (pwg.images.search, no
     * bool param) above. pwg.users.setInfo's 'expand' param is
     * WsParamType::BOOL with no POSITIVE/NOTNULL.
     */
    public function test_reflection_getMethodDetails_describes_a_bool_param(): void
    {
        $response = $this->ws('reflection.getMethodDetails', ['methodName' => 'pwg.users.setInfo']);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $params = $result['params'];
        self::assertIsArray($params);
        $byName = array_column($params, null, 'name');

        self::assertSame(
            ['name' => 'expand', 'optional' => true, 'acceptArray' => false, 'type' => 'bool'],
            $byName['expand']
        );
    }

    /**
     * pwg.users.setInfo is on CurrentConfig::apiKeyForbiddenMethods()'s own
     * default list -- an API-key-authenticated request must be refused
     * before invoke() ever runs the method body, regardless of a
     * (deliberately wrong) pwg_token.
     */
    public function test_invoke_via_api_key_for_a_forbidden_method_returns_access_denied(): void
    {
        // api_key.create requires $_SESSION['connected_with'] === 'pwg_ui'
        // (ApiKeyService::connectedWithPwgUi()) -- loginAsAdminViaUI(), not
        // the WS pwg.session.login, matches WsSessionTest's own pattern.
        $this->loginAsAdminViaUI();
        $token = $this->getPwgToken();

        $createResponse = $this->callWs('pwg.users.api_key.create', [
            'key_name' => 'Contract server test key ' . uniqid(),
            'duration' => 1,
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $createResponse['stat']);
        $createResult = $createResponse['result'];
        self::assertIsArray($createResult);
        $authKey = $createResult['auth_key'];
        $secret = $createResult['apikey_secret'];
        self::assertIsString($authKey);
        self::assertIsString($secret);

        try {
            $ch = curl_init($this->baseUrl . '/ws.php?format=json');
            self::assertNotFalse($ch);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'method' => 'pwg.users.setInfo',
                'user_id' => 1,
                'pwg_token' => 'irrelevant-because-the-method-gate-runs-first',
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->testHeader(), ['X-Piwigo-Api: ' . $authKey . ':' . $secret]));

            $body = curl_exec($ch);
            unset($ch);

            self::assertIsString($body);
            $decoded = json_decode($body, true);
            self::assertIsArray($decoded);
            self::assertSame('fail', $decoded['stat']);
            self::assertSame(401, $decoded['err']);
            self::assertSame('Access denied', $decoded['message']);
        } finally {
            $revokeToken = $this->getPwgToken();
            $this->callWs('pwg.users.api_key.revoke', ['pwg_token' => $revokeToken]);
        }
    }

    /**
     * Genuinely different code path from
     * test_invoke_via_api_key_for_a_forbidden_method_returns_access_denied()
     * above: that test authenticates via the X-Piwigo-Api HEADER, whose
     * $connectionByHeader=true short-circuit in AuthService::authKeyLogin()
     * never calls logUser() -- the session's user status stays guest, so
     * invoke()'s own admin_only gate (`! AccessControl::isAdmin()`)
     * rejects pwg.users.setInfo *before* isAuthorizedMethodForAPIKEY() is
     * ever reached, even though the outward 401/"Access denied" response
     * looks identical. Logging in through pwg.session.login with the same
     * api_key's "pkid-..." public part / secret as username/password
     * instead (PwgCore::sessionLogin() -> authKeyLogin() with its
     * $connectionByHeader default of false) performs a real logUser() as
     * the key's owner (fixture_admin, a genuine admin) and sets
     * $_SESSION['connected_with'] = 'ws_session_login_api_key' -- the
     * admin_only gate now passes for real, so this is the only way to
     * reach isAuthorizedMethodForAPIKEY()'s own forbidden-methods check
     * (its `return false;`) through the real WS route.
     */
    public function test_invoke_via_session_api_key_login_for_a_forbidden_method_returns_access_denied(): void
    {
        $this->loginAsAdminViaUI();
        $token = $this->getPwgToken();

        $createResponse = $this->callWs('pwg.users.api_key.create', [
            'key_name' => 'Contract server test key ' . uniqid(),
            'duration' => 1,
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $createResponse['stat']);
        $createResult = $createResponse['result'];
        self::assertIsArray($createResult);
        $authKey = $createResult['auth_key'];
        $secret = $createResult['apikey_secret'];
        self::assertIsString($authKey);
        self::assertIsString($secret);

        try {
            $loginResponse = $this->callWs('pwg.session.login', [
                'username' => $authKey,
                'password' => $secret,
            ]);
            self::assertSame('ok', $loginResponse['stat']);

            $forbidden = $this->callWs('pwg.users.setInfo', [
                'user_id' => 1,
                'pwg_token' => 'irrelevant-because-the-method-gate-runs-first',
            ]);

            self::assertSame('fail', $forbidden['stat']);
            self::assertSame(401, $forbidden['err']);
            self::assertSame('Access denied', $forbidden['message']);
        } finally {
            // Best-effort cleanup, matching
            // test_invoke_via_api_key_for_a_forbidden_method_returns_access_denied()'s
            // own finally block above -- 'pkid' isn't sent either there or
            // here, so this always resolves to a discarded 'fail' (missing
            // required param, or -- now that this session's
            // connected_with is 'ws_session_login_api_key', not 'pwg_ui'
            // -- "Acces Denied") rather than an actual revoke; the created
            // key is a throwaway regardless.
            $revokeToken = $this->getPwgToken();
            $this->callWs('pwg.users.api_key.revoke', ['pwg_token' => $revokeToken]);
        }
    }
}
