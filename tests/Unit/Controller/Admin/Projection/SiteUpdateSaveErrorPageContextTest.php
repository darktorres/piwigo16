<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\SiteUpdateSaveErrorPageContext;

test('toArray flattens save_error', function (): void {
    $context = new SiteUpdateSaveErrorPageContext(
        saveError: 'Some checksums are missing.',
    );

    expect($context->toArray())
        ->toBe([
            'save_error' => 'Some checksums are missing.',
        ]);
});
