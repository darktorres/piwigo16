<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\GroupPermPageContext;
use Piwigo\Category\Projection\CategorySelectOptions;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new GroupPermPageContext(
        title: 'Manage permissions for group "Family"',
        catOptionsTrueLabel: 'Authorized',
        catOptionsFalseLabel: 'Forbidden',
        formAction: '/admin.php?page=group_perm&group_id=3',
        pwgToken: 'token123',
        categoryOptionTrue: new CategorySelectOptions(options: [
            1 => 'Holidays',
        ], selected: []),
        categoryOptionFalse: new CategorySelectOptions(options: [], selected: []),
    );

    expect($context->toArray())
        ->toBe([
            'TITLE' => 'Manage permissions for group "Family"',
            'L_CAT_OPTIONS_TRUE' => 'Authorized',
            'L_CAT_OPTIONS_FALSE' => 'Forbidden',
            'F_ACTION' => '/admin.php?page=group_perm&group_id=3',
            'CSRF_TOKEN' => 'token123',
            'category_option_true' => [
                1 => 'Holidays',
            ],
            'category_option_true_selected' => [],
            'category_option_false' => [],
            'category_option_false_selected' => [],
        ]);
});
