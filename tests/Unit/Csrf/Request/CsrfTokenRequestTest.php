<?php

declare(strict_types=1);

use Piwigo\Csrf\Request\CsrfTokenRequest;

test('fromArray returns null for an empty REQUEST', function (): void {
    $request = CsrfTokenRequest::fromArray([]);

    expect($request->submittedToken)->toBeNull();
});

test('fromArray returns null for an empty-string token', function (): void {
    $request = CsrfTokenRequest::fromArray(['pwg_token' => '']);

    expect($request->submittedToken)->toBeNull();
});

test('fromArray returns null for a non-string token', function (): void {
    $request = CsrfTokenRequest::fromArray(['pwg_token' => ['nested']]);

    expect($request->submittedToken)->toBeNull();
});

test('fromArray reads a non-empty string token', function (): void {
    $request = CsrfTokenRequest::fromArray(['pwg_token' => 'abc123']);

    expect($request->submittedToken)->toBe('abc123');
});
