<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategorySelectOptions;
use Piwigo\Controller\Projection\CommentsPageContext;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SizingParams;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $derivativeParams = new DerivativeParams(new SizingParams([100, 100]));

    $context = new CommentsPageContext(
        fAction: '/comments.php',
        fKeyword: 'sunset',
        fAuthor: 'jane',
        sinceOptions: [
            1 => 'today',
            2 => 'last 7 days',
        ],
        sinceOptionsSelected: 4,
        sortByOptions: [
            'date' => 'comment date',
        ],
        sortByOptionsSelected: 'date',
        sortOrderOptions: [
            'DESC' => 'descending',
        ],
        sortOrderOptionsSelected: 'DESC',
        itemNumberOptions: [
            5 => 5,
            'all' => 'all',
        ],
        itemNumberOptionsSelected: 20,
        navbar: [
            'NB_PAGE' => 3,
        ],
        commentDerivativeParams: $derivativeParams,
        comments: [[
            'ID' => 5,
            'AUTHOR' => 'jane',
        ]],
        categoriesOptions: new CategorySelectOptions(options: [
            1 => 'Holidays',
        ], selected: [1]),
    );

    expect($context->toArray())
        ->toBe([
            'F_ACTION' => '/comments.php',
            'F_KEYWORD' => 'sunset',
            'F_AUTHOR' => 'jane',
            'since_options' => [
                1 => 'today',
                2 => 'last 7 days',
            ],
            'since_options_selected' => 4,
            'sort_by_options' => [
                'date' => 'comment date',
            ],
            'sort_by_options_selected' => 'date',
            'sort_order_options' => [
                'DESC' => 'descending',
            ],
            'sort_order_options_selected' => 'DESC',
            'item_number_options' => [
                5 => 5,
                'all' => 'all',
            ],
            'item_number_options_selected' => 20,
            'navbar' => [
                'NB_PAGE' => 3,
            ],
            'comment_derivative_params' => $derivativeParams,
            'comments' => [[
                'ID' => 5,
                'AUTHOR' => 'jane',
            ]],
            'categories' => [
                1 => 'Holidays',
            ],
            'categories_selected' => [1],
        ]);
});

test('toArray includes an empty comments list (not omitted)', function (): void {
    $derivativeParams = new DerivativeParams(new SizingParams([100, 100]));

    $context = new CommentsPageContext(
        fAction: '/comments.php',
        fKeyword: '',
        fAuthor: '',
        sinceOptions: [],
        sinceOptionsSelected: 0,
        sortByOptions: [],
        sortByOptionsSelected: 'date',
        sortOrderOptions: [],
        sortOrderOptionsSelected: 'DESC',
        itemNumberOptions: [],
        itemNumberOptionsSelected: 20,
        navbar: [],
        commentDerivativeParams: $derivativeParams,
        comments: [],
        categoriesOptions: new CategorySelectOptions(options: [], selected: []),
    );

    expect($context->toArray()['comments'])->toBe([]);
});
