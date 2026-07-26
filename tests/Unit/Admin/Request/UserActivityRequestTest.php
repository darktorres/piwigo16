<?php

declare(strict_types=1);

use Piwigo\Admin\Request\UserActivityRequest;

test('fromArray recognizes the download_logs type', function (): void {
    $request = UserActivityRequest::fromArray(['type' => 'download_logs']);

    expect($request->isDownloadLogs)->toBeTrue();
});

test('fromArray treats any other type as not download_logs', function (): void {
    $request = UserActivityRequest::fromArray(['type' => 'something_else']);

    expect($request->isDownloadLogs)->toBeFalse();
});

test('fromArray exposes filter presence and values for photo/album/group', function (): void {
    $request = UserActivityRequest::fromArray(['photo' => '42']);

    expect($request->hasFilter('photo'))->toBeTrue()
        ->and($request->filterValue('photo'))->toBe('42')
        ->and($request->hasFilter('album'))->toBeFalse()
        ->and($request->filterValue('album'))->toBeNull();
});

test('fromArray rejects a non-digit photo filter', function (): void {
    expect(fn (): UserActivityRequest => UserActivityRequest::fromArray(['photo' => '1; DROP TABLE']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a non-digit album filter', function (): void {
    expect(fn (): UserActivityRequest => UserActivityRequest::fromArray(['album' => '../../etc']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a non-digit group filter', function (): void {
    expect(fn (): UserActivityRequest => UserActivityRequest::fromArray(['group' => 'abc']))
        ->toThrow(RuntimeException::class);
});
