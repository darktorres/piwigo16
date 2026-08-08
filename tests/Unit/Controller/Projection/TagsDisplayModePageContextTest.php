<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\TagsDisplayModePageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new TagsDisplayModePageContext(
        cloudUrl: '/tags.php',
        lettersUrl: '/tags.php?display_mode=letters',
        displayMode: 'cloud',
    );

    expect($context->toArray())->toBe([
        'U_CLOUD' => '/tags.php',
        'U_LETTERS' => '/tags.php?display_mode=letters',
        'display_mode' => 'cloud',
    ]);
});
