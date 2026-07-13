<?php

declare(strict_types=1);

use Piwigo\Controller\LegacyRenderCapture;

test('capture returns the echoed output as a string', function (): void {
    $result = LegacyRenderCapture::capture(static function (): void {
        echo 'hello';
    });

    expect($result)->toBe('hello');
});

test('capture concatenates every echo call inside the closure', function (): void {
    $result = LegacyRenderCapture::capture(static function (): void {
        echo 'a';
        echo 'b';
        echo 'c';
    });

    expect($result)->toBe('abc');
});

test('capture returns an empty string when nothing is echoed', function (): void {
    $result = LegacyRenderCapture::capture(static function (): void {
        // no output
    });

    expect($result)->toBe('');
});

test('capture does not leak an output buffer when the closure throws', function (): void {
    $levelBefore = ob_get_level();

    expect(static fn () => LegacyRenderCapture::capture(static function (): void {
        echo 'partial output';
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect(ob_get_level())->toBe($levelBefore);
});
