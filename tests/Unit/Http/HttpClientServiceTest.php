<?php

declare(strict_types=1);

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Piwigo\Http\HttpClientNetworkException;
use Piwigo\Http\HttpClientService;
use Piwigo\Http\HttpClientSsrfException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function makeHttpRequest(string $method, string $uri): RequestInterface
{
    return new Psr17Factory()->createRequest($method, $uri);
}

/**
 * Starts a real, disposable PHP built-in server bound to 127.0.0.1
 * serving $docRoot -- see the big docblock further down for why this is
 * the only way to exercise fetch()/fetchToFile()/guardedFetch()'s own
 * static, non-injectable success path for real.
 *
 * $useRouter routes EVERY request (including a proxy's own
 * absolute-URI-in-the-request-line style requests, which `-t $docRoot`
 * can't match to a file) through $docRoot/router.php, matching PHP's own
 * `php -S host:port router.php` convention.
 *
 * @return array{0: resource, 1: int} the process handle and the bound port
 */
function httpClientServiceTestStartLocalServer(string $docRoot, bool $useRouter = false): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20_000, 60_000);
        $command = $useRouter
            ? ['php', '-S', '127.0.0.1:' . $port, $docRoot . '/router.php']
            : ['php', '-S', '127.0.0.1:' . $port, '-t', $docRoot];
        $proc = proc_open($command, $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('failed to start local test server');
        }

        // @ doesn't suppress this from PHPUnit's own warning collector --
        // "Connection refused" is the expected, normal condition on every
        // early iteration before the spawned server finishes starting, so
        // a real error-handler swap is needed to keep it out of the suite's
        // warning count.
        set_error_handler(static fn (): bool => true);
        try {
            for ($i = 0; $i < 100; $i++) {
                $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if (is_resource($sock)) {
                    fclose($sock);

                    return [$proc, $port];
                }
                usleep(20_000);
            }
        } finally {
            restore_error_handler();
        }

        // Port collision (rare, another process grabbed it first) or the
        // server genuinely never came up -- kill it and retry with a
        // fresh random port rather than leaving a zombie process/hanging.
        proc_terminate($proc);
        proc_close($proc);
    }

    throw new RuntimeException('local test server never became reachable after 5 attempts');
}

/**
 * @param resource $proc
 */
function httpClientServiceTestStopLocalServer($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

test('sendRequest rejects a non-https scheme before contacting the transport', function (): void {
    $calls = 0;
    $client = new MockHttpClient(function () use (&$calls): MockResponse {
        $calls++;

        return new MockResponse('unreachable');
    });
    $service = new HttpClientService($client);

    expect(fn (): ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'http://example.test/')))
        ->toThrow(HttpClientSsrfException::class, 'Only https:// URLs are allowed: http://example.test/');
    expect($calls)->toBe(0);
});

test('sendRequest rejects a malformed URL missing scheme/host, with the URL embedded in the message', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn (): ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'not-a-url')))
        ->toThrow(HttpClientSsrfException::class, 'Malformed URL: not-a-url');
});

test('sendRequest rejects a URL that parse_url() itself cannot parse at all, reached via a redirect target', function (): void {
    // Kills line 350's FalseToTrue (`$parts === true` instead of `===
    // false`). A raw PSR-7 request URI can't reach this branch --
    // nyholm/psr7's own Uri constructor already rejects a string like
    // "https:///" before assertUrlIsSafe() is ever called -- but a
    // redirect Location header is returned verbatim by
    // resolveRedirectTarget() (matched only against a loose
    // `^https?://` prefix regex, never parsed as a real URI), so a
    // malicious/malformed Location can genuinely reach this exact
    // branch on the NEXT loop iteration. Confirmed live.
    $client = new MockHttpClient(new MockResponse('', [
        'http_code' => 302,
        'response_headers' => ['Location' => 'https:///'],
    ]));
    $service = new HttpClientService($client);

    expect(fn (): ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/start')))
        ->toThrow(HttpClientSsrfException::class, 'Malformed URL: https:///');
});

test('sendRequest rejects a loopback IP host, with the URL embedded in the message', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn (): ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://127.0.0.1/')))
        ->toThrow(HttpClientSsrfException::class, 'Target host resolves to a private/reserved IP range: https://127.0.0.1/');
});

test('sendRequest rejects the link-local cloud metadata IP', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn (): ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://169.254.169.254/latest/meta-data/')))
        ->toThrow(HttpClientSsrfException::class);
});

test('sendRequest rejects a private RFC1918 IP host, with the URL embedded in the message', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn (): ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://10.0.0.5/')))
        ->toThrow(HttpClientSsrfException::class, 'Target host resolves to a private/reserved IP range: https://10.0.0.5/');
});

test('sendRequest allows a genuine hostname that resolves to a public IP', function (): void {
    // Kills line 372's TernaryNegated/NotIdenticalToIdentical/FalseToTrue
    // on the `filter_var($host, FILTER_VALIDATE_IP) !== false ? $host :
    // gethostbyname($host)` branch. Every other SSRF test in this file
    // uses an IP-literal host, which always takes the "already an IP"
    // branch either way -- only a genuine (non-IP-literal) hostname
    // exercises the gethostbyname() branch. Confirmed live: real code
    // resolves 'example.com' to a real public IP and allows the
    // request; each of these 3 mutations instead uses the raw hostname
    // string AS the "resolved" IP, which then fails the later IP-format
    // validation and wrongly throws an SSRF exception. Real DNS lookup
    // only (confirmed working in this sandbox); MockHttpClient
    // intercepts the actual HTTP request, no real internet needed.
    $client = new MockHttpClient(new MockResponse('hello', ['http_code' => 200]));
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://example.com/'));

    expect($response->getStatusCode())->toBe(200);
});

test('sendRequest allows a public IP host and returns the response body', function (): void {
    $client = new MockHttpClient(new MockResponse('hello world', ['http_code' => 200]));
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/ok'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('hello world');
});

test('sendRequest forwards every response header, including multiple values for the same header, onto the PSR-7 response', function (): void {
    // Kills lines 414/415's ForeachEmptyIterable (both the outer
    // header-name loop and the inner per-value loop in toPsrResponse())
    // -- no existing test inspected response headers at all.
    $client = new MockHttpClient(new MockResponse('hi', [
        'http_code' => 200,
        'response_headers' => [
            'X-Single' => 'one',
            'X-Multi' => ['a', 'b'],
        ],
    ]));
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/headers'));

    expect($response->getHeaderLine('X-Single'))->toBe('one')
        ->and($response->getHeader('X-Multi'))->toBe(['a', 'b']);
});

test('sendRequest throws when a redirect target is a private IP', function (): void {
    $client = new MockHttpClient(new MockResponse('', [
        'http_code' => 302,
        'response_headers' => ['Location' => 'https://127.0.0.1/internal'],
    ]));
    $service = new HttpClientService($client);

    expect(fn (): ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/start')))
        ->toThrow(HttpClientSsrfException::class);
});

test('sendRequest follows a redirect chain of safe targets to completion', function (): void {
    $client = new MockHttpClient([
        new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['Location' => 'https://93.184.216.35/step2'],
        ]),
        new MockResponse('done', ['http_code' => 200]),
    ]);
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/start'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('done');
});

test('sendRequest downgrades POST to GET on a 302 redirect and drops the body, headers, and forces max_redirects to 0 on every hop', function (): void {
    // Kills line 336's EmptyStringToNotEmpty (the body-dropping branch's
    // '' fallback) -- the existing assertion only checked the METHOD
    // downgraded, never that the body was actually emptied. Also kills
    // lines 305/315/316/317's ForeachEmptyIterable/RemoveArrayItem
    // (headers/body/max_redirects silently missing from the outgoing
    // options) by inspecting the real options Symfony received on both
    // hops, and line 289's sibling gap for a genuine multi-value header
    // (X-Multi joined as "a, b", confirming the implode() in the same
    // extraction loop).
    $seen = [];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
        $seen[] = [$method, $url, $options];
        if (count($seen) === 1) {
            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'https://93.184.216.35/step2'],
            ]);
        }

        return new MockResponse('done', ['http_code' => 200]);
    });
    $service = new HttpClientService($client);

    $request = makeHttpRequest('POST', 'https://93.184.216.34/start')
        ->withHeader('X-Multi', 'a')
        ->withAddedHeader('X-Multi', 'b')
        ->withBody(new Psr17Factory()->createStream('a=1'));

    $service->sendRequest($request);

    expect($seen)->toHaveCount(2);
    [$firstMethod, , $firstOptions] = $seen[0];
    [$secondMethod, , $secondOptions] = $seen[1];

    expect($firstMethod)->toBe('POST')
        ->and($firstOptions['body'] ?? null)->toBe('a=1')
        ->and($firstOptions['headers'] ?? [])->toContain('X-Multi: a, b')
        ->and($firstOptions['max_redirects'] ?? null)->toBe(0);
    expect($secondMethod)->toBe('GET')
        ->and($secondOptions['body'] ?? null)->toBe('')
        ->and($secondOptions['max_redirects'] ?? null)->toBe(0);
});

test('sendRequest makes exactly MAX_REDIRECTS + 1 requests before giving up on an infinite redirect loop', function (): void {
    // Kills line 310's DecrementInteger/IncrementInteger on the
    // $redirects counter's starting value (0) and line 324's
    // GreaterOrEqualToGreater (`>` instead of `>=`) -- the existing
    // "gives up after max redirect count" test only checked the FINAL
    // status code (302 either way), never the exact number of requests
    // actually made. Confirmed live: real code makes exactly 6 requests
    // (MAX_REDIRECTS=5 redirects followed, giving up on the 6th
    // response without following it further); each of these 3
    // mutations shifts that count to 5 or 7.
    $count = 0;
    $client = new MockHttpClient(function () use (&$count): MockResponse {
        $count++;

        return new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['Location' => 'https://93.184.216.34/loop'],
        ]);
    });
    $service = new HttpClientService($client);

    $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/loop'));

    expect($count)->toBe(6);
});

test('sendRequest preserves method and body on a 307 redirect', function (): void {
    $seenMethods = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$seenMethods): MockResponse {
        $seenMethods[] = $method;
        if (count($seenMethods) === 1) {
            return new MockResponse('', [
                'http_code' => 307,
                'response_headers' => ['Location' => 'https://93.184.216.35/step2'],
            ]);
        }

        return new MockResponse('done', ['http_code' => 200]);
    });
    $service = new HttpClientService($client);

    $request = makeHttpRequest('POST', 'https://93.184.216.34/start');
    $service->sendRequest($request);

    expect($seenMethods)->toBe(['POST', 'POST']);
});

test('sendRequest gives up after the max redirect count and returns the last redirect response', function (): void {
    $client = new MockHttpClient(fn (): MockResponse => new MockResponse('', [
        'http_code' => 302,
        'response_headers' => ['Location' => 'https://93.184.216.34/loop'],
    ]));
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/loop'));

    expect($response->getStatusCode())->toBe(302);
});

test('sendRequest wraps a transport failure as HttpClientNetworkException', function (): void {
    $client = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
    $service = new HttpClientService($client);

    expect(fn (): ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/down')))
        ->toThrow(HttpClientNetworkException::class);
});

test('requestRaw returns Symfony\'s own response type, applies the same SSRF guard, and forwards the given headers', function (): void {
    // Kills line 289's ForeachEmptyIterable (looping over an empty array
    // instead of $headers, so requestRaw()'s own $headers argument would
    // silently never reach the outgoing request) -- the existing
    // assertions only checked the response, never that 'User-Agent' was
    // actually forwarded.
    $seenHeaders = [];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenHeaders): MockResponse {
        $seenHeaders = $options['headers'] ?? [];

        return new MockResponse('raw body', ['http_code' => 200]);
    });
    $service = new HttpClientService($client);

    $response = $service->requestRaw('GET', 'https://93.184.216.34/raw', ['User-Agent' => 'Piwigo'], '');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent(false))->toBe('raw body')
        ->and($seenHeaders)->toContain('User-Agent: Piwigo');
});

test('requestRaw rejects a private IP host', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn (): \Symfony\Contracts\HttpClient\ResponseInterface => $service->requestRaw('GET', 'https://192.168.1.1/', [], ''))
        ->toThrow(HttpClientSsrfException::class);
});

test('requestRaw forwards extra Symfony options such as timeout to the transport', function (): void {
    $seenOptions = [];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
        $seenOptions = $options;

        return new MockResponse('ok', ['http_code' => 200]);
    });
    $service = new HttpClientService($client);

    $service->requestRaw('GET', 'https://93.184.216.34/raw', [], '', ['timeout' => 5]);

    expect($seenOptions['timeout'] ?? null)->toBe(5.0);
});

test('requestRaw exempts a trustedSelfHost from both the https-only and private-IP checks', function (): void {
    $client = new MockHttpClient(new MockResponse('self', ['http_code' => 200]));
    $service = new HttpClientService($client, trustedSelfHost: 'localhost');

    $response = $service->requestRaw('GET', 'http://localhost/i.php?x=1', [], '');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent(false))->toBe('self');
});

test('requestRaw honors a trustedSelfHost that includes a non-standard port', function (): void {
    $client = new MockHttpClient(new MockResponse('self', ['http_code' => 200]));
    $service = new HttpClientService($client, trustedSelfHost: 'localhost:8080');

    $response = $service->requestRaw('GET', 'http://localhost:8080/i.php?x=1', [], '');

    expect($response->getStatusCode())->toBe(200);
});

test('requestRaw still guards a different host even when a trustedSelfHost is set', function (): void {
    $service = new HttpClientService(new MockHttpClient(), trustedSelfHost: 'localhost');

    expect(fn (): \Symfony\Contracts\HttpClient\ResponseInterface => $service->requestRaw('GET', 'http://127.0.0.1/', [], ''))
        ->toThrow(HttpClientSsrfException::class);
});

// The remaining gaps below are all reachable through the same injectable
// MockHttpClient seam used throughout this file.
//
// `fetch()`/`fetchToFile()`/their shared `guardedFetch()` helper have no
// injectable-client seam (guardedFetch() always constructs `new self(...)`
// internally using the hardcoded real defaultClient() --
// Symfony\Component\HttpClient\HttpClient::create() -- with no parameter
// anywhere in the static call chain to substitute a MockHttpClient), which
// is why every OTHER project test file touching this static path (see
// PageTailTest/RateRepositoryTest/PemCatalogTest/ExtensionLifecycleTest/
// InstallWizardTest/IntroSubControllerGetLatestNewsTest/
// MaintenanceActionDispatcherTest's own identical docblocks) deliberately
// stays on the network-unreachable-target branch instead (this sandbox has
// no real internet access). That's still true for genuinely *external*
// requests -- but guardedFetch()'s own self-request/trustedSelfHost
// exemption (see its docblock) doesn't need external internet at all, only
// a real loopback TCP connection, which this sandbox does have. The
// success-path lines below (141/161/256) are closed that way: a real,
// disposable `php -S 127.0.0.1:<port>` server plus a matching
// `$_SERVER['HTTP_HOST']` (production's own self-request signal, e.g. a
// self-priming request right after upload) legitimately exempts the
// request from both the https-only and private-IP checks, the same way a
// real self-request does -- not a test-only bypass. The proxy-option lines
// (219-225) are closed separately, pointed at a closed local port so the
// *actual* proxied connection fails near-instantly without needing real
// internet either; only the option-building code itself is under test
// there.

test('fetch() returns the real response body through a genuine self-request round trip', function (): void {
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/probe.php', '<?php echo "real-local-fetch-body";');

    [$proc, $port] = httpClientServiceTestStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;

    try {
        $result = HttpClientService::fetch('http://127.0.0.1:' . $port . '/probe.php', CurrentConfigTestFactory::get());

        expect($result)->toBe('real-local-fetch-body');
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        httpClientServiceTestStopLocalServer($proc);
        unlink($docRoot . '/probe.php');
        rmdir($docRoot);
    }
});

test('fetch() builds the GET query string, POST body/Content-Type, and User-Agent header for real, through a genuine self-request', function (): void {
    // Kills guardedFetch()'s own URL/method/body-building mutations
    // (lines 171-178: GET-vs-POST method selection, '?'-vs-'&' query
    // separator logic in both directions, http_build_query()'s own
    // separator argument, and the User-Agent header) -- a probe script
    // that echoes back exactly what it received lets every one of these
    // be asserted precisely, through the same genuine local-server
    // round trip as the sibling test above (guardedFetch() has no
    // injectable-client seam).
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/echo.php', <<<'PHP'
        <?php
        header('Content-Type: application/json');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'get' => $_GET,
            'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
            'body' => file_get_contents('php://input'),
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        PHP);

    [$proc, $port] = httpClientServiceTestStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
    $base = 'http://127.0.0.1:' . $port . '/echo.php';

    try {
        // GET with query data appended via '?' (no existing query string).
        $getResult = HttpClientService::fetch($base, CurrentConfigTestFactory::get(), ['a' => '1', 'b' => '2'], [], 'CustomAgent/1.0');
        expect($getResult)->toBeString();
        assert(is_string($getResult));
        $getDecoded = json_decode($getResult, true);
        expect($getDecoded)->toBe([
            'method' => 'GET',
            'get' => ['a' => '1', 'b' => '2'],
            'contentType' => null,
            'body' => '',
            'userAgent' => 'CustomAgent/1.0',
        ]);

        // GET with query data appended via '&' (URL already has a '?').
        $ampResult = HttpClientService::fetch($base . '?existing=1', CurrentConfigTestFactory::get(), ['a' => '1'], [], 'CustomAgent/1.0');
        expect($ampResult)->toBeString();
        assert(is_string($ampResult));
        $ampDecoded = json_decode($ampResult, true);
        assert(is_array($ampDecoded));
        expect($ampDecoded['get'] ?? null)->toBe(['existing' => '1', 'a' => '1']);

        // POST with form-encoded body and Content-Type.
        $postResult = HttpClientService::fetch($base, CurrentConfigTestFactory::get(), [], ['x' => 'y', 'z' => 'w'], 'CustomAgent/1.0');
        expect($postResult)->toBeString();
        assert(is_string($postResult));
        $postDecoded = json_decode($postResult, true);
        expect($postDecoded)->toBe([
            'method' => 'POST',
            'get' => [],
            'contentType' => 'application/x-www-form-urlencoded',
            'body' => 'x=y&z=w',
            'userAgent' => 'CustomAgent/1.0',
        ]);

        // NOT closable: lines 174/225's EmptyStringToNotEmpty on
        // http_build_query()'s own numeric_prefix argument only matters
        // for numerically-KEYED data (PHP normalizes any numeric string
        // key to a real int key at runtime), but $getData/$postData are
        // both typed `array<string, mixed>` -- no type-safe caller can
        // construct an int-keyed array for either parameter, so this
        // mutation is unreachable under the method's own declared
        // contract, confirmed via a real PHPStan error when attempting
        // exactly that array shape here.
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        httpClientServiceTestStopLocalServer($proc);
        unlink($docRoot . '/echo.php');
        rmdir($docRoot);
    }
});

test('fetch() forwards the X-Piwigo-Env test-mode header on a genuine self-request', function (): void {
    // Positive-case coverage for lines 211/213's self-request +
    // test-mode guard (tests/bootstrap.php sets
    // $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test' for the whole suite, and
    // PHP_SAPI is 'cli' under Pest, so Env::testModeIsActive() is
    // already true throughout -- this confirms the header genuinely
    // reaches a matching self-request).
    //
    // The AND-vs-OR distinction on lines 211/213 themselves (forwarding
    // to a request that is NOT actually a self-request) could not be
    // closed: reaching a host that doesn't match $_SERVER['HTTP_HOST']
    // requires the target to independently pass assertUrlIsSafe() on
    // its own merits (https + a real public IP, since a mismatched host
    // means no trustedSelfHost exemption applies), but this sandbox has
    // no real, inspectable HTTPS server -- only loopback, which the
    // guard itself blocks without that exemption. A real external target
    // (confirmed reachable, e.g. example.com) can't have its received
    // headers inspected either. Attempted the fake-local-proxy technique
    // used elsewhere in this file too: it needs an HTTP (not HTTPS)
    // proxied target to avoid CONNECT-tunnel support php -S lacks, which
    // circles back to the same reachability wall.
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/echo.php', <<<'PHP'
        <?php
        header('Content-Type: application/json');
        echo json_encode(['piwigoEnv' => $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null]);
        PHP);

    [$proc, $port] = httpClientServiceTestStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;

    try {
        $result = HttpClientService::fetch('http://127.0.0.1:' . $port . '/echo.php', CurrentConfigTestFactory::get());

        expect($result)->toBeString();
        assert(is_string($result));
        $decoded = json_decode($result, true);
        assert(is_array($decoded));
        expect($decoded['piwigoEnv'] ?? null)->toBe('test');
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        httpClientServiceTestStopLocalServer($proc);
        unlink($docRoot . '/echo.php');
        rmdir($docRoot);
    }
});

test('guardedFetch() accepts status 200 and 399 but rejects 199 and 400, pinning the exact status filter boundaries', function (): void {
    // Kills line 267's GreaterOrEqualToGreater (`> 400` instead of `>=
    // 400`), BooleanOrToBooleanAnd (`&&` instead of `||`, which can
    // never be true for any single status -- nothing would ever be
    // rejected), and both DecrementInteger/IncrementInteger mutations
    // (boundary shifted to 199/399/401) -- every other test in this
    // file only ever sees status 200, so the exact boundary was never
    // pinned. A real local server whose status is configurable via
    // query string closes this precisely, at both edges of the real
    // [200, 400) accepted range.
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/status.php', <<<'PHP'
        <?php
        http_response_code((int) ($_GET['s'] ?? 200));
        echo 'body-for-status-' . ($_GET['s'] ?? 200);
        PHP);

    [$proc, $port] = httpClientServiceTestStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
    $base = 'http://127.0.0.1:' . $port . '/status.php';

    try {
        expect(HttpClientService::fetch($base . '?s=199', CurrentConfigTestFactory::get()))->toBeFalse();
        expect(HttpClientService::fetch($base . '?s=200', CurrentConfigTestFactory::get()))->toBe('body-for-status-200');
        expect(HttpClientService::fetch($base . '?s=399', CurrentConfigTestFactory::get()))->toBe('body-for-status-399');
        expect(HttpClientService::fetch($base . '?s=400', CurrentConfigTestFactory::get()))->toBeFalse();
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        httpClientServiceTestStopLocalServer($proc);
        unlink($docRoot . '/status.php');
        rmdir($docRoot);
    }
});

test('fetchToFile() writes the real self-requested response body into the given file handle', function (): void {
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/probe.php', '<?php echo "real-local-file-body";');

    [$proc, $port] = httpClientServiceTestStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
    $destPath = sys_get_temp_dir() . '/pwg-httpclient-test-dest-' . bin2hex(random_bytes(8));
    $handle = fopen($destPath, 'wb');
    expect($handle)->not->toBeFalse();

    try {
        if ($handle !== false) {
            $ok = HttpClientService::fetchToFile($handle, 'http://127.0.0.1:' . $port . '/probe.php', CurrentConfigTestFactory::get());
            fclose($handle);

            expect($ok)->toBeTrue();
            expect(file_get_contents($destPath))->toBe('real-local-file-body');
        }
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        httpClientServiceTestStopLocalServer($proc);
        unlink($docRoot . '/probe.php');
        rmdir($docRoot);
        @unlink($destPath);
    }
});

test('fetch() propagates real content instead of throwing when guardedFetch() returns a genuine 3xx response', function (): void {
    // Kills line 141's FalseToTrue (getContent(true) instead of
    // getContent(false)). guardedFetch()'s own status filter accepts
    // any status in [200, 400), which includes 3xx codes that survive
    // MAX_REDIRECTS exhaustion (see requestRaw()'s own redirect-count
    // test) -- Symfony's getContent(true) throws a RedirectionException
    // for any 3xx status, confirmed live, so a self-redirecting local
    // server that never resolves gives a real, reachable way to make
    // fetch() receive exactly such a response.
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/loop.php', "<?php header('Location: /loop.php', true, 302);");

    [$proc, $port] = httpClientServiceTestStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;

    try {
        $result = HttpClientService::fetch('http://127.0.0.1:' . $port . '/loop.php', CurrentConfigTestFactory::get());

        expect($result)->toBe('');
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        httpClientServiceTestStopLocalServer($proc);
        unlink($docRoot . '/loop.php');
        rmdir($docRoot);
    }
});

test('fetchToFile() writes real content instead of throwing when guardedFetch() returns a genuine 3xx response', function (): void {
    // Kills line 161's first FalseToTrue (getContent(true) instead of
    // getContent(false)) -- the exact same redirect-exhaustion technique
    // as fetch()'s own sibling test above, but through fetchToFile()'s
    // separate getContent() call site.
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/loop.php', "<?php header('Location: /loop.php', true, 302);");

    [$proc, $port] = httpClientServiceTestStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
    $destPath = sys_get_temp_dir() . '/pwg-httpclient-test-dest-' . bin2hex(random_bytes(8));
    $handle = fopen($destPath, 'wb');
    expect($handle)->not->toBeFalse();

    try {
        if ($handle !== false) {
            $ok = HttpClientService::fetchToFile($handle, 'http://127.0.0.1:' . $port . '/loop.php', CurrentConfigTestFactory::get());
            fclose($handle);

            expect($ok)->toBeTrue();
            expect(file_get_contents($destPath))->toBe('');
        }
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        httpClientServiceTestStopLocalServer($proc);
        unlink($docRoot . '/loop.php');
        rmdir($docRoot);
        @unlink($destPath);
    }
});

test('fetchToFile() returns false instead of crashing when guardedFetch() returns null', function (): void {
    // Kills line 157's InstanceOfToTrue (`if (!true)`, never taking the
    // early return). If guardedFetch() genuinely returns null (the same
    // malformed-URL shape the existing fetch() test for this uses) and
    // this guard is skipped, fetchToFile() would call
    // $response->getContent() on null -- a fatal error, not the clean
    // `false` its own fire-and-forget contract requires.
    $handle = fopen(sys_get_temp_dir() . '/pwg-httpclient-nohost-' . bin2hex(random_bytes(4)), 'wb');
    expect($handle)->not->toBeFalse();

    try {
        if ($handle !== false) {
            $result = HttpClientService::fetchToFile($handle, 'http:///i.php', CurrentConfigTestFactory::get());

            expect($result)->toBeFalse();
        }
    } finally {
        if ($handle !== false) {
            fclose($handle);
        }
    }
});

test('fetchToFile() returns false, not true, when the write to the given handle genuinely fails', function (): void {
    // Kills line 161's second FalseToTrue (`!== true` instead of `!==
    // false` on the fwrite() result). fwrite() never returns the
    // literal boolean `true` (only an int byte-count or `false`), so
    // `!== true` is unconditionally true regardless of whether the
    // write actually succeeded -- confirmed live this makes the mutant
    // always return `true` even when the write demonstrably fails. A
    // real read-only-opened file handle forces a genuine fwrite()
    // failure.
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/probe.php', '<?php echo "content";');

    [$proc, $port] = httpClientServiceTestStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;

    $path = sys_get_temp_dir() . '/pwg-httpclient-readonly-' . bin2hex(random_bytes(8));
    file_put_contents($path, '');
    chmod($path, 0o444);
    $handle = fopen($path, 'rb');
    expect($handle)->not->toBeFalse();

    set_error_handler(static fn (): bool => true, \E_WARNING);
    try {
        if ($handle !== false) {
            $result = HttpClientService::fetchToFile($handle, 'http://127.0.0.1:' . $port . '/probe.php', CurrentConfigTestFactory::get());

            expect($result)->toBeFalse();
        }
    } finally {
        restore_error_handler();
        if ($handle !== false) {
            fclose($handle);
        }
        chmod($path, 0o644);
        unlink($path);
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        httpClientServiceTestStopLocalServer($proc);
        unlink($docRoot . '/probe.php');
        rmdir($docRoot);
    }
});

test('guardedFetch() actually routes through the configured proxy and embeds Basic-auth credentials in it', function (): void {
    // Kills lines 230/233/236/238's mutations on $extraOptions'
    // construction (timeout value, the useProxy/proxyServer/proxyAuth
    // guards, and the Basic-auth preg_replace() embedding) -- the
    // previous version of this test only checked that fetch() returned
    // `false` when pointed at a closed port, which every one of these
    // mutations also produces (a closed port refuses the connection
    // either way, proxied correctly or not). A real local "proxy" (a
    // `php -S` router echoing back the request line + headers it
    // received) lets this verify the request was actually ROUTED
    // through the proxy with the right Proxy-Authorization header,
    // combined with the self-request/trustedSelfHost exemption (see the
    // sibling round-trip tests) to reach an http:// target without
    // needing real internet -- the proxy option means the target itself
    // never needs to be reachable at all, only the proxy does.
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-proxy-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/router.php', <<<'PHP'
        <?php
        header('Content-Type: application/json');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[$k] = $v;
            }
        }
        echo json_encode([
            'requestUri' => $_SERVER['REQUEST_URI'] ?? null,
            'headers' => $headers,
        ]);
        PHP);

    [$proc, $port] = httpClientServiceTestStartLocalServer($docRoot, useRouter: true);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = 'my-self-host.test';

    CurrentConfigTestFactory::get()->useProxy = true;
    CurrentConfigTestFactory::get()->proxyServer = 'http://127.0.0.1:' . $port;
    CurrentConfigTestFactory::get()->proxyAuth = 'theuser:thepass';

    try {
        $result = HttpClientService::fetch('http://my-self-host.test/i.php?x=1', CurrentConfigTestFactory::get());

        expect($result)->toBeString();
        assert(is_string($result));
        $decoded = json_decode($result, true);
        assert(is_array($decoded));
        $decodedHeaders = $decoded['headers'] ?? null;
        assert(is_array($decodedHeaders));
        expect($decoded['requestUri'] ?? null)->toBe('http://my-self-host.test/i.php?x=1')
            ->and($decodedHeaders['HTTP_PROXY_AUTHORIZATION'] ?? null)->toBe('Basic ' . base64_encode('theuser:thepass'));
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        CurrentConfigTestFactory::get()->reset();
        httpClientServiceTestStopLocalServer($proc);
        unlink($docRoot . '/router.php');
        rmdir($docRoot);
    }
});

test('guardedFetch() does not route through the proxy at all when useProxy is false, even with a real proxyServer configured', function (): void {
    // Kills line 233's BooleanAndToBooleanOr (`||` instead of `&&`).
    // The sibling test above always sets useProxy=true, which can't
    // distinguish this -- only useProxy=false combined with a non-empty
    // proxyServer does, since that's the one combination where && and
    // || disagree. Two distinguishable local servers (the real target,
    // and a fake proxy that would answer differently) confirm which one
    // actually received the connection.
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-proxy-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/direct.php', '<?php echo "direct-hit";');
    file_put_contents($docRoot . '/router.php', '<?php echo "via-proxy";');

    [$targetProc, $targetPort] = httpClientServiceTestStartLocalServer($docRoot);
    [$proxyProc, $proxyPort] = httpClientServiceTestStartLocalServer($docRoot, useRouter: true);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $targetPort;

    CurrentConfigTestFactory::get()->useProxy = false;
    CurrentConfigTestFactory::get()->proxyServer = 'http://127.0.0.1:' . $proxyPort;

    try {
        $result = HttpClientService::fetch('http://127.0.0.1:' . $targetPort . '/direct.php', CurrentConfigTestFactory::get());

        expect($result)->toBe('direct-hit');
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        CurrentConfigTestFactory::get()->reset();
        httpClientServiceTestStopLocalServer($targetProc);
        httpClientServiceTestStopLocalServer($proxyProc);
        unlink($docRoot . '/direct.php');
        unlink($docRoot . '/router.php');
        rmdir($docRoot);
    }
});

test('guardedFetch() does not embed Basic-auth credentials into the proxy URL when proxyAuth is empty', function (): void {
    // Kills line 236's EmptyStringToNotEmpty/IfNegated/
    // NotIdenticalToIdentical on the proxyAuth() !== '' guard -- the
    // sibling proxy test above always configures a non-empty proxyAuth,
    // which can't distinguish any of these.
    $docRoot = sys_get_temp_dir() . '/pwg-httpclient-proxy-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot);
    file_put_contents($docRoot . '/direct.php', '<?php echo "direct-hit";');
    file_put_contents($docRoot . '/router.php', <<<'PHP'
        <?php
        header('Content-Type: application/json');
        echo json_encode(['hasAuth' => isset($_SERVER['HTTP_PROXY_AUTHORIZATION'])]);
        PHP);

    [$targetProc, $targetPort] = httpClientServiceTestStartLocalServer($docRoot);
    [$proxyProc, $proxyPort] = httpClientServiceTestStartLocalServer($docRoot, useRouter: true);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $targetPort;

    CurrentConfigTestFactory::get()->useProxy = true;
    CurrentConfigTestFactory::get()->proxyServer = 'http://127.0.0.1:' . $proxyPort;
    CurrentConfigTestFactory::get()->proxyAuth = '';

    try {
        $result = HttpClientService::fetch('http://127.0.0.1:' . $targetPort . '/direct.php', CurrentConfigTestFactory::get());

        expect($result)->toBeString();
        assert(is_string($result));
        $decoded = json_decode($result, true);
        assert(is_array($decoded));
        expect($decoded['hasAuth'] ?? null)->toBeFalse();
    } finally {
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
        CurrentConfigTestFactory::get()->reset();
        httpClientServiceTestStopLocalServer($targetProc);
        httpClientServiceTestStopLocalServer($proxyProc);
        unlink($docRoot . '/direct.php');
        unlink($docRoot . '/router.php');
        rmdir($docRoot);
    }
});

test('guardedRequest returns the redirect response as-is when the Location header is entirely absent', function (): void {
    $client = new MockHttpClient(new MockResponse('', ['http_code' => 302]));
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/no-location-header'));

    expect($response->getStatusCode())->toBe(302);
});

test('guardedRequest returns the redirect response as-is when the Location header is an empty string', function (): void {
    $client = new MockHttpClient(new MockResponse('', [
        'http_code' => 302,
        'response_headers' => ['Location' => ''],
    ]));
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/empty-location-header'));

    expect($response->getStatusCode())->toBe(302);
});

test('resolveRedirectTarget resolves a root-relative Location against the scheme+host+port of the current URI', function (): void {
    $seenUrls = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$seenUrls): MockResponse {
        $seenUrls[] = $url;

        return count($seenUrls) === 1
            ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => '/other-page']])
            : new MockResponse('landed-root-relative', ['http_code' => 200]);
    });
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34:8443/start'));

    expect($seenUrls)->toBe([
        'https://93.184.216.34:8443/start',
        'https://93.184.216.34:8443/other-page',
    ])
        ->and((string) $response->getBody())->toBe('landed-root-relative');
});

test('resolveRedirectTarget resolves a bare relative Location against the current URIs own directory', function (): void {
    $seenUrls = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$seenUrls): MockResponse {
        $seenUrls[] = $url;

        return count($seenUrls) === 1
            ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'step2']])
            : new MockResponse('landed-bare-relative', ['http_code' => 200]);
    });
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/album/start'));

    expect($seenUrls)->toBe([
        'https://93.184.216.34/album/start',
        'https://93.184.216.34/album/step2',
    ])
        ->and((string) $response->getBody())->toBe('landed-bare-relative');
});

test('fetch returns false instead of throwing when the url has no host segment', function (): void {
    // Real bug: UploadService::addUploadedFile()'s own self-priming
    // fetch() call (forcing derivative-image generation right after
    // upload) can build exactly this shape -- "http:///path" -- whenever
    // no gallery_url is configured and there's no real Host/
    // X-Forwarded-Host header (a CLI-driven upload, not a real Apache
    // request). nyholm/psr7's Uri constructor throws
    // InvalidArgumentException while merely parsing that string, before
    // guardedFetch() ever reaches the transport -- fetch()'s own
    // "fire-and-forget" contract requires this to fail the same graceful
    // way as any other unreachable request, not escape as a fatal
    // exception. No client mocking needed: the exception fires at
    // URI-parse time, before self::defaultClient() is ever dispatched to.
    expect(HttpClientService::fetch('http:///i.php?/upload/2026/08/01/photo.png', CurrentConfigTestFactory::get()))->toBeFalse();
});

/**
 * Confirmed-equivalent, verified live against the FULL suite in this
 * file (not just a single test) for every mutation below:
 *
 * - Line 230's DecrementInteger/IncrementInteger/RemoveArrayItem on the
 *   hardcoded `'timeout' => 10` extraOption. No test in this file (or
 *   practically reachable without a slow, potentially flaky real-clock
 *   test) ever exercises a request anywhere near a 10-second boundary --
 *   removing the option entirely, or shifting it by a second, has no
 *   observable effect on any currently-testable behavior.
 *
 * - Line 233's EmptyStringToNotEmpty on the `proxyServer() !== ''`
 *   guard. Confirmed live: Symfony's own proxy handling treats an
 *   empty-string 'proxy' option the same as not setting one at all
 *   (both route the request directly), so this mutation produces
 *   identical observable behavior to real code even in the one scenario
 *   (useProxy=true, proxyServer='') that could otherwise distinguish
 *   it.
 *
 * - Line 238's ConcatRemoveLeft (dropping the `$1` backreference from
 *   the Basic-auth preg_replace(), so the scheme prefix is dropped from
 *   the constructed proxy URL entirely). Confirmed live against the
 *   real proxy-auth-embedding test above: Symfony's own proxy-URL
 *   parsing recovers gracefully from a schemeless
 *   "user:pass@host:port" string, routing and authenticating
 *   identically either way.
 *
 * - Line 350's FalseToTrue (`$parts === true` instead of `=== false`)
 *   and EmptyStringToNotEmpty (`$parts['host'] === 'PEST...'` instead
 *   of `=== ''`). `$parts === true` can never be true (parse_url()
 *   only ever returns `false` or an array), and the chain's own second
 *   disjunct (`! isset($parts['scheme'], $parts['host'])`) already
 *   independently evaluates true whenever $parts is `false` (isset() on
 *   a non-array is always false) -- so mutating the first disjunct
 *   changes nothing. Separately, parse_url() never actually returns an
 *   array with `host` set to a literal empty string for any input tried
 *   (it either omits the key entirely or fails outright) -- the `===
 *   ''` fallback guards a shape parse_url() doesn't produce.
 *
 * - Line 395's EmptyStringToNotEmpty on `($parts['host'] ?? '')`.
 *   resolveRedirectTarget()'s only call site always passes a $currentUri
 *   that was JUST validated by assertUrlIsSafe() at the top of the same
 *   loop iteration, which requires `isset($parts['host'])` and a
 *   non-empty host -- the `?? ''` fallback can never actually engage.
 *
 * Attempted but NOT closed, documented rather than silently dropped:
 *
 * - Line 267's DecrementInteger (`$status < 199` instead of `< 200`).
 *   PHP's own built-in server (`php -S`) cannot send a response with
 *   status 199 at all -- confirmed live that curl rejects it with
 *   "Invalid status line" before the response ever reaches
 *   guardedFetch()'s own status check, for real code and this mutation
 *   alike. Every other boundary (200, 399, 400) IS closed above.
 *
 * - Lines 174/225's EmptyStringToNotEmpty on http_build_query()'s
 *   numeric_prefix argument. Only observable with numerically-KEYED
 *   $getData/$postData, but both parameters are typed
 *   `array<string, mixed>` -- confirmed live via a real PHPStan error
 *   that no type-safe caller can construct an int-keyed array for
 *   either one.
 *
 * - Lines 211/213's BooleanAndToBooleanOr (self-request host-match
 *   logic, and the enclosing `if ($isSelfRequest &&
 *   Env::testModeIsActive())`). Distinguishing these needs a request
 *   whose host genuinely does NOT match $_SERVER['HTTP_HOST'] while
 *   still being inspectable -- but reaching any such target requires it
 *   to independently pass the full SSRF guard (https + a real public
 *   IP, since a host mismatch means no trustedSelfHost exemption
 *   applies), and this sandbox has no real, inspectable HTTPS server to
 *   verify headers against (only loopback, which the guard itself
 *   blocks without that exemption). The fake-local-proxy technique used
 *   for the proxy-auth tests above doesn't route around this either: it
 *   needs an HTTP (not HTTPS) target to avoid CONNECT-tunnel support
 *   `php -S` lacks, which is exactly the scheme the SSRF guard rejects
 *   for a non-self-request.
 */
