<?php

declare(strict_types=1);

use Piwigo\Section\Request\FavoritesActionRequest;

test('fromArray reports false for an empty GET', function (): void {
    $request = FavoritesActionRequest::fromArray([]);

    expect($request->removeAllFromFavorites)
        ->toBeFalse();
});

test('fromArray reports true only for the exact action value', function (): void {
    $matching = FavoritesActionRequest::fromArray([
        'action' => 'remove_all_from_favorites',
    ]);
    $other = FavoritesActionRequest::fromArray([
        'action' => 'something_else',
    ]);

    expect($matching->removeAllFromFavorites)
        ->toBeTrue()
        ->and($other->removeAllFromFavorites)
        ->toBeFalse();
});

test('fromGlobals reads $_GET directly', function (): void {
    $original = $_GET;
    $_GET = [
        'action' => 'remove_all_from_favorites',
    ];

    try {
        $request = FavoritesActionRequest::fromGlobals();

        expect($request->removeAllFromFavorites)
            ->toBeTrue();
    } finally {
        $_GET = $original;
    }
});
