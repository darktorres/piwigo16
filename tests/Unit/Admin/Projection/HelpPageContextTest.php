<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\HelpPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new HelpPageContext(helpContent: '<p>Help</p>', helpSectionTitle: 'Getting started');

    expect($context->toArray())
        ->toBe([
            'HELP_CONTENT' => '<p>Help</p>',
            'HELP_SECTION_TITLE' => 'Getting started',
        ]);
});
