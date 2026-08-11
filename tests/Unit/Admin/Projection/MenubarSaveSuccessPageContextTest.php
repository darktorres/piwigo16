<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\MenubarSaveSuccessPageContext;

test('toArray flattens the save success message', function (): void {
    expect(new MenubarSaveSuccessPageContext('Order of menubar items has been updated successfully.')->toArray())
        ->toBe([
            'save_success' => 'Order of menubar items has been updated successfully.',
        ]);
});
