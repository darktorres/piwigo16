<?php

declare(strict_types=1);

use Piwigo\Admin\Request\AlbumNotificationSubmitRequest;

test('fromArray reports not submitted and empty defaults for an empty POST', function (): void {
    $request = AlbumNotificationSubmitRequest::fromArray([]);

    expect($request->isSubmitted)->toBeFalse()
        ->and($request->mailContent)->toBe('')
        ->and($request->who)->toBeNull()
        ->and($request->users)->toBe([])
        ->and($request->group)->toBeNull();
});

test('fromArray reports submitted when submitEmail is present', function (): void {
    $request = AlbumNotificationSubmitRequest::fromArray(['submitEmail' => '1']);

    expect($request->isSubmitted)->toBeTrue();
});

test('fromArray parses users when who is users and users is non-empty', function (): void {
    $request = AlbumNotificationSubmitRequest::fromArray([
        'who' => 'users',
        'users' => ['1', '2'],
    ]);

    expect($request->who)->toBe('users')
        ->and($request->users)->toBe(['1', '2']);
});

test('fromArray rejects a non-digit user id when who is users', function (): void {
    expect(fn (): AlbumNotificationSubmitRequest => AlbumNotificationSubmitRequest::fromArray([
        'who' => 'users',
        'users' => ['1; DROP TABLE'],
    ]))->toThrow(RuntimeException::class);
});

test('fromArray skips users validation when who is not users', function (): void {
    $request = AlbumNotificationSubmitRequest::fromArray([
        'who' => 'group',
        'users' => ['1; DROP TABLE'],
    ]);

    expect($request->users)->toBe([]);
});

test('fromArray passes group through when who is group', function (): void {
    $request = AlbumNotificationSubmitRequest::fromArray([
        'who' => 'group',
        'group' => '7',
    ]);

    expect($request->group)->toBe('7');
});

test('fromArray rejects a non-digit group when who is group and group is not empty', function (): void {
    expect(fn (): AlbumNotificationSubmitRequest => AlbumNotificationSubmitRequest::fromArray([
        'who' => 'group',
        'group' => '1; DROP TABLE',
    ]))->toThrow(RuntimeException::class);
});

test('fromArray skips group validation when group is an empty sentinel value', function (): void {
    $request = AlbumNotificationSubmitRequest::fromArray([
        'who' => 'group',
        'group' => '',
    ]);

    expect($request->group)->toBe('');
});

test('fromArray normalizes a non-string mail_content to an empty string', function (): void {
    $request = AlbumNotificationSubmitRequest::fromArray(['mail_content' => ['x']]);

    expect($request->mailContent)->toBe('');
});
