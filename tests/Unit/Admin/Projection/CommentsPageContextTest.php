<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CommentsPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new CommentsPageContext(
        formAction: '/admin.php?page=comments',
        pwgToken: 'token123',
        adminPageTitle: 'User comments',
    );

    expect($context->toArray())
        ->toBe([
            'F_ACTION' => '/admin.php?page=comments',
            'PWG_TOKEN' => 'token123',
            'ADMIN_PAGE_TITLE' => 'User comments',
        ]);
});
