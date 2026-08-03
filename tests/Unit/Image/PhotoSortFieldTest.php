<?php

declare(strict_types=1);

use Piwigo\Image\PhotoSortField;

/**
 * Piwigo\Image\PhotoSortField -- the typed replacement for
 * Ws\WsHelper::stdImageSqlOrder()'s own per-token field allowlist (Item 14
 * Sub-phase C2). Every one of the 8 real WS `order` tokens, its 2 aliases,
 * and one invalid token.
 */
test('fromToken recognizes every real sortable field', function (string $token, PhotoSortField $expected): void {
    expect(PhotoSortField::fromToken($token))->toBe($expected);
})->with([
    ['id', PhotoSortField::Id],
    ['file', PhotoSortField::File],
    ['name', PhotoSortField::Name],
    ['hit', PhotoSortField::Hit],
    ['rating_score', PhotoSortField::RatingScore],
    ['date_creation', PhotoSortField::DateCreation],
    ['date_available', PhotoSortField::DateAvailable],
    ['rand', PhotoSortField::Random],
]);

test('fromToken maps date_created to DateCreation and date_posted to DateAvailable', function (): void {
    expect(PhotoSortField::fromToken('date_created'))->toBe(PhotoSortField::DateCreation)
        ->and(PhotoSortField::fromToken('date_posted'))->toBe(PhotoSortField::DateAvailable);
});

test('fromToken maps random to the same field as rand', function (): void {
    expect(PhotoSortField::fromToken('random'))->toBe(PhotoSortField::Random);
});

test('fromToken returns null for an unrecognized token', function (): void {
    expect(PhotoSortField::fromToken('not_a_real_column'))->toBeNull();
});

test('column returns the real column or function name for every field', function (): void {
    expect(PhotoSortField::Id->column())->toBe('id')
        ->and(PhotoSortField::File->column())->toBe('file')
        ->and(PhotoSortField::Name->column())->toBe('name')
        ->and(PhotoSortField::Hit->column())->toBe('hit')
        ->and(PhotoSortField::RatingScore->column())->toBe('rating_score')
        ->and(PhotoSortField::DateCreation->column())->toBe('date_creation')
        ->and(PhotoSortField::DateAvailable->column())->toBe('date_available')
        ->and(PhotoSortField::Random->column())->toBe('RAND()');
});
