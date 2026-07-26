<?php

declare(strict_types=1);

use Piwigo\Activity\Request\ActivityContextRequest;

test('fromArrays returns defaults for an empty GET/POST/REQUEST', function (): void {
    $request = ActivityContextRequest::fromArrays([], [], []);

    expect($request->requestMethod)->toBeNull()
        ->and($request->requestAction)->toBeNull()
        ->and($request->pageParam)->toBeNull()
        ->and($request->destinationTagPresent)->toBeFalse()
        ->and($request->destinationTag)->toBeNull();
});

test('fromArrays reads the ws method and action from REQUEST', function (): void {
    $request = ActivityContextRequest::fromArrays([], [], ['method' => 'pwg.plugins.performAction', 'action' => 'restore']);

    expect($request->requestMethod)->toBe('pwg.plugins.performAction')
        ->and($request->requestAction)->toBe('restore');
});

test('fromArrays reads page from GET', function (): void {
    $request = ActivityContextRequest::fromArrays(['page' => 'site_update'], [], []);

    expect($request->pageParam)->toBe('site_update');
});

test('fromArrays reports destinationTagPresent even for a non-string value', function (): void {
    $request = ActivityContextRequest::fromArrays([], ['destination_tag' => ['nested']], []);

    expect($request->destinationTagPresent)->toBeTrue()
        ->and($request->destinationTag)->toBeNull();
});

test('fromArrays reads a string destination_tag', function (): void {
    $request = ActivityContextRequest::fromArrays([], ['destination_tag' => '42'], []);

    expect($request->destinationTagPresent)->toBeTrue()
        ->and($request->destinationTag)->toBe('42');
});
