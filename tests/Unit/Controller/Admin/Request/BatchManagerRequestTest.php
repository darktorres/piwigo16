<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\BatchManagerRequest;

test('fromArrays returns defaults for an empty GET/POST/REQUEST', function (): void {
    $request = BatchManagerRequest::fromArrays([], [], []);

    expect($request->page)->toBe('')
        ->and($request->start)->toBe(0)
        ->and($request->tab)->toBe('global')
        ->and($request->action)->toBeNull()
        ->and($request->nbOrphansDeleted)->toBeNull()
        ->and($request->nbMd5sumAdded)->toBeNull()
        ->and($request->isSubmitFilter)->toBeFalse()
        ->and($request->post)->toBe([])
        ->and($request->urlFilterPresent)->toBeFalse()
        ->and($request->urlFilterTokens)->toBe([]);
});

test('fromArrays parses a valid start from REQUEST', function (): void {
    $request = BatchManagerRequest::fromArrays([], [], ['start' => '12']);

    expect($request->start)->toBe(12);
});

test('fromArrays resets start to 0 when the filter form is submitted, even with a positive REQUEST start', function (): void {
    $request = BatchManagerRequest::fromArrays([], ['submitFilter' => '1'], ['start' => '12', 'submitFilter' => '1']);

    expect($request->isSubmitFilter)->toBeTrue()
        ->and($request->start)->toBe(0);
});

test('fromArrays resets start to 0 when display is all', function (): void {
    $request = BatchManagerRequest::fromArrays([], [], ['start' => '12', 'display' => 'all']);

    expect($request->start)->toBe(0);
});

test('fromArrays reads a valid mode as tab', function (): void {
    $request = BatchManagerRequest::fromArrays(['mode' => 'unit'], [], []);

    expect($request->tab)->toBe('unit');
});

test('fromArrays rejects an invalid mode', function (): void {
    expect(fn (): BatchManagerRequest => BatchManagerRequest::fromArrays(['mode' => 'bogus'], [], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays validates and parses nb_orphans_deleted only for the delete_orphans action', function (): void {
    $matching = BatchManagerRequest::fromArrays(['action' => 'delete_orphans', 'nb_orphans_deleted' => '3'], [], []);
    $wrongAction = BatchManagerRequest::fromArrays(['action' => 'sync_md5sum', 'nb_orphans_deleted' => '3'], [], []);

    expect($matching->nbOrphansDeleted)->toBe(3)
        ->and($wrongAction->nbOrphansDeleted)->toBeNull();
});

test('fromArrays rejects a non-digit nb_orphans_deleted', function (): void {
    expect(fn (): BatchManagerRequest => BatchManagerRequest::fromArrays(['action' => 'delete_orphans', 'nb_orphans_deleted' => 'abc'], [], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays validates and parses nb_md5sum_added only for the sync_md5sum action', function (): void {
    $request = BatchManagerRequest::fromArrays(['action' => 'sync_md5sum', 'nb_md5sum_added' => '7'], [], []);

    expect($request->nbMd5sumAdded)->toBe(7);
});

test('fromArrays retains the full raw post bag', function (): void {
    $request = BatchManagerRequest::fromArrays([], ['filter_category_use' => '1', 'filter_category' => '5'], []);

    expect($request->post)->toBe(['filter_category_use' => '1', 'filter_category' => '5']);
});

test('fromArrays normalizes a comma-separated filter string into tokens', function (): void {
    $request = BatchManagerRequest::fromArrays(['filter' => 'category-5,level-1'], [], []);

    expect($request->urlFilterPresent)->toBeTrue()
        ->and($request->urlFilterTokens)->toBe(['category-5', 'level-1']);
});

test('fromArrays keeps an already-array filter as tokens', function (): void {
    $request = BatchManagerRequest::fromArrays(['filter' => ['category-5', 'level-1']], [], []);

    expect($request->urlFilterTokens)->toBe(['category-5', 'level-1']);
});
