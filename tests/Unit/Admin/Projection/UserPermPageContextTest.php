<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\UserPermPageContext;

test('toArray flattens every property, and omits categories_because_of_groups when null', function (): void {
    $context = new UserPermPageContext(
        title: 'Manage permissions for user "alice"',
        catOptionsTrueLabel: 'Authorized',
        catOptionsFalseLabel: 'Forbidden',
        formAction: '/admin.php?page=user_perm&user_id=3',
        pwgToken: 'token123',
        categoriesBecauseOfGroups: null,
    );

    expect($context->toArray())->toBe([
        'TITLE' => 'Manage permissions for user "alice"',
        'L_CAT_OPTIONS_TRUE' => 'Authorized',
        'L_CAT_OPTIONS_FALSE' => 'Forbidden',
        'F_ACTION' => '/admin.php?page=user_perm&user_id=3',
        'PWG_TOKEN' => 'token123',
    ]);
});

test('toArray includes categories_because_of_groups when set', function (): void {
    $context = new UserPermPageContext(
        title: 'Manage permissions for user "alice"',
        catOptionsTrueLabel: 'Authorized',
        catOptionsFalseLabel: 'Forbidden',
        formAction: '/admin.php?page=user_perm&user_id=3',
        pwgToken: 'token123',
        categoriesBecauseOfGroups: ['Home > Holidays'],
    );

    expect($context->toArray()['categories_because_of_groups'])->toBe(['Home > Holidays']);
});
