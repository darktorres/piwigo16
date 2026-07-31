<?php

declare(strict_types=1);

use Piwigo\Admin\Request\GroupPermSubmitRequest;

test('fromArrays reports not submitted and empty lists for an empty POST', function (): void {
    $request = GroupPermSubmitRequest::fromArrays([], []);

    expect($request->isSubmitted)->toBeFalse()
        ->and($request->catTrue)->toBe([])
        ->and($request->catFalse)->toBe([]);
});

test('fromArrays parses cat_true/cat_false from a full submission', function (): void {
    $request = GroupPermSubmitRequest::fromArrays([], [
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
    expect(fn (): GroupPermSubmitRequest => GroupPermSubmitRequest::fromArrays([], ['cat_true' => ['1; DROP TABLE']]))
        ->toThrow(RuntimeException::class);
});

test('fromArrays rejects a non-digit cat_false element', function (): void {
    expect(fn (): GroupPermSubmitRequest => GroupPermSubmitRequest::fromArrays([], ['cat_false' => ['1; DROP TABLE']]))
        ->toThrow(RuntimeException::class);
});

test('fromArrays passes group_id through from GET', function (): void {
    $request = GroupPermSubmitRequest::fromArrays(['group_id' => '42'], []);

    expect($request->groupIdPresent)->toBeTrue()
        ->and($request->groupId)->toBe('42');
});

test('fromArrays reports groupIdPresent false when absent', function (): void {
    $request = GroupPermSubmitRequest::fromArrays([], []);

    expect($request->groupIdPresent)->toBeFalse()
        ->and($request->groupId)->toBeNull();
});

test('fromArrays rejects a non-digit group_id', function (): void {
    expect(fn (): GroupPermSubmitRequest => GroupPermSubmitRequest::fromArrays(['group_id' => '1; DROP TABLE'], []))
        ->toThrow(RuntimeException::class);
});
