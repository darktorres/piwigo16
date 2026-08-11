<?php

declare(strict_types=1);

use Piwigo\Admin\Request\ElementSetRanksRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = ElementSetRanksRequest::fromArrays([], []);

    expect($request->isCatIdValid)
        ->toBeFalse()
        ->and($request->catId)
        ->toBe(0)
        ->and($request->isSubmitted)
        ->toBeFalse()
        ->and($request->rankOfImage)
        ->toBe([])
        ->and($request->imageOrderChoice)
        ->toBe('default')
        ->and($request->imageOrderFields)
        ->toBe([])
        ->and($request->isImageOrderSubcats)
        ->toBeFalse();
});

test('fromArrays parses a numeric cat_id', function (): void {
    $request = ElementSetRanksRequest::fromArrays([
        'cat_id' => '12',
    ], []);

    expect($request->isCatIdValid)
        ->toBeTrue()
        ->and($request->catId)
        ->toBe(12);
});

test('fromArrays reports catId 0 and isCatIdValid false for a non-numeric cat_id', function (): void {
    $request = ElementSetRanksRequest::fromArrays([
        'cat_id' => 'abc',
    ], []);

    expect($request->isCatIdValid)
        ->toBeFalse()
        ->and($request->catId)
        ->toBe(0);
});

test('fromArrays filters rank_of_image to numeric values only, sorted ascending', function (): void {
    $request = ElementSetRanksRequest::fromArrays([], [
        'submit' => '1',
        'rank_of_image' => [
            '10' => '3',
            '11' => 'abc',
            '12' => '1',
        ],
    ]);

    expect(array_keys($request->rankOfImage))
        ->toBe([12, 10]);
});

test('fromArrays keeps a valid image_order_choice', function (): void {
    $request = ElementSetRanksRequest::fromArrays([], [
        'image_order_choice' => 'rank',
    ]);

    expect($request->imageOrderChoice)
        ->toBe('rank');
});

test('fromArrays keeps user_define as a valid image_order_choice', function (): void {
    $request = ElementSetRanksRequest::fromArrays([], [
        'image_order_choice' => 'user_define',
    ]);

    expect($request->imageOrderChoice)
        ->toBe('user_define');
});

test('fromArrays falls back to default for an unknown image_order_choice', function (): void {
    $request = ElementSetRanksRequest::fromArrays([], [
        'image_order_choice' => 'not_a_real_choice',
    ]);

    expect($request->imageOrderChoice)
        ->toBe('default');
});

test('fromArrays exposes the raw image_order array', function (): void {
    $request = ElementSetRanksRequest::fromArrays([], [
        'image_order' => ['name ASC', 'file DESC'],
    ]);

    expect($request->imageOrderFields)
        ->toBe(['name ASC', 'file DESC']);
});

test('fromArrays reports isImageOrderSubcats when present', function (): void {
    $request = ElementSetRanksRequest::fromArrays([], [
        'image_order_subcats' => '1',
    ]);

    expect($request->isImageOrderSubcats)
        ->toBeTrue();
});
