<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CatOptionsPageContext;
use Piwigo\Category\Projection\CategorySelectOptions;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new CatOptionsPageContext(
        helpUrl: '/admin/popuphelp.php?page=cat_options',
        formAction: '/admin.php?page=cat_options&section=comments',
        section: 'Authorize users to add comments on selected albums',
        catOptionsTrueLabel: 'Authorized',
        catOptionsFalseLabel: 'Forbidden',
        pwgToken: 'token123',
        adminPageTitle: 'Properties of abums',
        categoryOptionTrue: new CategorySelectOptions(options: [
            1 => 'Holidays',
        ], selected: []),
        categoryOptionFalse: new CategorySelectOptions(options: [
            2 => 'Private',
        ], selected: [2]),
    );

    expect($context->toArray())
        ->toBe([
            'U_HELP' => '/admin/popuphelp.php?page=cat_options',
            'F_ACTION' => '/admin.php?page=cat_options&section=comments',
            'L_SECTION' => 'Authorize users to add comments on selected albums',
            'L_CAT_OPTIONS_TRUE' => 'Authorized',
            'L_CAT_OPTIONS_FALSE' => 'Forbidden',
            'PWG_TOKEN' => 'token123',
            'ADMIN_PAGE_TITLE' => 'Properties of abums',
            'category_option_true' => [
                1 => 'Holidays',
            ],
            'category_option_true_selected' => [],
            'category_option_false' => [
                2 => 'Private',
            ],
            'category_option_false_selected' => [2],
        ]);
});
