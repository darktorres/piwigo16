<?php

declare(strict_types=1);

use Piwigo\Controller\Api\Uploads\TusMetadataHeader;

test('parse decodes a real multi-pair Upload-Metadata header', function (): void {
    $header = implode(',', [
        'filename ' . base64_encode('photo.jpg'),
        'category ' . base64_encode('5,6'),
    ]);

    expect(TusMetadataHeader::parse($header))
        ->toBe([
            'filename' => 'photo.jpg',
            'category' => '5,6',
        ]);
});

test('parse returns an empty array for an empty header', function (): void {
    expect(TusMetadataHeader::parse(''))
        ->toBe([]);
});

test('parse decodes a flag-only key (no value half) to an empty string', function (): void {
    expect(TusMetadataHeader::parse('isPrivate'))
        ->toBe([
            'isPrivate' => '',
        ]);
});

test('parse trims surrounding whitespace around each comma-separated pair', function (): void {
    $header = ' filename ' . base64_encode('a.jpg') . ' , category ' . base64_encode('5') . ' ';

    expect(TusMetadataHeader::parse($header))
        ->toBe([
            'filename' => 'a.jpg',
            'category' => '5',
        ]);
});

test('parse skips an empty pair produced by a stray comma', function (): void {
    $header = 'filename ' . base64_encode('a.jpg') . ',,category ' . base64_encode('5');

    expect(TusMetadataHeader::parse($header))
        ->toBe([
            'filename' => 'a.jpg',
            'category' => '5',
        ]);
});

test('parse trims a pair before splitting, so a leading space never survives as the key/value delimiter', function (): void {
    // trim() runs on the whole pair before explode(' ', ..., 2) ever
    // looks for a delimiter, so " <base64>" isn't "an empty key plus a
    // value" -- the leading space is gone by the time explode() runs,
    // and the whole trimmed string becomes a flag-only key instead (same
    // shape as the dedicated flag-only-key test above).
    $header = ' ' . base64_encode('orphan-value');

    expect(TusMetadataHeader::parse($header))
        ->toBe([
            base64_encode('orphan-value') => '',
        ]);
});

test('parse decodes an invalid base64 value to an empty string rather than throwing', function (): void {
    $header = 'filename not-valid-base64!!!';

    expect(TusMetadataHeader::parse($header))
        ->toBe([
            'filename' => '',
        ]);
});

test('parse keeps only the last value when the same key appears twice', function (): void {
    $header = implode(',', [
        'filename ' . base64_encode('first.jpg'),
        'filename ' . base64_encode('second.jpg'),
    ]);

    expect(TusMetadataHeader::parse($header))
        ->toBe([
            'filename' => 'second.jpg',
        ]);
});
