<?php

declare(strict_types=1);

use Piwigo\Core\WsContext;

beforeEach(function (): void {
    WsContext::reset();
});

afterEach(function (): void {
    WsContext::reset();
});

test('mark activates the flag, reset clears it back', function (): void {
    expect(WsContext::isActive())->toBeFalse();

    WsContext::mark();

    expect(WsContext::isActive())->toBeTrue();

    WsContext::reset();

    expect(WsContext::isActive())->toBeFalse();
});
