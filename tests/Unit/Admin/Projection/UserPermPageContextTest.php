<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\UserPermPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new UserPermPageContext(
        title: 'Manage permissions for user "alice"',
        catOptionsTrueLabel: 'Authorized',
        catOptionsFalseLabel: 'Forbidden',
        formAction: '/admin.php?page=user_perm&user_id=3',
        pwgToken: 'token123',
    );

    expect($context->toArray())->toBe([
        'TITLE' => 'Manage permissions for user "alice"',
        'L_CAT_OPTIONS_TRUE' => 'Authorized',
        'L_CAT_OPTIONS_FALSE' => 'Forbidden',
        'F_ACTION' => '/admin.php?page=user_perm&user_id=3',
        'PWG_TOKEN' => 'token123',
    ]);
});
