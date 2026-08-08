<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\SiteUpdateSyncResultPageContext;

test('toArray nests every counter under update_result', function (): void {
    $context = new SiteUpdateSyncResultPageContext(
        newCategories: 1,
        delCategories: 2,
        newElements: 3,
        delElements: 4,
        updElements: 5,
        errors: 6,
    );

    expect($context->toArray())->toBe([
        'update_result' => [
            'NB_NEW_CATEGORIES' => 1,
            'NB_DEL_CATEGORIES' => 2,
            'NB_NEW_ELEMENTS' => 3,
            'NB_DEL_ELEMENTS' => 4,
            'NB_UPD_ELEMENTS' => 5,
            'NB_ERRORS' => 6,
        ],
    ]);
});
