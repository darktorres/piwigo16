<?php

declare(strict_types=1);

use Piwigo\Admin\Request\BatchManagerUnitRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], []);

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
    ]);

    expect($request->isSubmitted)->toBeTrue()
        ->and($request->elementIds)->toBe('1,2,3')
        ->and($request->post['name-1'])->toBe('My photo');
});

test('fromArrays rejects a malformed element_ids', function (): void {
    expect(fn (): BatchManagerUnitRequest => BatchManagerUnitRequest::fromArrays([], ['submit' => '1', 'element_ids' => '1; DROP TABLE']))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses nb_photos_deleted', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], ['nb_photos_deleted' => '5']);

    expect($request->nbPhotosDeletedPresent)->toBeTrue()
        ->and($request->nbPhotosDeleted)->toBe(5);
});

test('fromArrays rejects a malformed nb_photos_deleted', function (): void {
    expect(fn (): BatchManagerUnitRequest => BatchManagerUnitRequest::fromArrays([], ['nb_photos_deleted' => '1; DROP TABLE']))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reports isSetSelected and the raw whole_set string', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], ['setSelected' => '1', 'whole_set' => '1,2,3']);

    expect($request->isSetSelected)->toBeTrue()
        ->and($request->wholeSet)->toBe('1,2,3');
});

test('fromArrays reports selectionPresent and the raw selection array', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], ['selection' => ['4', '5']]);

    expect($request->selectionPresent)->toBeTrue()
        ->and($request->selection)->toBe(['4', '5']);
});

test('fromArrays reports selectionPresent false for a non-array selection', function (): void {
    $request = BatchManagerUnitRequest::fromArrays([], ['selection' => 'not-an-array']);

    expect($request->selectionPresent)->toBeFalse()
        ->and($request->selection)->toBe([]);
});

test('fromArrays parses display', function (): void {
    $request = BatchManagerUnitRequest::fromArrays(['display' => '20'], []);

    expect($request->displayRequested)->toBeTrue()
        ->and($request->display)->toBe(20);
});

test('fromArrays treats display=0 and display= as not requested', function (): void {
    expect(BatchManagerUnitRequest::fromArrays(['display' => '0'], [])->displayRequested)->toBeFalse()
        ->and(BatchManagerUnitRequest::fromArrays(['display' => ''], [])->displayRequested)->toBeFalse();
});
