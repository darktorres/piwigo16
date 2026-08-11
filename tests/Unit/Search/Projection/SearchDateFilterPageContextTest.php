<?php

declare(strict_types=1);

use Piwigo\Search\Projection\SearchDateFilterPageContext;

test('toArray flattens under the date_posted key pair', function (): void {
    $context = new SearchDateFilterPageContext(
        listKey: 'LIST_DATE_POSTED',
        counterKey: 'DATE_POSTED',
        listOfDates: [
            '2026' => [
                'label' => 'year 2026',
                'count' => 3,
            ],
        ],
        counters: [
            '24h' => [
                'label' => 'last 24 hours',
                'counter' => 1,
            ],
        ],
    );

    expect($context->toArray())
        ->toBe([
            'LIST_DATE_POSTED' => [
                '2026' => [
                    'label' => 'year 2026',
                    'count' => 3,
                ],
            ],
            'DATE_POSTED' => [
                '24h' => [
                    'label' => 'last 24 hours',
                    'counter' => 1,
                ],
            ],
        ]);
});

test('toArray flattens under the date_created key pair', function (): void {
    $context = new SearchDateFilterPageContext(
        listKey: 'LIST_DATE_CREATED',
        counterKey: 'DATE_CREATED',
        listOfDates: [],
        counters: [],
    );

    expect($context->toArray())
        ->toBe([
            'LIST_DATE_CREATED' => [],
            'DATE_CREATED' => [],
        ]);
});
