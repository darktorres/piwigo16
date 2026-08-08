<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationDefaultPageContext;

test('toArray flattens to an empty default array', function (): void {
    expect(new ConfigurationDefaultPageContext()->toArray())->toBe([
        'default' => [],
    ]);
});
