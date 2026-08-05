<?php

declare(strict_types=1);

use Piwigo\Controller\Request\PictureRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = PictureRequest::fromArrays([], [], new InputValidator());

    expect($request->metadataPresent)->toBeFalse()
        ->and($request->action)->toBeNull()
        ->and($request->rate)->toBeNull()
        ->and($request->commentToEdit)->toBeNull()
        ->and($request->content)->toBeNull()
        ->and($request->postKey)->toBe('')
        ->and($request->websiteUrl)->toBeNull()
        ->and($request->commentToDelete)->toBeNull()
        ->and($request->commentToValidate)->toBeNull()
        ->and($request->contentPresent)->toBeFalse()
        ->and($request->slideshowPresent)->toBeFalse()
        ->and($request->slideshow)->toBeNull();
});

test('fromArrays parses action and rate', function (): void {
    $request = PictureRequest::fromArrays([], ['rate' => '5'], new InputValidator());
    $requestWithAction = PictureRequest::fromArrays(['action' => 'add_to_favorites'], [], new InputValidator());

    expect($request->rate)->toBe('5')
        ->and($requestWithAction->action)->toBe('add_to_favorites');
});

test('fromArrays validates comment_to_edit only when action is edit_comment', function (): void {
    $request = PictureRequest::fromArrays(['action' => 'edit_comment', 'comment_to_edit' => '42'], [], new InputValidator());

    expect($request->commentToEdit)->toBe('42');
});

test('fromArrays skips comment_to_edit validation for a different action', function (): void {
    $request = PictureRequest::fromArrays(['action' => 'rate', 'comment_to_edit' => 'not-a-digit'], [], new InputValidator());

    expect($request->commentToEdit)->toBeNull();
});

test('fromArrays rejects a malformed comment_to_edit when action is edit_comment', function (): void {
    expect(fn (): PictureRequest => PictureRequest::fromArrays(['action' => 'edit_comment', 'comment_to_edit' => '1; DROP TABLE'], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays validates comment_to_delete only when action is delete_comment', function (): void {
    $request = PictureRequest::fromArrays(['action' => 'delete_comment', 'comment_to_delete' => '7'], [], new InputValidator());

    expect($request->commentToDelete)->toBe('7');
});

test('fromArrays rejects a malformed comment_to_delete when action is delete_comment', function (): void {
    expect(fn (): PictureRequest => PictureRequest::fromArrays(['action' => 'delete_comment', 'comment_to_delete' => 'bad'], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays validates comment_to_validate only when action is validate_comment', function (): void {
    $request = PictureRequest::fromArrays(['action' => 'validate_comment', 'comment_to_validate' => '9'], [], new InputValidator());

    expect($request->commentToValidate)->toBe('9');
});

test('fromArrays rejects a malformed comment_to_validate when action is validate_comment', function (): void {
    expect(fn (): PictureRequest => PictureRequest::fromArrays(['action' => 'validate_comment', 'comment_to_validate' => 'bad'], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses content/postKey/websiteUrl regardless of action', function (): void {
    $request = PictureRequest::fromArrays([], [
        'content' => 'Hello world',
        'key' => 'thekey',
        'website_url' => 'https://example.test',
    ], new InputValidator());

    expect($request->content)->toBe('Hello world')
        ->and($request->contentPresent)->toBeTrue()
        ->and($request->postKey)->toBe('thekey')
        ->and($request->websiteUrl)->toBe('https://example.test');
});

test('fromArrays reports slideshowPresent and the raw slideshow string', function (): void {
    $request = PictureRequest::fromArrays(['slideshow' => 'abc'], [], new InputValidator());

    expect($request->slideshowPresent)->toBeTrue()
        ->and($request->slideshow)->toBe('abc');
});

test('fromArrays reports slideshowPresent true but slideshow null for a non-string value', function (): void {
    $request = PictureRequest::fromArrays(['slideshow' => ['x']], [], new InputValidator());

    expect($request->slideshowPresent)->toBeTrue()
        ->and($request->slideshow)->toBeNull();
});
