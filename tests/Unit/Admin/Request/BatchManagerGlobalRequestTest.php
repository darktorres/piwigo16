<?php

declare(strict_types=1);

use Piwigo\Admin\Request\BatchManagerGlobalRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], []);

    expect($request->isSubmitted)->toBeFalse()
        ->and($request->selectAction)->toBe('')
        ->and($request->nbPhotosDeletedPresent)->toBeFalse()
        ->and($request->nbPhotosDeleted)->toBe(0)
        ->and($request->isSetSelected)->toBeFalse()
        ->and($request->wholeSet)->toBe('')
        ->and($request->selectionPresent)->toBeFalse()
        ->and($request->selection)->toBe([])
        ->and($request->post)->toBe([])
        ->and($request->page)->toBe('')
        ->and($request->displayRequested)->toBeFalse()
        ->and($request->displayRaw)->toBeNull();
});

test('fromArrays parses nb_photos_deleted', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['nb_photos_deleted' => '5']);

    expect($request->nbPhotosDeletedPresent)->toBeTrue()
        ->and($request->nbPhotosDeleted)->toBe(5);
});

test('fromArrays rejects a non-digit nb_photos_deleted', function (): void {
    expect(fn (): BatchManagerGlobalRequest => BatchManagerGlobalRequest::fromArrays([], ['nb_photos_deleted' => 'abc']))
        ->toThrow(RuntimeException::class);
});

test('fromArrays exposes whole_set when setSelected is present', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['setSelected' => '1', 'whole_set' => '1,2,3']);

    expect($request->isSetSelected)->toBeTrue()
        ->and($request->wholeSet)->toBe('1,2,3');
});

test('fromArrays exposes the raw selection array', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['selection' => ['1', '2']]);

    expect($request->selectionPresent)->toBeTrue()
        ->and($request->selection)->toBe(['1', '2']);
});

test('fromArrays rejects a non-digit del_tags element', function (): void {
    expect(fn (): BatchManagerGlobalRequest => BatchManagerGlobalRequest::fromArrays([], ['del_tags' => ['abc']]))
        ->toThrow(RuntimeException::class);
});

test('fromArrays rejects a non-digit move', function (): void {
    expect(fn (): BatchManagerGlobalRequest => BatchManagerGlobalRequest::fromArrays([], ['move' => 'abc']))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reads selectAction and retains the full post bag', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['selectAction' => 'author', 'author' => 'Alice']);

    expect($request->selectAction)->toBe('author')
        ->and($request->post)->toBe(['selectAction' => 'author', 'author' => 'Alice']);
});

test('fromArrays reads page from GET', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays(['page' => 'batch_manager'], []);

    expect($request->page)->toBe('batch_manager');
});

test('fromArrays treats an absent or zero display as not requested', function (): void {
    $absent = BatchManagerGlobalRequest::fromArrays([], []);
    $zero = BatchManagerGlobalRequest::fromArrays(['display' => '0'], []);

    expect($absent->displayRequested)->toBeFalse()
        ->and($zero->displayRequested)->toBeFalse();
});

test('fromArrays keeps a requested display value, including "all"', function (): void {
    $numeric = BatchManagerGlobalRequest::fromArrays(['display' => '50'], []);
    $all = BatchManagerGlobalRequest::fromArrays(['display' => 'all'], []);

    expect($numeric->displayRequested)->toBeTrue()
        ->and($numeric->displayRaw)->toBe('50')
        ->and($all->displayRequested)->toBeTrue()
        ->and($all->displayRaw)->toBe('all');
});
