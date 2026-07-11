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

test('sendRequest rejects a non-https scheme before contacting the transport', function (): void {
    $calls = 0;
    $client = new MockHttpClient(function () use (&$calls): MockResponse {
        $calls++;

        return new MockResponse('unreachable');
    });
    $service = new HttpClientService($client);

    expect(fn () => $service->sendRequest(makeHttpRequest('GET', 'http://example.test/')))
        ->toThrow(HttpClientSsrfException::class);
    expect($calls)->toBe(0);
});

test('sendRequest rejects a malformed URL', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn () => $service->sendRequest(makeHttpRequest('GET', 'not-a-url')))
        ->toThrow(HttpClientSsrfException::class);
});

test('sendRequest rejects a loopback IP host', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn () => $service->sendRequest(makeHttpRequest('GET', 'https://127.0.0.1/')))
        ->toThrow(HttpClientSsrfException::class);
});

test('sendRequest rejects the link-local cloud metadata IP', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn () => $service->sendRequest(makeHttpRequest('GET', 'https://169.254.169.254/latest/meta-data/')))
        ->toThrow(HttpClientSsrfException::class);
});

test('sendRequest rejects a private RFC1918 IP host', function (): void {
    $service = new HttpClientService(new MockHttpClient());

    expect(fn () => $service->sendRequest(makeHttpRequest('GET', 'https://10.0.0.5/')))
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

    expect(fn () => $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/start')))
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

    expect(fn () => $service->sendRequest(makeHttpRequest('GET', 'https://93.184.216.34/down')))
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

    expect(fn () => $service->requestRaw('GET', 'https://192.168.1.1/', [], ''))
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

    expect(fn () => $service->requestRaw('GET', 'http://127.0.0.1/', [], ''))
        ->toThrow(HttpClientSsrfException::class);
});
