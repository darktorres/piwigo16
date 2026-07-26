<?php

declare(strict_types=1);

use Piwigo\Controller\Request\CommentsRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = CommentsRequest::fromArrays([], [], 20);

    expect($request->since)->toBeNull()
        ->and($request->sortBy)->toBe('date')
        ->and($request->sortOrder)->toBe('DESC')
        ->and($request->itemsNumber)->toBe(20)
        ->and($request->catDisplay)->toBeNull()
        ->and($request->catId)->toBeNull()
        ->and($request->authorFilter)->toBeNull()
        ->and($request->authorDisplay)->toBeNull()
        ->and($request->commentId)->toBeNull()
        ->and($request->keywordFilter)->toBeNull()
        ->and($request->keywordDisplay)->toBeNull()
        ->and($request->action)->toBeNull()
        ->and($request->actionCommentId)->toBeNull()
        ->and($request->start)->toBe(0)
        ->and($request->content)->toBeNull()
        ->and($request->key)->toBe('')
        ->and($request->imageId)->toBeNull()
        ->and($request->websiteUrl)->toBeNull();
});

test('fromArrays falls back to 10 for a non-numeric, non-all items_number', function (): void {
    $request = CommentsRequest::fromArrays(['items_number' => 'lots'], [], 20);

    expect($request->itemsNumber)->toBe(10);
});

test('fromArrays keeps a valid items_number override', function (): void {
    $request = CommentsRequest::fromArrays(['items_number' => '50'], [], 20);

    expect($request->itemsNumber)->toBe('50');
});

test('fromArrays keeps all as items_number', function (): void {
    $request = CommentsRequest::fromArrays(['items_number' => 'all'], [], 20);

    expect($request->itemsNumber)->toBe('all');
});

test('fromArrays ignores an unrecognized sort_by/sort_order', function (): void {
    $request = CommentsRequest::fromArrays(['sort_by' => 'evil', 'sort_order' => 'evil'], [], 20);

    expect($request->sortBy)->toBe('date')
        ->and($request->sortOrder)->toBe('DESC');
});

test('fromArrays accepts a recognized sort_by/sort_order', function (): void {
    $request = CommentsRequest::fromArrays(['sort_by' => 'image_id', 'sort_order' => 'ASC'], [], 20);

    expect($request->sortBy)->toBe('image_id')
        ->and($request->sortOrder)->toBe('ASC');
});

test('fromArrays skips the cat filter when cat is 0', function (): void {
    $request = CommentsRequest::fromArrays(['cat' => '0'], [], 20);

    expect($request->catId)->toBeNull()
        ->and($request->catDisplay)->toBe('0');
});

test('fromArrays validates and keeps a numeric cat', function (): void {
    $request = CommentsRequest::fromArrays(['cat' => '5'], [], 20);

    expect($request->catId)->toBe('5')
        ->and($request->catDisplay)->toBe('5');
});

test('fromArrays rejects a non-digit cat', function (): void {
    expect(fn (): CommentsRequest => CommentsRequest::fromArrays(['cat' => 'abc'], [], 20))
        ->toThrow(RuntimeException::class);
});

test('fromArrays splits author filter (truthy-gated) from author display (presence-gated)', function (): void {
    $zero = CommentsRequest::fromArrays(['author' => '0'], [], 20);
    expect($zero->authorFilter)->toBeNull()
        ->and($zero->authorDisplay)->toBe('0');

    $normal = CommentsRequest::fromArrays(['author' => 'alice'], [], 20);
    expect($normal->authorFilter)->toBe('alice')
        ->and($normal->authorDisplay)->toBe('alice');
});

test('fromArrays splits keyword filter (truthy-gated) from keyword display (presence-gated)', function (): void {
    $zero = CommentsRequest::fromArrays(['keyword' => '0'], [], 20);
    expect($zero->keywordFilter)->toBeNull()
        ->and($zero->keywordDisplay)->toBe('0');
});

test('fromArrays validates and parses a numeric comment_id', function (): void {
    $request = CommentsRequest::fromArrays(['comment_id' => '42'], [], 20);

    expect($request->commentId)->toBe(42);
});

test('fromArrays rejects a non-digit comment_id', function (): void {
    expect(fn (): CommentsRequest => CommentsRequest::fromArrays(['comment_id' => 'abc'], [], 20))
        ->toThrow(RuntimeException::class);
});

test('fromArrays picks the first matching action in delete/validate/edit order', function (): void {
    $request = CommentsRequest::fromArrays(['validate' => '7', 'edit' => '9'], [], 20);

    expect($request->action)->toBe('validate')
        ->and($request->actionCommentId)->toBe(7);
});

test('fromArrays parses start as an int', function (): void {
    $request = CommentsRequest::fromArrays(['start' => '30'], [], 20);

    expect($request->start)->toBe(30);
});

test('fromArrays parses the edit-submit POST fields', function (): void {
    $request = CommentsRequest::fromArrays([], [
        'content' => 'hello',
        'key' => 'somekey',
        'image_id' => '12',
        'website_url' => 'example.com',
    ], 20);

    expect($request->content)->toBe('hello')
        ->and($request->key)->toBe('somekey')
        ->and($request->imageId)->toBe('12')
        ->and($request->websiteUrl)->toBe('example.com');
});

test('fromArrays treats an empty or zero content as absent', function (): void {
    $empty = CommentsRequest::fromArrays([], ['content' => ''], 20);
    $zero = CommentsRequest::fromArrays([], ['content' => '0'], 20);

    expect($empty->content)->toBeNull()
        ->and($zero->content)->toBeNull();
});
