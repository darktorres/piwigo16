<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationSearchTabPageContext;

test('toArray flattens search and SHOW_FILTER_RATINGS', function (): void {
    $context = new ConfigurationSearchTabPageContext(
        search: [
            'filters_views' => [],
            'filters_names' => ['tags'],
        ],
        showFilterRatings: true,
    );

    expect($context->toArray())
        ->toBe([
            'search' => [
                'filters_views' => [],
                'filters_names' => ['tags'],
            ],
            'SHOW_FILTER_RATINGS' => true,
        ]);
});
