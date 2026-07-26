<?php

declare(strict_types=1);

use Piwigo\Admin\Request\LanguagesInstalledActionRequest;

test('fromArray returns both action and language when both are valid', function (): void {
    $request = LanguagesInstalledActionRequest::fromArray(
        ['action' => 'activate', 'language' => 'en_US'],
        '/^(en_US|fr_FR)$/'
    );

    expect($request->action)->toBe('activate')
        ->and($request->languageId)->toBe('en_US');
});

test('fromArray returns null action when absent', function (): void {
    $request = LanguagesInstalledActionRequest::fromArray(['language' => 'en_US'], '/^(en_US)$/');

    expect($request->action)->toBeNull();
});

test('fromArray rejects an unknown action', function (): void {
    expect(fn (): LanguagesInstalledActionRequest => LanguagesInstalledActionRequest::fromArray(
        ['action' => 'rm_rf', 'language' => 'en_US'],
        '/^(en_US)$/'
    ))->toThrow(RuntimeException::class);
});

test('fromArray rejects a language id not present in the scanned pattern', function (): void {
    expect(fn (): LanguagesInstalledActionRequest => LanguagesInstalledActionRequest::fromArray(
        ['action' => 'activate', 'language' => '../../etc'],
        '/^(en_US|fr_FR)$/'
    ))->toThrow(RuntimeException::class);
});
