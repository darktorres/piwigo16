<?php

declare(strict_types=1);

use Piwigo\Admin\Request\UserListFilterRequest;

test('fromArray returns null groupId and userSearchInput when absent', function (): void {
    $request = UserListFilterRequest::fromArray([]);

    expect($request->groupId)->toBeNull()
        ->and($request->userSearchInput)->toBeNull();
});

test('fromArray returns the group id verbatim', function (): void {
    $request = UserListFilterRequest::fromArray(['group' => '5']);

    expect($request->groupId)->toBe('5');
});

test('fromArray builds the id:-prefixed search input from user_id', function (): void {
    $request = UserListFilterRequest::fromArray(['user_id' => '42']);

    expect($request->userSearchInput)->toBe('id:42');
});

test('fromArray rejects a non-digit group', function (): void {
    expect(fn (): UserListFilterRequest => UserListFilterRequest::fromArray(['group' => 'abc']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a non-digit user_id', function (): void {
    expect(fn (): UserListFilterRequest => UserListFilterRequest::fromArray(['user_id' => '1; DROP TABLE']))
        ->toThrow(RuntimeException::class);
});
