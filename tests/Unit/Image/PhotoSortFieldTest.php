<?php

declare(strict_types=1);

use Piwigo\Db\DbCredentials;
use Piwigo\Image\OrderByClause;
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
    // Random/Rank both read DbCredentials::fromEnv()->driver internally
    // (no Connection available in this plain enum method -- see
    // column()'s own docblock) -- driver-aware here so this test passes
    // regardless of which platform .env.test currently points at.
    $pgsql = DbCredentials::fromEnv()->driver === 'pgsql';

    expect(PhotoSortField::Id->column())->toBe('id')
        ->and(PhotoSortField::File->column())->toBe('file')
        ->and(PhotoSortField::Name->column())->toBe('name')
        ->and(PhotoSortField::Hit->column())->toBe('hit')
        ->and(PhotoSortField::RatingScore->column())->toBe('rating_score')
        ->and(PhotoSortField::DateCreation->column())->toBe('date_creation')
        ->and(PhotoSortField::DateAvailable->column())->toBe('date_available')
        ->and(PhotoSortField::Random->column())->toBe($pgsql ? 'RANDOM()' : 'RAND()')
        ->and(PhotoSortField::Rank->column())->toBe($pgsql ? '"rank"' : '`rank`');
});

/**
 * Item 16J: fromSortFieldToken() matches
 * Controller\Admin\ConfigurationSubController.php's own real `$sort_fields`
 * vocabulary -- 7 plain fields plus `rank`, deliberately not the same
 * alias set as fromToken() above (no date_created/date_posted/rand/random,
 * since `$sort_fields` never produces those tokens).
 */
test('fromSortFieldToken recognizes every real $sort_fields entry', function (string $token, PhotoSortField $expected): void {
    expect(PhotoSortField::fromSortFieldToken($token))->toBe($expected);
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

test('fromSortFieldToken rejects fromToken-only aliases', function (string $token): void {
    expect(PhotoSortField::fromSortFieldToken($token))->toBeNull();
})->with(['date_created', 'date_posted', 'rand', 'random']);

test('parseOrderByFragment parses a real multi-field CurrentConfig::orderBy() string', function (): void {
    $entries = PhotoSortField::parseOrderByFragment('ORDER BY date_available DESC, file ASC, id ASC');

    expect($entries)->toBe([
        ['field' => PhotoSortField::DateAvailable, 'dir' => 'DESC'],
        ['field' => PhotoSortField::File, 'dir' => 'ASC'],
        ['field' => PhotoSortField::Id, 'dir' => 'ASC'],
    ]);
});

test('parseOrderByFragment parses the backtick-quoted rank entry', function (): void {
    expect(PhotoSortField::parseOrderByFragment('ORDER BY `rank` ASC'))->toBe([
        ['field' => PhotoSortField::Rank, 'dir' => 'ASC'],
    ]);
});

test('parseOrderByFragment is case-insensitive on the ORDER BY prefix and direction', function (): void {
    expect(PhotoSortField::parseOrderByFragment('order by id desc'))->toBe([
        ['field' => PhotoSortField::Id, 'dir' => 'DESC'],
    ]);
});

test('parseOrderByFragment trims whitespace the ORDER BY prefix regex would not otherwise consume (e.g. a leading NUL byte)', function (): void {
    // The prefix regex's own `^\s*` already absorbs ordinary leading
    // whitespace before "ORDER BY", so unwrapping the outer trim() call
    // is only observable via a char trim()'s default charlist covers
    // but PCRE's \s does not -- a leading NUL byte is one: trim()
    // strips it, but \s* doesn't match it, so without trim() the
    // prefix regex fails to match at all (its `^` anchor can never
    // skip past the NUL) and the whole fragment is wrongly rejected.
    expect(PhotoSortField::parseOrderByFragment("\0ORDER BY id ASC"))->toBe([
        ['field' => PhotoSortField::Id, 'dir' => 'ASC'],
    ]);
});

test('parseOrderByFragment lowercases the captured field token before matching it, since the per-entry regex is case-insensitive on the field name too', function (): void {
    // The per-entry regex's `[a-z_]+` is matched with the /i flag, so
    // an uppercase field name is captured verbatim (e.g. "ID", not
    // "id") -- fromSortFieldToken()'s own match() arms are lowercase
    // literals compared with strict ===, so a captured token that
    // isn't lowercased first would never match any of them and the
    // whole fragment would be wrongly rejected as unparseable.
    expect(PhotoSortField::parseOrderByFragment('ORDER BY ID ASC'))->toBe([
        ['field' => PhotoSortField::Id, 'dir' => 'ASC'],
    ]);
});

test('parseOrderByFragment returns null for text outside the bounded vocabulary', function (string $fragment): void {
    expect(PhotoSortField::parseOrderByFragment($fragment))->toBeNull();
})->with([
    'ORDER BY RAND()',
    'ORDER BY comment ASC',
    'ORDER BY id',
    '',
    'ORDER BY ',
]);

test('dqlOrderProperty maps every base field against the image alias', function (): void {
    expect(PhotoSortField::Id->dqlOrderProperty('i'))->toBe('i.id')
        ->and(PhotoSortField::File->dqlOrderProperty('i'))->toBe('i.file')
        ->and(PhotoSortField::Name->dqlOrderProperty('i'))->toBe('i.name')
        ->and(PhotoSortField::Hit->dqlOrderProperty('i'))->toBe('i.hit')
        ->and(PhotoSortField::RatingScore->dqlOrderProperty('i'))->toBe('i.ratingScore')
        ->and(PhotoSortField::DateCreation->dqlOrderProperty('i'))->toBe('i.dateCreation')
        ->and(PhotoSortField::DateAvailable->dqlOrderProperty('i'))->toBe('i.dateAvailable');
});

test('dqlOrderProperty returns null for Random regardless of alias', function (): void {
    expect(PhotoSortField::Random->dqlOrderProperty('i'))->toBeNull();
});

test('dqlOrderProperty returns null for Rank without an image_category alias', function (): void {
    expect(PhotoSortField::Rank->dqlOrderProperty('i'))->toBeNull()
        ->and(PhotoSortField::Rank->dqlOrderProperty('i', null))->toBeNull();
});

test('dqlOrderProperty resolves Rank against the image_category alias when given one', function (): void {
    expect(PhotoSortField::Rank->dqlOrderProperty('i', 'ic'))->toBe('ic.rank');
});

test('resolveDqlOrderBy parses and maps a multi-field fragment in one step', function (): void {
    expect(PhotoSortField::resolveDqlOrderBy('ORDER BY date_available DESC, file ASC, id ASC', 'i'))->toEqual([
        new OrderByClause('i.dateAvailable', 'DESC'),
        new OrderByClause('i.file', 'ASC'),
        new OrderByClause('i.id', 'ASC'),
    ]);
});

test('resolveDqlOrderBy returns null for unparseable text', function (): void {
    expect(PhotoSortField::resolveDqlOrderBy('ORDER BY RAND()', 'i'))->toBeNull();
});

test('resolveDqlOrderBy returns null when an entry has no property path in this query', function (): void {
    // Rank requested, but no image_category alias supplied.
    expect(PhotoSortField::resolveDqlOrderBy('ORDER BY `rank` ASC', 'i'))->toBeNull();
});

test('resolveDqlOrderBy resolves Rank when an image_category alias is supplied', function (): void {
    expect(PhotoSortField::resolveDqlOrderBy('ORDER BY `rank` ASC', 'i', 'ic'))->toEqual([
        new OrderByClause('ic.rank', 'ASC'),
    ]);
});
