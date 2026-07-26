<?php

declare(strict_types=1);

use Piwigo\Picture\Request\PictureCommentSubmitRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = PictureCommentSubmitRequest::fromArrays([], []);

    expect($request->contentPresent)->toBeFalse()
        ->and($request->author)->toBeNull()
        ->and($request->content)->toBeNull()
        ->and($request->websiteUrl)->toBeNull()
        ->and($request->email)->toBeNull()
        ->and($request->key)->toBeNull()
        ->and($request->commentsOrderRaw)->toBeNull();
});

test('fromArrays reports contentPresent even for a non-string content value', function (): void {
    $request = PictureCommentSubmitRequest::fromArrays([], ['content' => ['nested']]);

    expect($request->contentPresent)->toBeTrue()
        ->and($request->content)->toBeNull();
});

test('fromArrays reads all the comment submit fields', function (): void {
    $request = PictureCommentSubmitRequest::fromArrays([], [
        'content' => 'Nice photo!',
        'author' => 'Alice',
        'website_url' => 'https://example.com',
        'email' => 'alice@example.com',
        'key' => 'somekey',
    ]);

    expect($request->contentPresent)->toBeTrue()
        ->and($request->content)->toBe('Nice photo!')
        ->and($request->author)->toBe('Alice')
        ->and($request->websiteUrl)->toBe('https://example.com')
        ->and($request->email)->toBe('alice@example.com')
        ->and($request->key)->toBe('somekey');
});

test('fromArrays reads comments_order from GET', function (): void {
    $request = PictureCommentSubmitRequest::fromArrays(['comments_order' => 'DESC'], []);

    expect($request->commentsOrderRaw)->toBe('DESC');
});
