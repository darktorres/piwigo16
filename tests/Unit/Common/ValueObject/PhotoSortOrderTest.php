<?php

declare(strict_types=1);

use Piwigo\Common\Enum\SortOrder;
use Piwigo\Common\ValueObject\PhotoSortField;
use Piwigo\Common\ValueObject\PhotoSortOrder;

/**
 * Piwigo\Common\ValueObject\PhotoSortOrder -- the structured photo sort
 * order behind CurrentConfig's own order_by/order_by_inside_category pair.
 *
 * Vocabulary only, no database: rendering lives in Piwigo\Db\SortRenderer
 * and has its own test.
 */
test('none() and an all-whitespace fragment render as no ordering at all', function (): void {
    expect(PhotoSortOrder::none()->isEmpty())
        ->toBeTrue()
        ->and(PhotoSortOrder::none()->entries())
        ->toBe([])
        ->and(PhotoSortOrder::fromConfigFragment('   ')->isEmpty())
        ->toBeTrue();
});

test('default() is the shipped order_by seed', function (): void {
    expect(PhotoSortOrder::default()->toSortFieldTokens())
        ->toBe(['date_available DESC', 'file ASC', 'id ASC']);
});

test('fromConfigFragment() round-trips a known fragment through the structured path', function (): void {
    $order = PhotoSortOrder::fromConfigFragment('ORDER BY name ASC, hit DESC');

    expect($order->toSortFieldTokens())
        ->toBe(['name ASC', 'hit DESC'])
        ->and($order->toStoredBody())
        ->toBe('name ASC, hit DESC')
        ->and($order->entries())
        ->toBe([
            [
                'field' => PhotoSortField::Name,
                'dir' => SortOrder::Asc,
            ],
            [
                'field' => PhotoSortField::Hit,
                'dir' => SortOrder::Desc,
            ],
        ]);
});

test('fromConfigFragment() parses the backtick-quoted rank entry and writes it back the same way', function (): void {
    // The backticks are part of the stored format, so they must survive a
    // parse/render round trip on every platform -- an unbackticked `rank ASC`
    // would match no $sort_fields option key in the admin form.
    expect(PhotoSortOrder::fromConfigFragment('ORDER BY `rank` ASC')->toSortFieldTokens())
        ->toBe(['`rank` ASC']);
});

test('fromConfigFragment() is case-insensitive on the ORDER BY prefix, the field and the direction', function (): void {
    expect(PhotoSortOrder::fromConfigFragment('order by ID desc')->toSortFieldTokens())
        ->toBe(['id DESC']);
});

test('fromConfigFragment() trims whitespace the ORDER BY prefix regex would not otherwise consume', function (): void {
    // The prefix regex's own `^\s*` already absorbs ordinary leading
    // whitespace, so the outer trim() is only observable via a char
    // trim()'s default charlist covers but PCRE's \s does not -- a leading
    // NUL byte is one. Without trim(), the `^` anchor can never skip past
    // it and the whole fragment is wrongly rejected.
    expect(PhotoSortOrder::fromConfigFragment("\0ORDER BY id ASC")->toSortFieldTokens())
        ->toBe(['id ASC']);
});

test('a random order stays structured despite carrying no direction', function (string $fragment): void {
    // RAND()/RANDOM() is a function call, so it never matches the
    // "<field> ASC|DESC" shape the other entries do. It still has to parse:
    // the two platforms spell it differently and only the structured path
    // is rendered per platform.
    expect(PhotoSortOrder::fromConfigFragment($fragment)->entries())
        ->toBe([
            [
                'field' => PhotoSortField::Random,
                'dir' => SortOrder::Asc,
            ],
        ]);
})->with([
    'ORDER BY RAND()',
    'ORDER BY random()',
    'ORDER BY RAND( )',
]);

test('text outside the vocabulary falls back to the default order', function (string $fragment): void {
    // There is no raw escape hatch: the admin form is the only writer and it
    // validates against $sort_fields, so unparseable text is a corrupt
    // config row, and substituting the default is what an absent row does.
    expect(PhotoSortOrder::fromConfigFragment($fragment)->toSortFieldTokens())
        ->toBe(PhotoSortOrder::default()->toSortFieldTokens());
})->with([
    'ORDER BY comment ASC',
    'ORDER BY some_plugin_column ASC NULLS LAST',
    'ORDER BY id',
]);

test('tryFromConfigFragment() reports unrepresentable text instead of substituting a default', function (): void {
    // The strict parse a *composed* fragment needs: silently replacing it
    // with the default would reorder a listing its caller ordered on purpose.
    expect(PhotoSortOrder::tryFromConfigFragment('ORDER BY comment ASC'))
        ->toBeNull()
        ->and(PhotoSortOrder::tryFromConfigFragment('ORDER BY id ASC'))
        ->not->toBeNull();
});

test('tryFromConfigFragment() treats empty text as no ordering, not as a failure', function (): void {
    $order = PhotoSortOrder::tryFromConfigFragment('  ');

    expect($order)
        ->not->toBeNull();
    assert($order instanceof PhotoSortOrder);
    expect($order->isEmpty())
        ->toBeTrue();
});

test('fromApiOrderParam() accepts the API vocabulary, including its aliases', function (): void {
    // Distinct from the config vocabulary: `rand` and the legacy
    // date_created/date_posted names are only valid here.
    expect(PhotoSortOrder::fromApiOrderParam('date_created desc, file')->toSortFieldTokens())
        ->toBe(['date_creation DESC', 'file ASC'])
        ->and(PhotoSortOrder::fromApiOrderParam('rand')->entries())
        ->toBe([
            [
                'field' => PhotoSortField::Random,
                'dir' => SortOrder::Asc,
            ],
        ])
        ->and(PhotoSortOrder::fromApiOrderParam('')->isEmpty())
        ->toBeTrue();
});

test('fromApiOrderParam() drops tokens outside the allow-list', function (): void {
    expect(PhotoSortOrder::fromApiOrderParam('not_a_column ASC')->isEmpty())
        ->toBeTrue();
});

test('toStoredBody() is empty for an empty order', function (): void {
    expect(PhotoSortOrder::none()->toStoredBody())->toBe('');
});
