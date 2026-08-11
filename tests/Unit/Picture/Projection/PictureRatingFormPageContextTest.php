<?php

declare(strict_types=1);

use Piwigo\Picture\Projection\PictureRatingFormPageContext;

test('toArray nests the rating form fields under the rating key', function (): void {
    $context = new PictureRatingFormPageContext(formAction: '/picture.php?action=rate', userRate: 3, marks: [0, 1, 2, 3, 4, 5]);

    expect($context->toArray())
        ->toBe([
            'rating' => [
                'F_ACTION' => '/picture.php?action=rate',
                'USER_RATE' => 3,
                'marks' => [0, 1, 2, 3, 4, 5],
            ],
        ]);
});

test('toArray allows a null user rate', function (): void {
    $context = new PictureRatingFormPageContext(formAction: '/picture.php?action=rate', userRate: null, marks: []);

    expect($context->toArray())
        ->toBe([
            'rating' => [
                'F_ACTION' => '/picture.php?action=rate',
                'USER_RATE' => null,
                'marks' => [],
            ],
        ]);
});
