<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationCommentsPageContext;

test('toArray flattens under comments', function (): void {
    $context = new ConfigurationCommentsPageContext([
        'NB_COMMENTS_PAGE' => 20,
        'comments_order' => 'DESC',
    ]);

    expect($context->toArray())->toBe([
        'comments' => [
            'NB_COMMENTS_PAGE' => 20,
            'comments_order' => 'DESC',
        ],
    ]);
});
