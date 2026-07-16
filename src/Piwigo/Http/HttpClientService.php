<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponseInterface;

/**
 * PSR-18 HTTP client wrapping the app's shared Symfony transport
 * (self::defaultClient(), P23 batch 8d -- ported from the deleted
 * admin/include/functions.php's pwg_http_client(), whose only real
 * caller was this class's own constructor default), with an SSRF guard
 * applied to the initial URL and to every redirect target it follows,
 * not just the first request. [SEC-23]
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
 */
final class HttpClientService implements ClientInterface
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

    private readonly HttpClientInterface $client;

    private readonly Psr17Factory $factory;

    /**
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
    private readonly ?string $trustedSelfHost;

    public function __construct(?HttpClientInterface $client = null, ?string $trustedSelfHost = null)
    {
        $this->client = $client ?? self::defaultClient();
        $this->factory = new Psr17Factory();
        $this->trustedSelfHost = $trustedSelfHost;
    }

    /**
     * A shared, lazily-built Symfony HttpClient instance. Symfony picks the
     * best available transport itself (curl if present, native streams
     * otherwise) -- no need to hand-roll the curl/file_get_contents/fsockopen
     * fallback chain the old fetchRemote() implementation did.
     */
    private static function defaultClient(): HttpClientInterface
    {
        /** @var HttpClientInterface|null $client */
        static $client = null;
        if ($client === null) {
            $client = \Symfony\Component\HttpClient\HttpClient::create();
        }
        return $client;
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->toPsrResponse($this->guardedRequest($request, []));
    }

    /**
     * Symfony-shaped entry point for callers needing per-request options
     * PSR-7's RequestInterface has no room for (proxy, timeout) --
     * fetchRemote() is the real caller. Runs through the identical SSRF
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
