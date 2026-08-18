<?php

declare(strict_types=1);

use Piwigo\Core\AppInfo;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * `GET /api/v1/session` -- `pwg.session.getStatus`'s real replacement.
 * Real end-to-end proof (over a real browser session, so real cookies
 * carry) that `/api/v1` resolves the same session-based auth every other
 * admin page already gets, no special-casing needed for this surface.
 */
it('reports guest status for an anonymous visitor', function (): void {
    $page = H::visitPwg($this, '/identification.php');

    $exchange = H::apiFetchPsr7($page, 'GET', '/api/v1/session');
    $this->assertPsr7ExchangeMatchesOpenApiSchema($exchange['request'], $exchange['response']);

    expect($exchange['response']->getStatusCode())
        ->toBe(200);
    $decoded = json_decode((string) $exchange['response']->getBody(), true);
    $body = is_array($decoded) ? $decoded : [];
    expect($body['username'])->toBe('guest')
        ->and($body['status'])->toBe('guest')
        ->and($body['version'])->toBe(AppInfo::VERSION)
        ->and($body['charset'])->toBe('utf-8')
        ->and($body)
        ->not->toHaveKey('uploadFileTypes')
        ->and($body)
        ->not->toHaveKey('uploadFormChunkSize');
});

it('reports admin status and the extra upload fields for a logged-in admin', function (): void {
    $page = H::loginAsAdmin($this);

    $exchange = H::apiFetchPsr7($page, 'GET', '/api/v1/session');
    $this->assertPsr7ExchangeMatchesOpenApiSchema($exchange['request'], $exchange['response']);

    expect($exchange['response']->getStatusCode())
        ->toBe(200);
    $decoded = json_decode((string) $exchange['response']->getBody(), true);
    $body = is_array($decoded) ? $decoded : [];
    expect($body['username'])->toBe(H::ADMIN_USER)
        ->and($body['status'])->toBe('webmaster')
        ->and($body['pwgToken'])->toBeString()
        ->and($body['pwgToken'])->not->toBe('')
        ->and($body)
        ->toHaveKey('uploadFileTypes')
        ->and($body)
        ->toHaveKey('uploadFormChunkSize');
});

it('POST /api/v1/session (login) reports the new status in its own response, not the pre-login one', function (): void {
    // Regression coverage: SessionLoginController used to return this
    // endpoint's own response before CurrentUser was refreshed, so a
    // successful login's own response body still reported the guest
    // status it had at the start of the request -- only a *separate*
    // follow-up GET /api/v1/session picked up the real, now-authenticated
    // state. Asserting on this same POST's own response is the point;
    // loginAsAdmin()'s own UI-driven flow doesn't exercise this endpoint
    // at all (identification.php's own real login, not this REST route).
    $page = H::visitPwg($this, '/identification.php');

    $exchange = H::apiFetchPsr7($page, 'POST', '/api/v1/session', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
    ]);
    $this->assertPsr7ExchangeMatchesOpenApiSchema($exchange['request'], $exchange['response']);

    expect($exchange['response']->getStatusCode())
        ->toBe(200);
    $decoded = json_decode((string) $exchange['response']->getBody(), true);
    $body = is_array($decoded) ? $decoded : [];
    expect($body['username'])->toBe(H::ADMIN_USER)
        ->and($body['status'])->toBe('webmaster');
});
