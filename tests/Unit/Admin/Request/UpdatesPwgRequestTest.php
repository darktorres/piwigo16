<?php

declare(strict_types=1);

use Piwigo\Admin\Request\UpdatesPwgRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays defaults step to 0 when absent', function (): void {
    $request = UpdatesPwgRequest::fromArrays([], [], 'none', new InputValidator());

    expect($request->step)->toBe(0);
});

test('fromArrays parses a numeric step', function (): void {
    $request = UpdatesPwgRequest::fromArrays(['step' => '2'], [], 'none', new InputValidator());

    expect($request->step)->toBe(2);
});

test('fromArrays defaults step to 0 when present but neither a string nor an int', function (): void {
    $request = UpdatesPwgRequest::fromArrays(['step' => 12.5], [], 'none', new InputValidator());

    expect($request->step)->toBe(0);
});

test('fromArrays strips a trailing letter suffix from "to" for the Official container', function (): void {
    $request = UpdatesPwgRequest::fromArrays(['to' => '16.2.0a'], [], 'Official', new InputValidator());

    expect($request->upgradeTo)->toBe('16.2.0');
});

test('fromArrays keeps "to" as-is for a non-Official environment', function (): void {
    $request = UpdatesPwgRequest::fromArrays(['to' => '16.2.0'], [], 'none', new InputValidator());

    expect($request->upgradeTo)->toBe('16.2.0');
});

test('fromArrays rejects a non-Official "to" with a letter suffix', function (): void {
    expect(fn (): UpdatesPwgRequest => UpdatesPwgRequest::fromArrays(['to' => '16.2.0a'], [], 'none', new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays rejects a malformed "to" for the Official container', function (): void {
    expect(fn (): UpdatesPwgRequest => UpdatesPwgRequest::fromArrays(['to' => 'not-a-version'], [], 'Official', new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays defaults upgradeTo to an empty string when "to" is present but not a string, Official', function (): void {
    // false is "empty" per InputValidator's own emptyValue() check (so
    // validate() no-ops without checking the pattern) but is not a string,
    // reaching the ternary's is_string()-false branch.
    $request = UpdatesPwgRequest::fromArrays(['to' => false], [], 'Official', new InputValidator());

    expect($request->upgradeTo)->toBe('');
});

test('fromArrays defaults upgradeTo to an empty string when "to" is absent, non-Official', function (): void {
    $request = UpdatesPwgRequest::fromArrays([], [], 'none', new InputValidator());

    expect($request->upgradeTo)->toBe('');
});

test('fromArrays defaults upgradeTo to an empty string when "to" is present but not a string, non-Official', function (): void {
    $request = UpdatesPwgRequest::fromArrays(['to' => false], [], 'none', new InputValidator());

    expect($request->upgradeTo)->toBe('');
});

test('fromArrays recognizes a valid upgrade submission', function (): void {
    $request = UpdatesPwgRequest::fromArrays([], ['submit' => '1', 'upgrade_to' => '16.3.0'], 'none', new InputValidator());

    expect($request->isUpgradeSubmitted)->toBeTrue()
        ->and($request->upgradeToSubmitted)->toBe('16.3.0');
});

test('fromArrays reports not submitted when submit is missing', function (): void {
    $request = UpdatesPwgRequest::fromArrays([], ['upgrade_to' => '16.3.0'], 'none', new InputValidator());

    expect($request->isUpgradeSubmitted)->toBeFalse()
        ->and($request->upgradeToSubmitted)->toBe('');
});

test('fromArrays reports not submitted when upgrade_to is not a string', function (): void {
    $request = UpdatesPwgRequest::fromArrays([], ['submit' => '1', 'upgrade_to' => ['x']], 'none', new InputValidator());

    expect($request->isUpgradeSubmitted)->toBeFalse()
        ->and($request->upgradeToSubmitted)->toBe('');
});
