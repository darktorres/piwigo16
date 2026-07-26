<?php

declare(strict_types=1);

use Piwigo\Core\Request\MobileThemeRequest;

test('fromArray reports absent for an empty GET', function (): void {
    $request = MobileThemeRequest::fromArray([]);

    expect($request->mobilePresent)->toBeFalse()
        ->and($request->mobileRaw)->toBeNull();
});

test('fromArray reads a present mobile value verbatim', function (): void {
    $request = MobileThemeRequest::fromArray(['mobile' => '1']);

    expect($request->mobilePresent)->toBeTrue()
        ->and($request->mobileRaw)->toBe('1');
});

test('fromArray reads the literal string false verbatim', function (): void {
    $request = MobileThemeRequest::fromArray(['mobile' => 'false']);

    expect($request->mobilePresent)->toBeTrue()
        ->and($request->mobileRaw)->toBe('false');
});
