<?php

declare(strict_types=1);

use Piwigo\Page\Projection\NoPhotoYetGuestPageContext;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new NoPhotoYetGuestPageContext(
        step: 1,
        loginUrl: 'identification.php',
        deactivateUrl: '/?no_photo_yet=browse',
    );

    expect($context->toArray())
        ->toBe([
            'step' => 1,
            'U_LOGIN' => 'identification.php',
            'deactivate_url' => '/?no_photo_yet=browse',
        ]);
});
