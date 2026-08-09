<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationDisplayTabPageContext;

test('toArray flattens under display', function (): void {
    $context = new ConfigurationDisplayTabPageContext([
        'menubar_filter_icon' => true,
        'index_new_icon' => false,
        'picture_informations' => 'file',
        'NB_CATEGORIES_PAGE' => 5,
    ]);

    expect($context->toArray())->toBe([
        'display' => [
            'menubar_filter_icon' => true,
            'index_new_icon' => false,
            'picture_informations' => 'file',
            'NB_CATEGORIES_PAGE' => 5,
        ],
    ]);
});
