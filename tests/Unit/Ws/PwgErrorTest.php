<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Ws\PwgError;

/**
 * PwgError's constructor has exactly one branch: it only calls
 * PresentationAccessor::htmlService()->setStatusHeader() for HTTP-range
 * codes (`$code >= 400 and $code < 600`). header() itself is a genuine
 * no-op under CLI SAPI (confirmed live in HtmlServiceTest.php's own
 * docblocks -- headers_list() stays empty even after a real header()
 * call), so "was setStatusHeader() actually called" is observed the same
 * side-channel way HtmlServiceTest.php already established for that same
 * method: HtmlService::setStatusHeader() unconditionally fires the real
 * 'set_status_header' trigger_notify event as its very last step, so a
 * spy handler registered on that event proves the call happened (or
 * didn't) without needing to inspect actual HTTP headers.
 *
 * Kernel::boot() with a real Paths (same minimal setup as
 * PresentationAccessorTest.php/PwgSerialPhpEncoderTest.php's own
 * docblock) is required here because PwgError goes through the real
 * PresentationAccessor::htmlService() accessor, which needs a booted DI
 * container -- a fake/spy HtmlService isn't an option since HtmlService
 * is `final` and PresentationAccessor's own instanceof guard requires the
 * real concrete class, not an interface.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('code just below the HTTP range (399) does not call setStatusHeader', function (): void {
    $calls = [];
    $handler = static function (int $code, string $text) use (&$calls): void {
        $calls[] = [$code, $text];
    };
    EventDispatcher::get()->addEventHandler('set_status_header', $handler);

    try {
        $error = new PwgError(399, 'Not an HTTP code');

        expect($calls)->toBe([])
            ->and($error->code())->toBe(399)
            ->and($error->message())->toBe('Not an HTTP code');
    } finally {
        EventDispatcher::get()->removeEventHandler('set_status_header', $handler);
    }
});

test('code at the lower HTTP boundary (400) calls setStatusHeader', function (): void {
    $calls = [];
    $handler = static function (int $code, string $text) use (&$calls): void {
        $calls[] = [$code, $text];
    };
    EventDispatcher::get()->addEventHandler('set_status_header', $handler);

    try {
        $error = new PwgError(400, 'Bad request');

        expect($calls)->toBe([[400, 'Bad request']])
            ->and($error->code())->toBe(400)
            ->and($error->message())->toBe('Bad request');
    } finally {
        EventDispatcher::get()->removeEventHandler('set_status_header', $handler);
    }
});

test('code comfortably inside the HTTP range (404) calls setStatusHeader', function (): void {
    // Kills the GreaterOrEqualToSmaller mutant on line 27 ($code <= 400):
    // for 404, `>= 400` is true but `<= 400` is false, so a mutant using
    // `<=` would skip the call while the real code makes it.
    $calls = [];
    $handler = static function (int $code, string $text) use (&$calls): void {
        $calls[] = [$code, $text];
    };
    EventDispatcher::get()->addEventHandler('set_status_header', $handler);

    try {
        $error = new PwgError(404, 'Not found');

        expect($calls)->toBe([[404, 'Not found']])
            ->and($error->code())->toBe(404)
            ->and($error->message())->toBe('Not found');
    } finally {
        EventDispatcher::get()->removeEventHandler('set_status_header', $handler);
    }
});

test('code at the upper HTTP boundary (599) calls setStatusHeader', function (): void {
    $calls = [];
    $handler = static function (int $code, string $text) use (&$calls): void {
        $calls[] = [$code, $text];
    };
    EventDispatcher::get()->addEventHandler('set_status_header', $handler);

    try {
        $error = new PwgError(599, 'Custom 599');

        expect($calls)->toBe([[599, 'Custom 599']])
            ->and($error->code())->toBe(599)
            ->and($error->message())->toBe('Custom 599');
    } finally {
        EventDispatcher::get()->removeEventHandler('set_status_header', $handler);
    }
});

test('code just past the HTTP range (600) does not call setStatusHeader', function (): void {
    $calls = [];
    $handler = static function (int $code, string $text) use (&$calls): void {
        $calls[] = [$code, $text];
    };
    EventDispatcher::get()->addEventHandler('set_status_header', $handler);

    try {
        $error = new PwgError(600, 'Not an HTTP code either');

        expect($calls)->toBe([])
            ->and($error->code())->toBe(600)
            ->and($error->message())->toBe('Not an HTTP code either');
    } finally {
        EventDispatcher::get()->removeEventHandler('set_status_header', $handler);
    }
});

test('code comfortably outside the HTTP range (1003) does not call setStatusHeader', function (): void {
    // WsError::INVALID_PARAM-style application error codes (>= 1000) are
    // the class's other real-world usage (see PwgSerialPhpEncoderTest.php's
    // own docblock) -- confirms the guard also holds well clear of the
    // upper boundary, not just immediately past it.
    $calls = [];
    $handler = static function (int $code, string $text) use (&$calls): void {
        $calls[] = [$code, $text];
    };
    EventDispatcher::get()->addEventHandler('set_status_header', $handler);

    try {
        $error = new PwgError(1003, 'Invalid param foo');

        expect($calls)->toBe([])
            ->and($error->code())->toBe(1003)
            ->and($error->message())->toBe('Invalid param foo');
    } finally {
        EventDispatcher::get()->removeEventHandler('set_status_header', $handler);
    }
});
