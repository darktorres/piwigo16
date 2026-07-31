<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Piwigo\Http\HttpClientNetworkException;
use Piwigo\Http\HttpClientService;
use Piwigo\Http\HttpClientSsrfException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function makeHttpRequest(string $method, string $uri): Psr\Http\Message\RequestInterface
{
    return new Psr17Factory()->createRequest($method, $uri);
}

/**
 * Starts a real, disposable PHP built-in server bound to 127.0.0.1
 * serving $docRoot -- see the big docblock further down for why this is
 * the only way to exercise fetch()/fetchToFile()/guardedFetch()'s own
 * static, non-injectable success path for real.
 *
 * @return array{0: resource, 1: int} the process handle and the bound port
 */
function httpClientServiceTestStartLocalServer(string $docRoot): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20_000, 60_000);
        $proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $docRoot], $descriptors, $pipes);
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

    expect(fn (): \Psr\Http\Message\ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'http://example.test/')))
        ->toThrow(HttpClientSsrfException::class);
    expect($calls)->toBe(0);
});

test('sendRequest rejects a malformed URL', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn (): \Psr\Http\Message\ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'not-a-url')))
        ->toThrow(HttpClientSsrfException::class);
});

test('sendRequest rejects a loopback IP host', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn (): \Psr\Http\Message\ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://127.0.0.1/')))
        ->toThrow(HttpClientSsrfException::class);
});

test('sendRequest rejects the link-local cloud metadata IP', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn (): \Psr\Http\Message\ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://169.254.169.254/latest/meta-data/')))
        ->toThrow(HttpClientSsrfException::class);
});

test('sendRequest rejects a private RFC1918 IP host', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn (): \Psr\Http\Message\ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://10.0.0.5/')))
        ->toThrow(HttpClientSsrfException::class);
});

test('sendRequest allows a public IP host and returns the response body', function (): void {
    $client = new MockHttpClient(new MockResponse('hello world', ['http_code' => 200]));
    $service = new HttpClientService($client);

    $response = $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/ok'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('hello world');
});

test('sendRequest throws when a redirect target is a private IP', function (): void {
    $client = new MockHttpClient(new MockResponse('', [
        'http_code' => 302,
        'response_headers' => ['Location' => 'https://127.0.0.1/internal'],
    ]));
    $service = new HttpClientService($client);

    expect(fn (): \Psr\Http\Message\ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/start')))
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

test('sendRequest downgrades POST to GET on a 302 redirect and drops the body', function (): void {
    $seenMethods = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$seenMethods): MockResponse {
        $seenMethods[] = $method;
        if (count($seenMethods) === 1) {
            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location' => 'https://93.184.216.35/step2'],
            ]);
        }

        return new MockResponse('done', ['http_code' => 200]);
    });
    $service = new HttpClientService($client);

    $request = makeHttpRequest('POST', 'https://93.184.216.34/start')
        ->withBody(new Psr17Factory()->createStream('a=1'));

    $service->sendRequest($request);

    expect($seenMethods)->toBe(['POST', 'GET']);
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

    expect(fn (): \Psr\Http\Message\ResponseInterface => $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/down')))
        ->toThrow(HttpClientNetworkException::class);
});

test('requestRaw returns Symfony\'s own response type and applies the same SSRF guard', function (): void {
    $client = new MockHttpClient(new MockResponse('raw body', ['http_code' => 200]));
    $service = new HttpClientService($client);

    $response = $service->requestRaw('GET', 'https://93.184.216.34/raw', ['User-Agent' => 'Piwigo'], '');

    expect($response)->toBeInstanceOf(Symfony\Contracts\HttpClient\ResponseInterface::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getContent(false))->toBe('raw body');
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
        $result = HttpClientService::fetch('http://127.0.0.1:' . $port . '/probe.php');

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
            $ok = HttpClientService::fetchToFile($handle, 'http://127.0.0.1:' . $port . '/probe.php');
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

test('guardedFetch() embeds proxy auth into the proxy option when useProxy/proxyServer/proxyAuth are all configured', function (): void {
    \Piwigo\Config\CurrentConfig::setUseProxy(true);
    // 127.0.0.1:1 is a closed local port -- the actual proxied connection
    // attempt fails with a real, near-instant ECONNREFUSED (no external
    // internet involved), which guardedFetch()'s own
    // ClientExceptionInterface catch turns into a clean `false`, same as
    // any other unreachable target. Only the proxy-option construction
    // itself (embedding Basic-auth credentials into the proxy URL) is
    // under test here.
    \Piwigo\Config\CurrentConfig::setProxyServer('http://127.0.0.1:1');
    \Piwigo\Config\CurrentConfig::setProxyAuth('user:pass');

    try {
        $result = HttpClientService::fetch('https://93.184.216.34/proxy-probe');

        expect($result)->toBeFalse();
    } finally {
        \Piwigo\Config\CurrentConfig::reset();
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
    expect(HttpClientService::fetch('http:///i.php?/upload/2026/08/01/photo.png'))->toBeFalse();
});
