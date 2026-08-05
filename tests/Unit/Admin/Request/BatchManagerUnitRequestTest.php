<?php

declare(strict_types=1);

use Piwigo\Admin\Request\BatchManagerUnitRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], [], new InputValidator());

    expect($request->isSubmitted)->toBeFalse()
        ->and($request->elementIds)->toBe('')
        ->and($request->post)->toBe([])
        ->and($request->nbPhotosDeletedPresent)->toBeFalse()
        ->and($request->nbPhotosDeleted)->toBe(0)
        ->and($request->isSetSelected)->toBeFalse()
        ->and($request->wholeSet)->toBe('')
        ->and($request->selectionPresent)->toBeFalse()
        ->and($request->selection)->toBe([])
        ->and($request->displayRequested)->toBeFalse()
        ->and($request->display)->toBe(0);
});

test('fromArrays parses a full unit-mode submission and retains the raw post bag', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], [
        'submit' => '1',
        'element_ids' => '1,2,3',
        'name-1' => 'My photo',
    ], new InputValidator());

    expect($request->isSubmitted)->toBeTrue()
        ->and($request->elementIds)->toBe('1,2,3')
        ->and($request->post['name-1'])->toBe('My photo');
});

test('fromArrays rejects a malformed element_ids', function (): void {
    expect(fn (): BatchManagerUnitRequest => BatchManagerUnitRequest::fromArrays([], ['submit' => '1', 'element_ids' => '1; DROP TABLE'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays defaults element_ids to an empty string when present but not a string', function (): void {
    // A non-string element_ids that's still scalar (so validate() passes
    // it as-is against the digit-list pattern) must fall back to '', not
    // some other placeholder.
    $request = BatchManagerUnitRequest::fromArrays([], ['submit' => '1', 'element_ids' => 123], new InputValidator());

    expect($request->elementIds)->toBe('');
});

test('fromArrays parses nb_photos_deleted', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], ['nb_photos_deleted' => '5'], new InputValidator());

    expect($request->nbPhotosDeletedPresent)->toBeTrue()
        ->and($request->nbPhotosDeleted)->toBe(5);
});

test('fromArrays rejects a malformed nb_photos_deleted', function (): void {
    expect(fn (): BatchManagerUnitRequest => BatchManagerUnitRequest::fromArrays([], ['nb_photos_deleted' => '1; DROP TABLE'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays defaults nb_photos_deleted to 0 when present but empty', function (): void {
    // '' is "empty" per InputValidator's own emptyValue() check (so
    // validate() no-ops without even checking the digit pattern) but is
    // NOT is_numeric(), so this is the only way to reach the `: 0`
    // fallback on a present key without a validation throw.
    $request = BatchManagerUnitRequest::fromArrays([], ['nb_photos_deleted' => ''], new InputValidator());

    expect($request->nbPhotosDeletedPresent)->toBeTrue()
        ->and($request->nbPhotosDeleted)->toBe(0);
});

test('fromArrays reports isSetSelected and the raw whole_set string', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], ['setSelected' => '1', 'whole_set' => '1,2,3'], new InputValidator());

    expect($request->isSetSelected)->toBeTrue()
        ->and($request->wholeSet)->toBe('1,2,3');
});

test('fromArrays reports selectionPresent and the raw selection array', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], ['selection' => ['4', '5']], new InputValidator());

    expect($request->selectionPresent)->toBeTrue()
        ->and($request->selection)->toBe(['4', '5']);
});

test('fromArrays reports selectionPresent false for a non-array selection', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], ['selection' => 'not-an-array'], new InputValidator());

    expect($request->selectionPresent)->toBeFalse()
        ->and($request->selection)->toBe([]);
});

test('fromArrays parses display', function (): void {
    $request = BatchManagerUnitRequest::fromArrays(['display' => '20'], [], new InputValidator());

    expect($request->displayRequested)->toBeTrue()
        ->and($request->display)->toBe(20);
});

test('fromArrays treats display=0 and display= as not requested', function (): void {
    expect(BatchManagerUnitRequest::fromArrays(['display' => '0'], [], new InputValidator())->displayRequested)->toBeFalse()
        ->and(BatchManagerUnitRequest::fromArrays(['display' => ''], [], new InputValidator())->displayRequested)->toBeFalse();
});

test('fromArrays defaults display to 0 when requested but not purely numeric', function (): void {
    // display_requested can be true off a non-numeric-but-non-empty raw
    // value -- intval() on a leading-digit non-numeric string like
    // '42abc' returns 42 (not 0), so an AND-vs-OR mix-up here is only
    // observable with a value is_numeric() rejects but intval() doesn't
    // parse as plain 0.
    $request = BatchManagerUnitRequest::fromArrays(['display' => '42abc'], [], new InputValidator());

    expect($request->displayRequested)->toBeTrue()
        ->and($request->display)->toBe(0);
});
