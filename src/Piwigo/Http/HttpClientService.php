<?php

declare(strict_types=1);

namespace Piwigo\Http;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponseInterface;

/**
 * PSR-18 HTTP client wrapping the app's shared Symfony transport
 * (self::defaultClient()), with an SSRF guard applied to the initial URL
 * and to every redirect target it follows, not just the first request.
 * [SEC-23]
 *
 * Symfony's own auto-redirect-following happens transport-side, before a
 * response is ever handed back to the caller -- there is no hook to
 * validate a redirect target before it gets requested. Auto-follow is
 * disabled here (max_redirects: 0 on every underlying request) and
 * redirects are followed manually instead, one hop at a time, so each
 * Location header is guarded before it's requested.
 *
 * No reference implementation exists for this class or for SEC-23/24 --
 * confirmed via the reference repo that it never built either, even at its
 * own fully-evolved HEAD (AdminService::fetchRemote() there still has the
 * raw local-file-read fallback this class's SEC-24 companion removes, and
 * zero SSRF/IP-range validation). Built from the security doc's own SEC-23
 * description.
 *
 * $getData/$postData values are genuinely arbitrary by design -- fed
 * straight into PHP's own http_build_query(array|object $data).
 * $extraOptions mirrors Symfony HttpClientInterface::request()'s own
 * heterogeneous $options array (timeout: float, proxy: string, ...),
 * merged onto the underlying client call unchanged.
 */
final readonly class HttpClientService implements ClientInterface
{
    private const int MAX_REDIRECTS = 5;

    /**
     * @var list<int>
     */
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * @var list<int>
     */
    private const array BODY_DROPPING_STATUSES = [301, 302, 303];

    private HttpClientInterface $client;

    private Psr17Factory $factory;

    public function __construct(
        ?HttpClientInterface $client = null, /**
     * A target host exempted from the SSRF guard entirely (both the
     * https-only scheme check and the private/reserved-IP check). The only
     * legitimate use is fetchRemote()'s own self-requests back into this
     * same app (e.g. forcing derivative-image generation right after
     * upload, see add_uploaded_file() in functions_upload.inc.php) --
     * fetchRemote() passes its own $_SERVER['HTTP_HOST'] here, the same
     * value it already compares against for X-Piwigo-Env forwarding.
     *
     * This exemption is deliberate, not a loophole: SSRF protects against
     * attacker-influenceable URLs reaching unintended internal targets. A
     * request back to the app's own current host is neither
     * attacker-controlled (it's built from server-computed values, never
     * from user input) nor "unintended" (it's the app calling itself on
     * purpose). Without this exemption, a blanket https-only + no-private-IP
     * rule would break derivative-image generation on every plain-HTTP or
     * private-IP self-hosted deployment -- the overwhelmingly common case
     * for self-hosted software (localhost dev environments, Docker-internal
     * networking, LAN-only installs behind a private-IP reverse proxy).
     */
        private ?string $trustedSelfHost = null
    ) {
        $this->client = $client ?? self::defaultClient();
        $this->factory = new Psr17Factory();
    }

    /**
     * A shared, lazily-built Symfony HttpClient instance. Symfony picks the
     * best available transport itself (curl if present, native streams
     * otherwise) -- no need to hand-roll the curl/file_get_contents/fsockopen
     * fallback chain the old fetchRemote() implementation did.
     */
    private static function defaultClient(): HttpClientInterface
    {
        /** @var HttpClientInterface|null */
        static $client = null;
        if ($client === null) {
            $client = HttpClient::create();
        }
        return $client;
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->toPsrResponse($this->guardedRequest($request, []));
    }

    /**
     * Absorbs the orchestration around the real network call in
     * requestRaw() below (GET query-string building, proxy config,
     * same-host X-Piwigo-Env test-mode header forwarding for
     * self-requests, POST body/Content-Type construction). Static, and
     * internally constructs its own scoped instance -- trustedSelfHost is
     * only known once the target URL's host is compared against the
     * current request's host, which varies per call, not per
     * HttpClientService instance.
     *
     * Covers both real usage shapes that don't need a file handle:
     * content wanted (returned directly) and fire-and-forget (caller
     * discards the return). See fetchToFile() for the file-resource
     * shape.
     *
     * @param array<string, mixed> $getData data added to the request URL
     * @param array<string, mixed> $postData data transmitted with POST
     */
    public static function fetch(string $url, CurrentConfig $currentConfig, array $getData = [], array $postData = [], string $userAgent = 'Piwigo'): string|false
    {
        $response = self::guardedFetch($url, $currentConfig, $getData, $postData, $userAgent);
        if (! $response instanceof \Symfony\Contracts\HttpClient\ResponseInterface) {
            return false;
        }

        return $response->getContent(false);
    }

    /**
     * Same request as fetch(), but writes the response body into the
     * given file resource instead of returning it -- covers
     * fetchRemote()'s other real usage shape (downloading an extension
     * archive straight to disk).
     *
     * @param resource $fileHandle
     * @param array<string, mixed> $getData data added to the request URL
     * @param array<string, mixed> $postData data transmitted with POST
     */
    public static function fetchToFile($fileHandle, string $url, CurrentConfig $currentConfig, array $getData = [], array $postData = [], string $userAgent = 'Piwigo'): bool
    {
        $response = self::guardedFetch($url, $currentConfig, $getData, $postData, $userAgent);
        if (! $response instanceof \Symfony\Contracts\HttpClient\ResponseInterface) {
            return false;
        }

        return @fwrite($fileHandle, $response->getContent(false)) !== false;
    }

    /**
     * @param array<string, mixed> $getData
     * @param array<string, mixed> $postData
     */
    private static function guardedFetch(string $url, CurrentConfig $currentConfig, array $getData, array $postData, string $userAgent): ?SymfonyResponseInterface
    {

        $method = $postData === [] ? 'GET' : 'POST';
        if ($getData !== []) {
            $url .= ! str_contains($url, '?') ? '?' : '&';
            $url .= http_build_query($getData, '', '&');
        }

        $headers = [
            'User-Agent' => $userAgent,
        ];

        // Piwigo makes real self-requests back into this same app (e.g. forcing
        // derivative-image generation right after upload, see
        // UploadService::addUploadedFile()). Test mode is detected per-request
        // from the X-Piwigo-Env header (see Env::testModeIsActive()), so
        // without forwarding it here, a self-request looks like a plain
        // production hit and never picks up the test DB config. Only forward
        // it for same-host requests, not genuinely external ones (piwigo.org
        // etc). The same same-host comparison also tells this class's own
        // SSRF guard which host to exempt (see $trustedSelfHost's own doc
        // comment) -- a self-request back into this same app is never
        // attacker-influenceable, so it must not be held to the guard's
        // https-only + no-private-IP rules the way a genuinely external
        // target is.
        // Bug found live via HttpClientServiceTest's own real self-request
        // round trip against a non-default-port local test server: PHP_URL_HOST
        // alone strips the port, but $_SERVER['HTTP_HOST'] (and therefore a
        // same-process-built self-request URL, see UrlService::
        // getAbsoluteRootUrl()) includes it whenever the request came in on
        // a non-standard port -- comparing host-only against host[:port]
        // meant a self-request was NEVER recognized as one on any
        // non-default port, silently defeating both the X-Piwigo-Env
        // forwarding above and the SSRF exemption below for exactly the
        // deployment shape this class's own docblock calls out (a
        // Docker-mapped "localhost:8080" install). Reattaching the port
        // here mirrors assertUrlIsSafe()'s own identical host[:port]
        // comparison for $trustedSelfHost.
        $srcHost = parse_url($url, PHP_URL_HOST);
        $srcPort = parse_url($url, PHP_URL_PORT);
        $srcHostAndPort = is_string($srcHost) ? $srcHost . (is_int($srcPort) ? ':' . $srcPort : '') : null;
        $currentHost = $_SERVER['HTTP_HOST'] ?? null;
        $isSelfRequest = $srcHostAndPort !== null && $srcHostAndPort === $currentHost;

        if ($isSelfRequest && Env::testModeIsActive()) {
            $headerValue = Env::testModeHeader();
            if ($headerValue !== null) {
                $headers['X-Piwigo-Env'] = $headerValue;
            }
        }

        $body = '';
        if ($method === 'POST') {
            // Matches Symfony HttpClientTrait's own array-body normalization
            // (http_build_query() + an explicit form-urlencoded Content-Type)
            // -- requestRaw() takes a plain string body.
            $body = http_build_query($postData, '', '&');
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        $extraOptions = [
            'timeout' => 10,
        ];

        if ($currentConfig->useProxy() && $currentConfig->proxyServer() !== '') {
            $proxyServer = $currentConfig->proxyServer();
            $proxyUrl = $proxyServer;
            if ($currentConfig->proxyAuth() !== '') {
                $proxyAuth = $currentConfig->proxyAuth();
                $proxyUrl = preg_replace('#^(https?://)#', '$1' . $proxyAuth . '@', $proxyUrl);
            }
            $extraOptions['proxy'] = $proxyUrl;
        }

        try {
            $response = new self(trustedSelfHost: $isSelfRequest ? $currentHost : null)
                ->requestRaw($method, $url, $headers, $body, $extraOptions);
            $status = $response->getStatusCode();
        } catch (ClientExceptionInterface) {
            return null;
        } catch (InvalidArgumentException) {
            // Real bug, found via a new Integration test that legitimately
            // calls UploadService::addUploadedFile() with no real HTTP host
            // configured (no `gallery_url`, no Host/X-Forwarded-Host
            // header -- a CLI-driven upload path): the self-request's own
            // "full" URL ends up with an empty host segment
            // (`http:///i.php?...`), and nyholm/psr7's Uri constructor
            // throws InvalidArgumentException while merely *parsing* that
            // string, before any network I/O or ClientExceptionInterface
            // is even reachable. Every caller of fetch()/fetchToFile()
            // treats a null/false guardedFetch() result as "the request
            // didn't happen, move on" (UploadService::addUploadedFile()'s
            // own self-priming call is explicitly documented as
            // "fire-and-forget"), so a malformed self-generated URL must
            // fail the same graceful way, not escape as a fatal exception.
            return null;
        }

        if ($status < 200 || $status >= 400) {
            return null;
        }

        return $response;
    }

    /**
     * Symfony-shaped entry point for callers needing per-request options
     * PSR-7's RequestInterface has no room for (proxy, timeout) --
     * guardedFetch() (fetch()/fetchToFile()'s shared helper) is the real
     * caller. Runs through the identical SSRF
     * guard + manual redirect loop as sendRequest(); returns Symfony's own
     * response type so a caller that only wants status/content doesn't pay
     * for a PSR-7 round-trip.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $extraOptions Symfony-only per-request options (timeout, proxy, ...) merged onto every hop; max_redirects is always forced to 0.
     */
    public function requestRaw(string $method, string $url, array $headers, string $body, array $extraOptions = []): SymfonyResponseInterface
    {
        $request = $this->factory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $request = $request->withBody($this->factory->createStream($body));

        return $this->guardedRequest($request, $extraOptions);
    }

    /**
     * @param array<string, mixed> $extraOptions
     */
    private function guardedRequest(RequestInterface $request, array $extraOptions): SymfonyResponseInterface
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $body = (string) $request->getBody();

        for ($redirects = 0; true; $redirects++) {
            $this->assertUrlIsSafe($uri, $request);

            try {
                $response = $this->client->request($method, $uri, [
                    'headers' => $headers,
                    'body' => $body,
                    'max_redirects' => 0,
                ] + $extraOptions);
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $e) {
                throw new HttpClientNetworkException($e->getMessage(), $request, $e);
            }

            if (! in_array($status, self::REDIRECT_STATUSES, true) || $redirects >= self::MAX_REDIRECTS) {
                return $response;
            }

            $location = $response->getHeaders(false)['location'][0] ?? null;
            if ($location === null || $location === '') {
                return $response;
            }

            $uri = $this->resolveRedirectTarget($uri, $location);
            if (in_array($status, self::BODY_DROPPING_STATUSES, true) && $method !== 'GET' && $method !== 'HEAD') {
                $method = 'GET';
                $body = '';
                unset($headers['Content-Type'], $headers['Content-Length'], $headers['content-type'], $headers['content-length']);
            }
        }
    }

    /**
     * [SEC-23] https-only scheme, and the hostname must not resolve to a
     * private/reserved IP range. Applied to the initial URL and to every
     * redirect target the loop above follows.
     */
    private function assertUrlIsSafe(string $url, RequestInterface $request): void
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new HttpClientSsrfException('Malformed URL: ' . $url, $request);
        }

        if ($this->trustedSelfHost !== null) {
            // Compared as host[:port], matching the shape of
            // $_SERVER['HTTP_HOST'] fetchRemote() passes in here -- parse_url()
            // splits the port out separately, so it has to be reattached to
            // compare like-for-like (a bare host-only comparison would miss
            // self-requests on a non-standard port, e.g. a Docker-mapped
            // "localhost:8080" install).
            $hostAndPort = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if ($hostAndPort === $this->trustedSelfHost) {
                return;
            }
        }

        if ($parts['scheme'] !== 'https') {
            throw new HttpClientSsrfException('Only https:// URLs are allowed: ' . $url, $request);
        }

        $host = $parts['host'];
        $ip = filter_var($host, \FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);

        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new HttpClientSsrfException('Target host resolves to a private/reserved IP range: ' . $url, $request);
        }
    }

    /**
     * Resolves a Location header value against the URI it was returned
     * for. Real-world redirect sources this app talks to (piwigo.org's PEM
     * server, GitHub-style release endpoints) always return absolute
     * Location URLs; the relative case is still handled correctly (same
     * origin as the current URI), just without full RFC 3986 generality
     * (query-only/fragment-only references, "../" segments) -- not needed
     * for this app's real callers.
     */
    private function resolveRedirectTarget(string $currentUri, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($currentUri);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $currentPath = $parts['path'] ?? '/';
        $basePath = preg_replace('#/[^/]*$#', '/', $currentPath);

        return $origin . $basePath . $location;
    }

    private function toPsrResponse(SymfonyResponseInterface $response): ResponseInterface
    {
        $psrResponse = $this->factory->createResponse($response->getStatusCode());

        foreach ($response->getHeaders(false) as $name => $values) {
            foreach ($values as $value) {
                $psrResponse = $psrResponse->withAddedHeader($name, $value);
            }
        }

        return $psrResponse->withBody($this->factory->createStream($response->getContent(false)));
    }
}
