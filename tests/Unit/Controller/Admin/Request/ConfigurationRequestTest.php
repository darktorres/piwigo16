<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\ConfigurationRequest;

test('fromArrays returns defaults for an empty GET/POST/FILES', function (): void {
    $request = ConfigurationRequest::fromArrays([], [], []);

    expect($request->section)->toBe('main')
        ->and($request->restoreSettingsRequested)->toBeFalse()
        ->and($request->isSubmitted)->toBeFalse()
        ->and($request->post)->toBe([])
        ->and($request->files)->toBe([]);
});

test('fromArrays reads a valid section', function (): void {
    $request = ConfigurationRequest::fromArrays(['section' => 'watermark'], [], []);

    expect($request->section)->toBe('watermark');
});

test('fromArrays rejects a section with digits or symbols', function (): void {
    expect(fn (): ConfigurationRequest => ConfigurationRequest::fromArrays(['section' => 'main2'], [], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reports restoreSettingsRequested only for the exact action value', function (): void {
    $restore = ConfigurationRequest::fromArrays(['action' => 'restore_settings'], [], []);
    $other = ConfigurationRequest::fromArrays(['action' => 'something_else'], [], []);

    expect($restore->restoreSettingsRequested)->toBeTrue()
        ->and($other->restoreSettingsRequested)->toBeFalse();
});

test('fromArrays reports isSubmitted and retains the full raw post/files bags', function (): void {
    $request = ConfigurationRequest::fromArrays([], ['submit' => '1', 'gallery_title' => 'My Gallery'], ['watermarkImage' => ['name' => 'x.png']]);

    expect($request->isSubmitted)->toBeTrue()
        ->and($request->post)->toBe(['submit' => '1', 'gallery_title' => 'My Gallery'])
        ->and($request->files)->toBe(['watermarkImage' => ['name' => 'x.png']]);
});
