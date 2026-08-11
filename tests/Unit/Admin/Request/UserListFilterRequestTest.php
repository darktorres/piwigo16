<?php

declare(strict_types=1);

use Piwigo\Admin\Request\UserListFilterRequest;
use Piwigo\Validation\InputValidator;

test('fromArray returns null groupId and userSearchInput when absent', function (): void {
    $request = UserListFilterRequest::fromArray([], new InputValidator());

    expect($request->groupId)
        ->toBeNull()
        ->and($request->userSearchInput)
        ->toBeNull()
        ->and($request->showAddUser)
        ->toBeFalse();
});

test('fromArray reports showAddUser when show_add_user is present', function (): void {
    $request = UserListFilterRequest::fromArray([
        'show_add_user' => '1',
    ], new InputValidator());

    expect($request->showAddUser)
        ->toBeTrue();
});

test('fromArray returns the group id verbatim', function (): void {
    $request = UserListFilterRequest::fromArray([
        'group' => '5',
    ], new InputValidator());

    expect($request->groupId)
        ->toBe('5');
});

test('fromArray builds the id:-prefixed search input from user_id', function (): void {
    $request = UserListFilterRequest::fromArray([
        'user_id' => '42',
    ], new InputValidator());

    expect($request->userSearchInput)
        ->toBe('id:42');
});

test('fromArray rejects a non-digit group', function (): void {
    expect(fn (): UserListFilterRequest => UserListFilterRequest::fromArray([
        'group' => 'abc',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a non-digit user_id', function (): void {
    expect(fn (): UserListFilterRequest => UserListFilterRequest::fromArray([
        'user_id' => '1; DROP TABLE',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});
