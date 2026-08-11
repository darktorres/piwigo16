<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\PopuphelpPageContext;

test('toArray flattens the help content', function (): void {
    expect((new PopuphelpPageContext(helpContent: '<p>Help</p>'))->toArray())
        ->toBe([
            'HELP_CONTENT' => '<p>Help</p>',
        ]);
});
