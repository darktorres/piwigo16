<?php

declare(strict_types=1);

use Piwigo\Admin\Request\LanguagesNewInstallRequest;

test('fromArray returns null for both when absent', function (): void {
    $request = LanguagesNewInstallRequest::fromArray([]);

    expect($request->revision)->toBeNull()
        ->and($request->installStatus)->toBeNull();
});

test('fromArray returns the revision string when present', function (): void {
    $request = LanguagesNewInstallRequest::fromArray(['revision' => '12345']);

    expect($request->revision)->toBe('12345');
});

test('fromArray returns the installstatus string when present', function (): void {
    $request = LanguagesNewInstallRequest::fromArray(['installstatus' => 'ok']);

    expect($request->installStatus)->toBe('ok');
});

test('fromArray coerces a non-string revision to an empty string while staying non-null', function (): void {
    $request = LanguagesNewInstallRequest::fromArray(['revision' => ['x']]);

    expect($request->revision)->toBe('');
});
