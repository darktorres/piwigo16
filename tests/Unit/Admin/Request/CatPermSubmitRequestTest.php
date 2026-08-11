<?php

declare(strict_types=1);

use Piwigo\Admin\Request\CatPermSubmitRequest;

test('fromArray reports not submitted for an empty POST', function (): void {
    $request = CatPermSubmitRequest::fromArray([]);

    expect($request->isSubmitted)
        ->toBeFalse();
});

test('fromArray parses status/apply_on_sub/groups/users from a full submission', function (): void {
    $request = CatPermSubmitRequest::fromArray([
        'status' => 'private',
        'apply_on_sub' => '1',
        'groups' => ['1', '2', 'not-a-number'],
        'users' => ['3', '4'],
    ]);

    expect($request->isSubmitted)
        ->toBeTrue()
        ->and($request->status)
        ->toBe('private')
        ->and($request->applyOnSub)
        ->toBeTrue()
        ->and($request->groups)
        ->toBe([1, 2])
        ->and($request->users)
        ->toBe([3, 4]);
});

test('fromArray defaults status to an empty string when missing or malformed', function (): void {
    $request = CatPermSubmitRequest::fromArray([
        'submit' => '1',
    ]);

    expect($request->status)
        ->toBe('');
});

test('fromArray defaults groups/users to an empty list when not an array', function (): void {
    $request = CatPermSubmitRequest::fromArray([
        'groups' => 'not-an-array',
    ]);

    expect($request->groups)
        ->toBe([])
        ->and($request->users)
        ->toBe([]);
});

test('fromArray reports applyOnSub false when absent', function (): void {
    $request = CatPermSubmitRequest::fromArray([]);

    expect($request->applyOnSub)
        ->toBeFalse();
});
