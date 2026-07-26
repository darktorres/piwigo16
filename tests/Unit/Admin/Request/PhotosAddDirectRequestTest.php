<?php

declare(strict_types=1);

use Piwigo\Admin\Request\PhotosAddDirectRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = PhotosAddDirectRequest::fromArrays([], [], true);

    expect($request->batchPresent)->toBeFalse()
        ->and($request->batch)->toBe('')
        ->and($request->displayFormats)->toBeFalse()
        ->and($request->formatsTruthy)->toBeFalse()
        ->and($request->formatsId)->toBe('')
        ->and($request->albumPresent)->toBeFalse()
        ->and($request->albumId)->toBeNull()
        ->and($request->postLevel)->toBe(0)
        ->and($request->hideWarningsPresent)->toBeFalse();
});

test('fromArrays parses batch as a raw string', function (): void {
    $request = PhotosAddDirectRequest::fromArrays(['batch' => '1,2,3'], [], true);

    expect($request->batchPresent)->toBeTrue()
        ->and($request->batch)->toBe('1,2,3');
});

test('fromArrays rejects a malformed batch', function (): void {
    expect(fn (): PhotosAddDirectRequest => PhotosAddDirectRequest::fromArrays(['batch' => '1; DROP TABLE'], [], true))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reports displayFormats false when the feature is disabled even if formats is present', function (): void {
    $request = PhotosAddDirectRequest::fromArrays(['formats' => '5'], [], false);

    expect($request->displayFormats)->toBeFalse()
        ->and($request->formatsTruthy)->toBeFalse();
});

test('fromArrays skips formats validation when the feature is disabled', function (): void {
    $request = PhotosAddDirectRequest::fromArrays(['formats' => '1; DROP TABLE'], [], false);

    expect($request->formatsTruthy)->toBeFalse()
        ->and($request->formatsId)->toBe('');
});

test('fromArrays reports formatsTruthy and formatsId when enabled and present', function (): void {
    $request = PhotosAddDirectRequest::fromArrays(['formats' => '5'], [], true);

    expect($request->displayFormats)->toBeTrue()
        ->and($request->formatsTruthy)->toBeTrue()
        ->and($request->formatsId)->toBe('5');
});

test('fromArrays rejects a malformed formats id when enabled and present', function (): void {
    expect(fn (): PhotosAddDirectRequest => PhotosAddDirectRequest::fromArrays(['formats' => '1; DROP TABLE'], [], true))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reports displayFormats false but no crash when formats is an empty string', function (): void {
    $request = PhotosAddDirectRequest::fromArrays(['formats' => ''], [], true);

    expect($request->displayFormats)->toBeTrue()
        ->and($request->formatsTruthy)->toBeFalse();
});

test('fromArrays parses a numeric album', function (): void {
    $request = PhotosAddDirectRequest::fromArrays(['album' => '12'], [], true);

    expect($request->albumPresent)->toBeTrue()
        ->and($request->albumId)->toBe(12);
});

test('fromArrays rejects a non-digit album', function (): void {
    expect(fn (): PhotosAddDirectRequest => PhotosAddDirectRequest::fromArrays(['album' => '1; DROP TABLE'], [], true))
        ->toThrow(RuntimeException::class);
});

test('fromArrays passes postLevel through raw from POST', function (): void {
    $request = PhotosAddDirectRequest::fromArrays([], ['level' => '8'], true);

    expect($request->postLevel)->toBe('8');
});

test('fromArrays reports hideWarningsPresent when present', function (): void {
    $request = PhotosAddDirectRequest::fromArrays(['hide_warnings' => '1'], [], true);

    expect($request->hideWarningsPresent)->toBeTrue();
});
