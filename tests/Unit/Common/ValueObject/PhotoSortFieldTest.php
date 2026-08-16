<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\PhotoSortField;

/**
 * Piwigo\Common\ValueObject\PhotoSortField -- vocabulary only.
 *
 * Nothing here touches a database, which is the point of the split: the
 * platform-dependent half (column names, `rank` quoting, RAND()/RANDOM())
 * lives in Piwigo\Db\SortRenderer and is covered by its own test.
 */
test('fromWsToken recognizes every real sortable field', function (string $token, PhotoSortField $expected): void {
    expect(PhotoSortField::fromWsToken($token))->toBe($expected);
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

test('fromWsToken maps date_created to DateCreation and date_posted to DateAvailable', function (): void {
    expect(PhotoSortField::fromWsToken('date_created'))->toBe(PhotoSortField::DateCreation)
        ->and(PhotoSortField::fromWsToken('date_posted'))->toBe(PhotoSortField::DateAvailable);
});

test('fromWsToken maps random to the same field as rand', function (): void {
    expect(PhotoSortField::fromWsToken('random'))->toBe(PhotoSortField::Random);
});

test('fromWsToken returns null for an unrecognized token', function (): void {
    expect(PhotoSortField::fromWsToken('not_a_real_column'))->toBeNull();
});

test('fromWsToken has no rank entry -- rank is config-only vocabulary', function (): void {
    expect(PhotoSortField::fromWsToken('rank'))->toBeNull();
});

/**
 * fromConfigToken() matches ConfigurationSubController's own `$sort_fields`
 * vocabulary -- 7 plain fields plus `rank`, deliberately not the same alias
 * set as fromWsToken(), since `$sort_fields` never produces those tokens.
 */
test('fromConfigToken recognizes every real $sort_fields entry', function (string $token, PhotoSortField $expected): void {
    expect(PhotoSortField::fromConfigToken($token))->toBe($expected);
})->with([
    ['id', PhotoSortField::Id],
    ['file', PhotoSortField::File],
    ['name', PhotoSortField::Name],
    ['hit', PhotoSortField::Hit],
    ['rating_score', PhotoSortField::RatingScore],
    ['date_creation', PhotoSortField::DateCreation],
    ['date_available', PhotoSortField::DateAvailable],
    ['rank', PhotoSortField::Rank],
]);

test('fromConfigToken rejects the WS-only aliases', function (string $token): void {
    expect(PhotoSortField::fromConfigToken($token))->toBeNull();
})->with(['date_created', 'date_posted', 'rand', 'random']);

test('configToken is a fixed literal per field, identical on every platform', function (): void {
    // The whole reason this is not derived from the platform's quoting: the
    // stored format and the $sort_fields option keys are the same strings on
    // MySQL and PostgreSQL, and deriving them made `rank` render as "rank"
    // on PostgreSQL, matching no option key.
    expect(PhotoSortField::Id->configToken())->toBe('id')
        ->and(PhotoSortField::File->configToken())->toBe('file')
        ->and(PhotoSortField::Name->configToken())->toBe('name')
        ->and(PhotoSortField::Hit->configToken())->toBe('hit')
        ->and(PhotoSortField::RatingScore->configToken())->toBe('rating_score')
        ->and(PhotoSortField::DateCreation->configToken())->toBe('date_creation')
        ->and(PhotoSortField::DateAvailable->configToken())->toBe('date_available')
        ->and(PhotoSortField::Random->configToken())->toBe('RAND()')
        ->and(PhotoSortField::Rank->configToken())->toBe('`rank`');
});

test('every configToken round-trips back through fromConfigToken', function (PhotoSortField $field): void {
    // Random is excluded: it has no $sort_fields entry, so its token is the
    // fragment-parser literal rather than a field name.
    expect(PhotoSortField::fromConfigToken(trim($field->configToken(), '`')))->toBe($field);
})->with([
    PhotoSortField::Id,
    PhotoSortField::File,
    PhotoSortField::Name,
    PhotoSortField::Hit,
    PhotoSortField::RatingScore,
    PhotoSortField::DateCreation,
    PhotoSortField::DateAvailable,
    PhotoSortField::Rank,
]);
