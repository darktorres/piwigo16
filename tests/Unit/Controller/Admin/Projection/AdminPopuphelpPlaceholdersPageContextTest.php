<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\AdminPopuphelpPlaceholdersPageContext;

test('toArray flattens all 5 empty-string placeholders', function (): void {
    expect((new AdminPopuphelpPlaceholdersPageContext())->toArray())->toBe([
        'U_RETURN' => '',
        'USERNAME' => '',
        'U_FAQ' => '',
        'U_CHANGE_THEME' => '',
        'U_LOGOUT' => '',
    ]);
});
