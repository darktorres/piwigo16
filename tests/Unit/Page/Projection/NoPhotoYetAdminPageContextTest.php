<?php

declare(strict_types=1);

use Piwigo\Page\Projection\NoPhotoYetAdminPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new NoPhotoYetAdminPageContext(
        step: 2,
        intro: 'Hello admin, your Piwigo photo gallery is empty!',
        nextStepUrl: '/admin.php',
        deactivateUrl: '/?no_photo_yet=deactivate',
    );

    expect($context->toArray())
        ->toBe([
            'step' => 2,
            'intro' => 'Hello admin, your Piwigo photo gallery is empty!',
            'next_step_url' => '/admin.php',
            'deactivate_url' => '/?no_photo_yet=deactivate',
        ]);
});
