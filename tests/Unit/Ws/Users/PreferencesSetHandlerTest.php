<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Users\PreferencesSetHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Users\PreferencesSetHandler -- `pwg.users.preferences.set`.
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers the `param` name regex validation, its only branch reachable
 * without a real, non-guest session -- there is no CSRF guard on this
 * method, unlike most other mutating Users handlers.
 */
function pwgUsersPreferencesSetHandlerTestSubject(): PreferencesSetHandler
{
    $handler = Kernel::container()->get(PreferencesSetHandler::class);
    if (! $handler instanceof PreferencesSetHandler) {
        throw new LogicException('Container returned an unexpected type for ' . PreferencesSetHandler::class);
    }

    return $handler;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('returns a WsErrorResponse when the param name contains characters outside [a-zA-Z0-9_-]', function (): void {
    $handler = pwgUsersPreferencesSetHandlerTestSubject();

    $result = $handler([
        'param' => 'not a valid name!',
        'value' => 'x',
        'is_json' => false,
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(1003)
            ->and($result->message())
            ->toBe('Invalid param name #not a valid name!#');
    }
});
