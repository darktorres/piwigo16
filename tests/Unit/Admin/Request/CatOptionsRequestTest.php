<?php

declare(strict_types=1);

use Piwigo\Admin\Request\CatOptionsRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = CatOptionsRequest::fromArrays([], []);

    expect($request->isSubmitted)->toBeFalse()
        ->and($request->catTrue)->toBe([])
        ->and($request->catFalse)->toBe([])
        ->and($request->isFalsify)->toBeFalse()
        ->and($request->isTrueify)->toBeFalse()
        ->and($request->sectionRaw)->toBe('')
        ->and($request->section)->toBe('status');
});

test('fromArrays parses a falsify submission', function (): void {
    $request = CatOptionsRequest::fromArrays(['section' => 'visible'], [
        'falsify' => '1',
        'cat_true' => ['1', '2'],
    ]);

    expect($request->isSubmitted)->toBeTrue()
        ->and($request->isFalsify)->toBeTrue()
        ->and($request->catTrue)->toBe([1, 2])
        ->and($request->sectionRaw)->toBe('visible')
        ->and($request->section)->toBe('visible');
});

test('fromArrays rejects a non-digit cat_true element', function (): void {
    expect(fn (): CatOptionsRequest => CatOptionsRequest::fromArrays([], ['cat_true' => ['1; DROP TABLE']]))
        ->toThrow(RuntimeException::class);
});

test('fromArrays rejects a non-digit cat_false element', function (): void {
    expect(fn (): CatOptionsRequest => CatOptionsRequest::fromArrays([], ['cat_false' => ['1; DROP TABLE']]))
        ->toThrow(RuntimeException::class);
});

test('fromArrays rejects a malformed section when POST is non-empty', function (): void {
    expect(fn (): CatOptionsRequest => CatOptionsRequest::fromArrays(['section' => 'bad;section'], ['falsify' => '1']))
        ->toThrow(RuntimeException::class);
});

test('fromArrays defaults sectionRaw to an empty string when section is present but not a string', function (): void {
    $request = CatOptionsRequest::fromArrays(['section' => ['x']], []);

    expect($request->sectionRaw)->toBe('');
});

test('fromArrays defaults section to status for an unrecognized value', function (): void {
    $request = CatOptionsRequest::fromArrays(['section' => 'unknown'], []);

    expect($request->section)->toBe('status')
        ->and($request->sectionRaw)->toBe('unknown');
});

test('fromArrays parses a trueify submission', function (): void {
    $request = CatOptionsRequest::fromArrays([], [
        'trueify' => '1',
        'cat_false' => ['3', '4'],
    ]);

    expect($request->isTrueify)->toBeTrue()
        ->and($request->catFalse)->toBe([3, 4]);
});

test('fromArrays recognizes all 4 known section values', function (): void {
    foreach (['comments', 'visible', 'status', 'representative'] as $section) {
        expect(CatOptionsRequest::fromArrays(['section' => $section], [])->section)->toBe($section);
    }
});
