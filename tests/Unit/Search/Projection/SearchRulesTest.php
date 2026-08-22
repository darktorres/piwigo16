<?php

declare(strict_types=1);

use Piwigo\Search\Projection\AllwordsRule;
use Piwigo\Search\Projection\AuthorRule;
use Piwigo\Search\Projection\CategoryRule;
use Piwigo\Search\Projection\DateRule;
use Piwigo\Search\Projection\ExpertRule;
use Piwigo\Search\Projection\SearchRules;
use Piwigo\Search\Projection\TagsRule;

test('fromArray leaves every field null when the row is empty', function (): void {
    $rules = SearchRules::fromArray([]);

    expect($rules->allwords)
        ->toBeNull()
        ->and($rules->author)
        ->toBeNull()
        ->and($rules->expert)
        ->toBeNull()
        ->and($rules->filetypes)
        ->toBeNull()
        ->and($rules->addedBy)
        ->toBeNull()
        ->and($rules->cat)
        ->toBeNull()
        ->and($rules->tags)
        ->toBeNull()
        ->and($rules->datePosted)
        ->toBeNull()
        ->and($rules->dateCreated)
        ->toBeNull()
        ->and($rules->ratios)
        ->toBeNull()
        ->and($rules->ratings)
        ->toBeNull()
        ->and($rules->filesizeMin)
        ->toBeNull()
        ->and($rules->filesizeMax)
        ->toBeNull()
        ->and($rules->widthMin)
        ->toBeNull()
        ->and($rules->widthMax)
        ->toBeNull()
        ->and($rules->heightMin)
        ->toBeNull()
        ->and($rules->heightMax)
        ->toBeNull()
        ->and($rules->toArray())
        ->toBe([]);
});

test('fromArray hydrates every field from a fully-populated row, and toArray round-trips it exactly', function (): void {
    $row = [
        'allwords' => [
            'words' => ['sunset'],
            'mode' => 'OR',
            'fields' => ['file', 'name'],
        ],
        'author' => [
            'words' => ['jane'],
        ],
        'expert' => [
            'string' => 'raw:query',
        ],
        'filetypes' => ['jpg', 'png'],
        'added_by' => [3, '4'],
        'cat' => [
            'words' => [5, '6'],
            'sub_inc' => true,
        ],
        'tags' => [
            'words' => [7, '8'],
            'mode' => 'OR',
        ],
        'date_posted' => [
            'preset' => 'custom',
            'custom' => ['y2026'],
        ],
        'date_created' => [
            'preset' => '7d',
            'custom' => [],
        ],
        'ratios' => ['Portrait', 'square'],
        'ratings' => ['3', '4'],
        'filesize_min' => 100,
        'filesize_max' => '500',
        'width_min' => 200,
        'width_max' => '',
        'height_min' => 0,
        'height_max' => 1080,
    ];

    $rules = SearchRules::fromArray($row);

    expect($rules->allwords)
        ->toEqual(new AllwordsRule(words: ['sunset'], mode: 'OR', fields: ['file', 'name']))
        ->and($rules->author)
        ->toEqual(new AuthorRule(words: ['jane']))
        ->and($rules->expert)
        ->toEqual(new ExpertRule(string: 'raw:query'))
        ->and($rules->filetypes)
        ->toBe(['jpg', 'png'])
        ->and($rules->addedBy)
        ->toBe([3, '4'])
        ->and($rules->cat)
        ->toEqual(new CategoryRule(words: [5, '6'], subInc: true))
        ->and($rules->tags)
        ->toEqual(new TagsRule(words: [7, '8'], mode: 'OR'))
        ->and($rules->datePosted)
        ->toEqual(new DateRule(preset: 'custom', custom: ['y2026']))
        ->and($rules->dateCreated)
        ->toEqual(new DateRule(preset: '7d', custom: []))
        ->and($rules->ratios)
        ->toBe(['Portrait', 'square'])
        ->and($rules->ratings)
        ->toBe(['3', '4'])
        ->and($rules->filesizeMin)
        ->toBe(100)
        ->and($rules->filesizeMax)
        ->toBe('500')
        ->and($rules->widthMin)
        ->toBe(200)
        ->and($rules->widthMax)
        ->toBe('')
        ->and($rules->heightMin)
        ->toBe(0)
        ->and($rules->heightMax)
        ->toBe(1080);

    expect($rules->toArray())
        ->toBe($row);
});

test('toArray distinguishes a present-but-empty list from an absent one', function (): void {
    $active = new SearchRules(filetypes: [], addedBy: [], ratios: [], ratings: []);
    $inactive = new SearchRules();

    expect($active->toArray())
        ->toBe([
            'filetypes' => [],
            'added_by' => [],
            'ratios' => [],
            'ratings' => [],
        ])
        ->and($inactive->toArray())
        ->toBe([]);
});

test('a filesize/width/height bound of 0 or empty string still counts as present, not absent', function (): void {
    $rules = new SearchRules(filesizeMin: 0, widthMax: '');

    expect($rules->toArray())
        ->toBe([
            'filesize_min' => 0,
            'width_max' => '',
        ]);
});

test('fromArray tolerates a garbage non-array scalar-list value by treating the field as absent', function (): void {
    $rules = SearchRules::fromArray([
        'filetypes' => 'not-an-array',
        'filesize_min' => ['not', 'scalar'],
    ]);

    expect($rules->filetypes)
        ->toBeNull()
        ->and($rules->filesizeMin)
        ->toBeNull();
});

test('fromArray coerces a present-but-garbage object-rule value to that rule\'s own empty defaults, matching SearchFilterRenderer::render()\'s pre-existing isset()-based presence check, rather than treating it as absent', function (): void {
    $rules = SearchRules::fromArray([
        'allwords' => 'not-an-array',
        'author' => 'not-an-array',
        'cat' => 'not-an-array',
        'tags' => 'not-an-array',
        'expert' => 'not-an-array',
        'date_posted' => 'not-an-array',
        'date_created' => 'not-an-array',
    ]);

    expect($rules->allwords)
        ->toEqual(new AllwordsRule())
        ->and($rules->author)
        ->toEqual(new AuthorRule())
        ->and($rules->cat)
        ->toEqual(new CategoryRule())
        ->and($rules->tags)
        ->toEqual(new TagsRule())
        ->and($rules->expert)
        ->toEqual(new ExpertRule())
        ->and($rules->datePosted)
        ->toEqual(new DateRule())
        ->and($rules->dateCreated)
        ->toEqual(new DateRule());
});

test('AllwordsRule::fromArray defaults mode to AND for anything other than the literal OR', function (): void {
    expect(AllwordsRule::fromArray([
        'mode' => 'OR',
    ])->mode)
        ->toBe('OR')
        ->and(AllwordsRule::fromArray([
            'mode' => 'or',
        ])->mode)
        ->toBe('AND')
        ->and(AllwordsRule::fromArray([])->mode)
        ->toBe('AND');
});

test('DateRule::fromArray coerces an int custom-date entry to string, matching the old inline coercion', function (): void {
    $rule = DateRule::fromArray([
        'preset' => 'custom',
        'custom' => [20260101, 'y2026'],
    ]);

    expect($rule->custom)
        ->toBe(['20260101', 'y2026']);
});
