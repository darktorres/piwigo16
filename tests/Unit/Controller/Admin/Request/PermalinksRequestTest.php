<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\PermalinksRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = PermalinksRequest::fromArrays([], []);

    expect($request->catId)->toBe(0)
        ->and($request->isSetPermalink)->toBeFalse()
        ->and($request->permalink)->toBe('')
        ->and($request->isSave)->toBeFalse()
        ->and($request->deletePermanentPresent)->toBeFalse()
        ->and($request->deletePermanent)->toBe('')
        ->and($request->psfPresent)->toBeFalse()
        ->and($request->psf)->toBeNull()
        ->and($request->dpsfPresent)->toBeFalse()
        ->and($request->dpsf)->toBeNull();
});

test('fromArrays parses a full set_permalink submission', function (): void {
    $request = PermalinksRequest::fromArrays([], [
        'cat_id' => '12',
        'set_permalink' => '1',
        'permalink' => 'my-album',
        'save' => '1',
    ]);

    expect($request->catId)->toBe(12)
        ->and($request->isSetPermalink)->toBeTrue()
        ->and($request->permalink)->toBe('my-album')
        ->and($request->isSave)->toBeTrue();
});

test('fromArrays rejects a non-digit cat_id', function (): void {
    expect(fn (): PermalinksRequest => PermalinksRequest::fromArrays([], ['cat_id' => '1; DROP TABLE']))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reports deletePermanentPresent and its normalized value', function (): void {
    $request = PermalinksRequest::fromArrays(['delete_permanent' => 'abc'], []);

    expect($request->deletePermanentPresent)->toBeTrue()
        ->and($request->deletePermanent)->toBe('abc');
});

test('fromArrays normalizes a non-string delete_permanent to an empty string but still reports present', function (): void {
    $request = PermalinksRequest::fromArrays(['delete_permanent' => ['x']], []);

    expect($request->deletePermanentPresent)->toBeTrue()
        ->and($request->deletePermanent)->toBe('');
});

test('fromArrays splits psf presence from its normalized value', function (): void {
    $request = PermalinksRequest::fromArrays(['psf' => 'name'], []);

    expect($request->psfPresent)->toBeTrue()
        ->and($request->psf)->toBe('name');
});

test('fromArrays reports psfPresent true but psf null for a non-string psf', function (): void {
    $request = PermalinksRequest::fromArrays(['psf' => ['x']], []);

    expect($request->psfPresent)->toBeTrue()
        ->and($request->psf)->toBeNull();
});

test('fromArrays splits dpsf presence from its normalized value independently of psf', function (): void {
    $request = PermalinksRequest::fromArrays(['dpsf' => 'hit'], []);

    expect($request->dpsfPresent)->toBeTrue()
        ->and($request->dpsf)->toBe('hit')
        ->and($request->psfPresent)->toBeFalse();
});
