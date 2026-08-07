<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\Request\C13yTreatmentRequest;

test('fromArray recognizes correction mode with a valid selection', function (): void {
    $request = C13yTreatmentRequest::fromArray([
        'c13y_submit_correction' => '1',
        'c13y_selection' => ['abc123', 'def456'],
    ]);

    expect($request->mode)->toBe('correction')
        ->and($request->selection)->toBe(['abc123', 'def456']);
});

test('fromArray recognizes ignore mode with a valid selection', function (): void {
    $request = C13yTreatmentRequest::fromArray([
        'c13y_submit_ignore' => '1',
        'c13y_selection' => ['abc123'],
    ]);

    expect($request->mode)->toBe('ignore')
        ->and($request->selection)->toBe(['abc123']);
});

test('fromArray returns null mode when neither submit key has a valid array selection', function (): void {
    $request = C13yTreatmentRequest::fromArray([
        'c13y_submit_correction' => '1',
        'c13y_submit_ignore' => '1',
        'c13y_selection' => 'not-an-array',
    ]);

    expect($request->mode)->toBeNull();
});

test('fromArray returns null mode when neither submit key is present', function (): void {
    $request = C13yTreatmentRequest::fromArray(['c13y_selection' => ['abc123']]);

    expect($request->mode)->toBeNull()
        ->and($request->selection)->toBe(['abc123']);
});

test('fromArray returns an empty selection when c13y_selection is not an array', function (): void {
    $request = C13yTreatmentRequest::fromArray([
        'c13y_submit_correction' => '1',
        'c13y_selection' => 'oops',
    ]);

    expect($request->selection)->toBe([]);
});

test('fromArray coerces non-string selection elements to strings', function (): void {
    $request = C13yTreatmentRequest::fromArray([
        'c13y_submit_correction' => '1',
        'c13y_selection' => ['ok', ['nested']],
    ]);

    expect($request->selection)->toBe(['ok', '']);
});

test('fromArray re-indexes a non-sequential selection into a plain 0-based list', function (): void {
    // Kills the array_values() UnwrapArrayValues mutant: a checkbox-style
    // POST submission (only the checked boxes present) leaves gaps in
    // c13y_selection's own keys -- array_map() alone would preserve those
    // original keys (3, 7), violating this class's own `list<string>`
    // contract on $selection.
    $request = C13yTreatmentRequest::fromArray([
        'c13y_submit_correction' => '1',
        'c13y_selection' => [3 => 'abc123', 7 => 'def456'],
    ]);

    expect($request->selection)->toBe(['abc123', 'def456']);
});
