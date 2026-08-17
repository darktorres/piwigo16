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

    $result = H::rawGet($page, '/api/v1/session');

    expect($result['status'])
        ->toBe(200);
    $decoded = json_decode($result['body'], true);
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

    $result = H::rawGet($page, '/api/v1/session');

    expect($result['status'])
        ->toBe(200);
    $decoded = json_decode($result['body'], true);
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
