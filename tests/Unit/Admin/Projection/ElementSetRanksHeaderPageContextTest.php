<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\ElementSetRanksHeaderPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new ElementSetRanksHeaderPageContext(
        categoriesNav: '<a href="/admin.php">Home</a>',
        formAction: '/admin.php',
        pwgToken: 'token123',
        imageOrderOptions: ['' => '', 'file ASC' => 'File name, A to Z'],
        imageOrderChoice: 'rank',
    );

    expect($context->toArray())->toBe([
        'CATEGORIES_NAV' => '<a href="/admin.php">Home</a>',
        'F_ACTION' => '/admin.php',
        'PWG_TOKEN' => 'token123',
        'image_order_options' => ['' => '', 'file ASC' => 'File name, A to Z'],
        'image_order_choice' => 'rank',
    ]);
});
