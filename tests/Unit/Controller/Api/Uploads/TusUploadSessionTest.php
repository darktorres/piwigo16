<?php

declare(strict_types=1);

use Piwigo\Controller\Api\Uploads\TusUploadSession;

test('create narrows a real Upload-Metadata-shaped array into every property', function (): void {
    $session = TusUploadSession::create('abc123', 500, 7, [
        'filename' => 'photo.jpg',
        'category' => '5,6,7',
        'level' => '2',
        'name' => 'My Photo',
        'author' => 'Alice',
        'comment' => 'A comment',
        'dateCreation' => '2026-01-01 00:00:00',
        'tags' => '10,11',
        'imageId' => '42',
    ]);

    expect($session->id)
        ->toBe('abc123')
        ->and($session->uploadLength)
        ->toBe(500)
        ->and($session->createdByUserId)
        ->toBe(7)
        ->and($session->filename)
        ->toBe('photo.jpg')
        ->and($session->categoryIds)
        ->toBe([5, 6, 7])
        ->and($session->level)
        ->toBe(2)
        ->and($session->name)
        ->toBe('My Photo')
        ->and($session->author)
        ->toBe('Alice')
        ->and($session->comment)
        ->toBe('A comment')
        ->and($session->dateCreation)
        ->toBe('2026-01-01 00:00:00')
        ->and($session->tagIds)
        ->toBe([10, 11])
        ->and($session->imageId)
        ->toBe(42)
        ->and($session->formatOf)
        ->toBeNull();
});

test('create defaults every optional field for empty/missing metadata', function (): void {
    $session = TusUploadSession::create('id', 100, 1, []);

    expect($session->filename)
        ->toBe('')
        ->and($session->categoryIds)
        ->toBe([])
        ->and($session->level)
        ->toBe(0)
        ->and($session->name)
        ->toBeNull()
        ->and($session->author)
        ->toBeNull()
        ->and($session->comment)
        ->toBeNull()
        ->and($session->dateCreation)
        ->toBeNull()
        ->and($session->tagIds)
        ->toBe([])
        ->and($session->imageId)
        ->toBeNull()
        ->and($session->formatOf)
        ->toBeNull();
});

test('create treats an empty-string optional value the same as absent', function (): void {
    $session = TusUploadSession::create('id', 100, 1, [
        'name' => '',
        'author' => '',
    ]);

    expect($session->name)
        ->toBeNull()
        ->and($session->author)
        ->toBeNull();
});

test('create drops non-numeric pieces from a category/tags csv list', function (): void {
    $session = TusUploadSession::create('id', 100, 1, [
        'category' => '5,not-a-number,7',
    ]);

    expect($session->categoryIds)
        ->toBe([5, 7]);
});

test('create parses formatOf when given', function (): void {
    $session = TusUploadSession::create('id', 100, 1, [
        'formatOf' => '9',
    ]);

    expect($session->formatOf)
        ->toBe(9)
        ->and($session->imageId)
        ->toBeNull();
});

test('toArray then fromArray round-trips every field exactly', function (): void {
    $original = TusUploadSession::create('roundtrip-id', 500, 7, [
        'filename' => 'photo.jpg',
        'category' => '5,6',
        'level' => '2',
        'name' => 'My Photo',
        'author' => 'Alice',
        'comment' => 'A comment',
        'dateCreation' => '2026-01-01 00:00:00',
        'tags' => '10,11',
        'imageId' => '42',
    ]);

    $restored = TusUploadSession::fromArray($original->toArray());

    expect($restored->id)
        ->toBe($original->id)
        ->and($restored->uploadLength)
        ->toBe($original->uploadLength)
        ->and($restored->createdByUserId)
        ->toBe($original->createdByUserId)
        ->and($restored->filename)
        ->toBe($original->filename)
        ->and($restored->categoryIds)
        ->toBe($original->categoryIds)
        ->and($restored->level)
        ->toBe($original->level)
        ->and($restored->name)
        ->toBe($original->name)
        ->and($restored->author)
        ->toBe($original->author)
        ->and($restored->comment)
        ->toBe($original->comment)
        ->and($restored->dateCreation)
        ->toBe($original->dateCreation)
        ->and($restored->tagIds)
        ->toBe($original->tagIds)
        ->and($restored->imageId)
        ->toBe($original->imageId)
        ->and($restored->formatOf)
        ->toBe($original->formatOf);
});

test('fromArray defaults every field when given a genuinely empty array', function (): void {
    $session = TusUploadSession::fromArray([]);

    expect($session->id)
        ->toBe('')
        ->and($session->uploadLength)
        ->toBe(0)
        ->and($session->createdByUserId)
        ->toBe(0)
        ->and($session->filename)
        ->toBe('')
        ->and($session->categoryIds)
        ->toBe([])
        ->and($session->level)
        ->toBe(0)
        ->and($session->tagIds)
        ->toBe([]);
});

test('fromArray drops a non-int entry from a decoded categoryIds/tagIds array', function (): void {
    $session = TusUploadSession::fromArray([
        'categoryIds' => [5, 'six', 7, null],
        'tagIds' => [10, '11'],
    ]);

    expect($session->categoryIds)
        ->toBe([5, 7])
        ->and($session->tagIds)
        ->toBe([10]);
});

test('fromArray narrows a wrong-typed field to its zero value instead of throwing', function (): void {
    $session = TusUploadSession::fromArray([
        'id' => 123,
        'uploadLength' => 'not-an-int',
        'createdByUserId' => null,
        'filename' => false,
        'categoryIds' => 'not-an-array',
        'name' => 42,
    ]);

    expect($session->id)
        ->toBe('')
        ->and($session->uploadLength)
        ->toBe(0)
        ->and($session->createdByUserId)
        ->toBe(0)
        ->and($session->filename)
        ->toBe('')
        ->and($session->categoryIds)
        ->toBe([])
        ->and($session->name)
        ->toBeNull();
});
