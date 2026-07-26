<?php

declare(strict_types=1);

use Piwigo\Auth\CookieService;
use Piwigo\Controller\Request\IdentificationSubmitRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = IdentificationSubmitRequest::fromArrays([], []);

    expect($request->postRedirectDecoded)->toBeNull()
        ->and($request->getRedirect)->toBeNull()
        ->and($request->hideRedirectErrorPresent)->toBeFalse()
        ->and($request->isLoginSubmitted)->toBeFalse()
        ->and($request->username)->toBe('')
        ->and($request->password)->toBeNull()
        ->and($request->isRememberMe)->toBeFalse();
});

test('fromArrays decodes redirect from GET', function (): void {
    $request = IdentificationSubmitRequest::fromArrays(['redirect' => 'admin.php%3Fpage%3Dcat_list'], []);

    expect($request->getRedirect)->toBe('admin.php%3Fpage%3Dcat_list');
});

test('fromArrays returns null getRedirect for an empty string', function (): void {
    $request = IdentificationSubmitRequest::fromArrays(['redirect' => ''], []);

    expect($request->getRedirect)->toBeNull();
});

test('fromArrays reports hideRedirectErrorPresent when present', function (): void {
    $request = IdentificationSubmitRequest::fromArrays(['hide_redirect_error' => '1'], []);

    expect($request->hideRedirectErrorPresent)->toBeTrue();
});

test('fromArrays decodes postRedirectDecoded from a string POST redirect', function (): void {
    $decoded = new CookieService()->cookiePath() . 'admin.php?page=cat_list';
    $request = IdentificationSubmitRequest::fromArrays([], ['redirect' => urlencode($decoded)]);

    expect($request->postRedirectDecoded)->toBe($decoded);
});

test('fromArrays rejects a decoded POST redirect outside the cookie path', function (): void {
    expect(fn (): IdentificationSubmitRequest => IdentificationSubmitRequest::fromArrays([], ['redirect' => 'https%3A%2F%2Fevil.example%2F']))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses a full login submission', function (): void {
    $request = IdentificationSubmitRequest::fromArrays([], [
        'login' => '1',
        'username' => 'alice',
        'password' => 'secret',
        'remember_me' => '1',
    ]);

    expect($request->isLoginSubmitted)->toBeTrue()
        ->and($request->username)->toBe('alice')
        ->and($request->password)->toBe('secret')
        ->and($request->isRememberMe)->toBeTrue();
});

test('fromArrays normalizes a non-string username to an empty string', function (): void {
    $request = IdentificationSubmitRequest::fromArrays([], ['username' => ['x']]);

    expect($request->username)->toBe('');
});

test('fromArrays keeps password null when absent', function (): void {
    $request = IdentificationSubmitRequest::fromArrays([], ['login' => '1']);

    expect($request->password)->toBeNull();
});

test('fromArrays requires remember_me to be the literal string 1', function (): void {
    expect(IdentificationSubmitRequest::fromArrays([], ['remember_me' => '0'])->isRememberMe)->toBeFalse()
        ->and(IdentificationSubmitRequest::fromArrays([], ['remember_me' => '1'])->isRememberMe)->toBeTrue();
});
