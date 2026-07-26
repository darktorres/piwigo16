<?php

declare(strict_types=1);

use Piwigo\Admin\Request\UserPermSubmitRequest;

test('fromArrays reports not submitted and empty lists for an empty POST', function (): void {
    $request = UserPermSubmitRequest::fromArrays([], []);

    expect($request->isSubmitted)->toBeFalse()
        ->and($request->catTrue)->toBe([])
        ->and($request->catFalse)->toBe([]);
});

test('fromArrays parses cat_true/cat_false from a full submission', function (): void {
    $request = UserPermSubmitRequest::fromArrays([], [
        'cat_true' => ['1', '2'],
        'cat_false' => ['3', '4'],
        'falsify' => '1',
    ]);

    expect($request->isSubmitted)->toBeTrue()
        ->and($request->catTrue)->toBe(['1', '2'])
        ->and($request->catFalse)->toBe([3, 4])
        ->and($request->isFalsify)->toBeTrue()
        ->and($request->isTrueify)->toBeFalse();
});

test('fromArrays rejects a non-digit cat_true element', function (): void {
    expect(fn (): UserPermSubmitRequest => UserPermSubmitRequest::fromArrays([], ['cat_true' => ['1; DROP TABLE']]))
        ->toThrow(RuntimeException::class);
});

test('fromArrays passes user_id through from GET', function (): void {
    $request = UserPermSubmitRequest::fromArrays(['user_id' => '42'], []);

    expect($request->userId)->toBe('42');
});

test('fromArrays returns a null user_id when absent', function (): void {
    $request = UserPermSubmitRequest::fromArrays([], []);

    expect($request->userId)->toBeNull();
});
