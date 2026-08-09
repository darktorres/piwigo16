<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\RatingUserPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new RatingUserPageContext(
        orderByIndex: 4,
        formAction: '/admin.php',
        minRates: 0,
        consensusTopNumber: 5,
        availableRates: [0, 1, 2, 3, 4, 5],
        ratings: ['alice' => ['id' => 1, 'count' => 3]],
        imageUrls: [1 => ['tn' => '/i.php?/1-sq.jpg', 'page' => '/picture.php?/1']],
        tnWidth: 120,
        nbElements: 42,
        adminPageTitle: 'Rating',
        orderByOptions: ['Average rate', 'Number of rates'],
    );

    expect($context->toArray())->toBe([
        'order_by_options_selected' => [4],
        'F_ACTION' => '/admin.php',
        'F_MIN_RATES' => 0,
        'CONSENSUS_TOP_NUMBER' => 5,
        'available_rates' => [0, 1, 2, 3, 4, 5],
        'ratings' => ['alice' => ['id' => 1, 'count' => 3]],
        'image_urls' => [1 => ['tn' => '/i.php?/1-sq.jpg', 'page' => '/picture.php?/1']],
        'TN_WIDTH' => 120,
        'NB_ELEMENTS' => 42,
        'ADMIN_PAGE_TITLE' => 'Rating',
        'order_by_options' => ['Average rate', 'Number of rates'],
    ]);
});
