<?php

declare(strict_types=1);

use Piwigo\Admin\Request\RatingRequest;
use Piwigo\Validation\InputValidator;

test('fromArray returns defaults for an empty GET', function (): void {
    $request = RatingRequest::fromArray([], new InputValidator());

    expect($request->start)
        ->toBe(0)
        ->and($request->elementsPerPage)
        ->toBe(10)
        ->and($request->orderByIndex)
        ->toBe(0)
        ->and($request->catPresent)
        ->toBeFalse()
        ->and($request->catRaw)
        ->toBeNull()
        ->and($request->catId)
        ->toBeNull()
        ->and($request->usersRaw)
        ->toBeNull()
        ->and($request->isUsersUser)
        ->toBeFalse()
        ->and($request->isUsersGuest)
        ->toBeFalse();
});

test('fromArray parses start/display/order_by as ints', function (): void {
    $request = RatingRequest::fromArray([
        'start' => '20',
        'display' => '30',
        'order_by' => '2',
    ], new InputValidator());

    expect($request->start)
        ->toBe(20)
        ->and($request->elementsPerPage)
        ->toBe(30)
        ->and($request->orderByIndex)
        ->toBe(2);
});

test('fromArray rejects a non-digit display', function (): void {
    expect(fn (): RatingRequest => RatingRequest::fromArray([
        'display' => '1; DROP TABLE',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray exposes catRaw and catId separately', function (): void {
    $request = RatingRequest::fromArray([
        'cat' => '12',
    ], new InputValidator());

    expect($request->catPresent)
        ->toBeTrue()
        ->and($request->catRaw)
        ->toBe('12')
        ->and($request->catId)
        ->toBe(12);
});

test('fromArray nulls catId but keeps catRaw for a non-numeric cat', function (): void {
    $request = RatingRequest::fromArray([
        'cat' => 'abc',
    ], new InputValidator());

    expect($request->catPresent)
        ->toBeTrue()
        ->and($request->catRaw)
        ->toBe('abc')
        ->and($request->catId)
        ->toBeNull();
});

test('fromArray reports isUsersUser for users=user', function (): void {
    $request = RatingRequest::fromArray([
        'users' => 'user',
    ], new InputValidator());

    expect($request->isUsersUser)
        ->toBeTrue()
        ->and($request->isUsersGuest)
        ->toBeFalse()
        ->and($request->usersRaw)
        ->toBe('user');
});

test('fromArray reports isUsersGuest for users=guest', function (): void {
    $request = RatingRequest::fromArray([
        'users' => 'guest',
    ], new InputValidator());

    expect($request->isUsersGuest)
        ->toBeTrue()
        ->and($request->isUsersUser)
        ->toBeFalse();
});

test('fromArray reports neither flag for an unrecognized users value', function (): void {
    $request = RatingRequest::fromArray([
        'users' => 'all',
    ], new InputValidator());

    expect($request->isUsersUser)
        ->toBeFalse()
        ->and($request->isUsersGuest)
        ->toBeFalse()
        ->and($request->usersRaw)
        ->toBe('all');
});

test('fromArray keeps a malformed array cat/users value verbatim', function (): void {
    $request = RatingRequest::fromArray([
        'cat' => ['12'],
        'users' => ['user'],
    ], new InputValidator());

    expect($request->catRaw)
        ->toBe(['12'])
        ->and($request->catId)
        ->toBeNull()
        ->and($request->usersRaw)
        ->toBe(['user'])
        ->and($request->isUsersUser)
        ->toBeFalse()
        ->and($request->isUsersGuest)
        ->toBeFalse();
});
