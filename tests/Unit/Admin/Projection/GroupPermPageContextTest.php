<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\GroupPermPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new GroupPermPageContext(
        title: 'Manage permissions for group "Family"',
        catOptionsTrueLabel: 'Authorized',
        catOptionsFalseLabel: 'Forbidden',
        formAction: '/admin.php?page=group_perm&group_id=3',
        pwgToken: 'token123',
    );

    expect($context->toArray())->toBe([
        'TITLE' => 'Manage permissions for group "Family"',
        'L_CAT_OPTIONS_TRUE' => 'Authorized',
        'L_CAT_OPTIONS_FALSE' => 'Forbidden',
        'F_ACTION' => '/admin.php?page=group_perm&group_id=3',
        'PWG_TOKEN' => 'token123',
    ]);
});
