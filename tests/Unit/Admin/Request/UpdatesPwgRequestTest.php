<?php

declare(strict_types=1);

use Piwigo\Admin\Request\UpdatesPwgRequest;

test('fromArrays defaults step to 0 when absent', function (): void {
    $request = UpdatesPwgRequest::fromArrays([], [], 'none');

    expect($request->step)->toBe(0);
});

test('fromArrays parses a numeric step', function (): void {
    $request = UpdatesPwgRequest::fromArrays(['step' => '2'], [], 'none');

    expect($request->step)->toBe(2);
});

test('fromArrays strips a trailing letter suffix from "to" for the Official container', function (): void {
    $request = UpdatesPwgRequest::fromArrays(['to' => '16.2.0a'], [], 'Official');

    expect($request->upgradeTo)->toBe('16.2.0');
});

test('fromArrays keeps "to" as-is for a non-Official environment', function (): void {
    $request = UpdatesPwgRequest::fromArrays(['to' => '16.2.0'], [], 'none');

    expect($request->upgradeTo)->toBe('16.2.0');
});

test('fromArrays rejects a non-Official "to" with a letter suffix', function (): void {
    expect(fn (): UpdatesPwgRequest => UpdatesPwgRequest::fromArrays(['to' => '16.2.0a'], [], 'none'))
        ->toThrow(RuntimeException::class);
});

test('fromArrays recognizes a valid upgrade submission', function (): void {
    $request = UpdatesPwgRequest::fromArrays([], ['submit' => '1', 'upgrade_to' => '16.3.0'], 'none');

    expect($request->isUpgradeSubmitted)->toBeTrue()
        ->and($request->upgradeToSubmitted)->toBe('16.3.0');
});

test('fromArrays reports not submitted when submit is missing', function (): void {
    $request = UpdatesPwgRequest::fromArrays([], ['upgrade_to' => '16.3.0'], 'none');

    expect($request->isUpgradeSubmitted)->toBeFalse()
        ->and($request->upgradeToSubmitted)->toBe('');
});

test('fromArrays reports not submitted when upgrade_to is not a string', function (): void {
    $request = UpdatesPwgRequest::fromArrays([], ['submit' => '1', 'upgrade_to' => ['x']], 'none');

    expect($request->isUpgradeSubmitted)->toBeFalse()
        ->and($request->upgradeToSubmitted)->toBe('');
});
