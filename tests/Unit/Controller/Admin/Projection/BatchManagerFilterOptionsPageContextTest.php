<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\BatchManagerFilterOptionsPageContext;

test('toArray flattens both dimensions and filesize', function (): void {
    $context = new BatchManagerFilterOptionsPageContext(
        dimensions: ['widths' => [600, 1920]],
        filesize: ['min' => 0, 'max' => 5000],
    );

    expect($context->toArray())->toBe([
        'dimensions' => ['widths' => [600, 1920]],
        'filesize' => ['min' => 0, 'max' => 5000],
    ]);
});
