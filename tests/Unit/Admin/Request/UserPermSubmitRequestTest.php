<?php

declare(strict_types=1);

use Piwigo\Admin\Request\UserPermSubmitRequest;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Validation\InputValidator;

test('fromArrays reports not submitted and empty lists for an empty POST', function (): void {
    $request = UserPermSubmitRequest::fromArrays([], [], new InputValidator());

    expect($request->isSubmitted)
        ->toBeFalse()
        ->and($request->catTrue)
        ->toBe([])
        ->and($request->catFalse)
        ->toBe([]);
});

test('fromArrays parses cat_true/cat_false from a full submission', function (): void {
    $request = UserPermSubmitRequest::fromArrays([], [
        'cat_true' => ['1', '2'],
        'cat_false' => ['3', '4'],
        'falsify' => '1',
    ], new InputValidator());

    expect($request->isSubmitted)
        ->toBeTrue()
        ->and($request->catTrue)
        ->toBe(['1', '2'])
        ->and($request->catFalse)
        ->toBe([3, 4])
        ->and($request->isFalsify)
        ->toBeTrue()
        ->and($request->isTrueify)
        ->toBeFalse();
});

test('fromArrays rejects a non-digit cat_true element', function (): void {
    expect(fn (): UserPermSubmitRequest => UserPermSubmitRequest::fromArrays([], [
        'cat_true' => ['1; DROP TABLE'],
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays rejects a non-digit cat_false element', function (): void {
    expect(fn (): UserPermSubmitRequest => UserPermSubmitRequest::fromArrays([], [
        'cat_false' => ['1; DROP TABLE'],
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses user_id to a UserId when present', function (): void {
    $request = UserPermSubmitRequest::fromArrays([
        'user_id' => '42',
    ], [], new InputValidator());

    expect($request->userId)
        ->toBeInstanceOf(UserId::class)
        ->and($request->userId?->value)
        ->toBe(42);
});

test('fromArrays returns a null user_id when absent', function (): void {
    $request = UserPermSubmitRequest::fromArrays([], [], new InputValidator());

    expect($request->userId)
        ->toBeNull();
});

test('fromArrays collapses a non-positive user_id to null', function (): void {
    $request = UserPermSubmitRequest::fromArrays([
        'user_id' => '0',
    ], [], new InputValidator());

    expect($request->userId)
        ->toBeNull();
});

test('fromArrays rejects a non-digit user_id', function (): void {
    expect(fn (): UserPermSubmitRequest => UserPermSubmitRequest::fromArrays([
        'user_id' => '1; DROP TABLE',
    ], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});
