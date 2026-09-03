<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Controller\Admin\Projection\BatchManagerSearchDebugPageContext;

test('toArray wraps the search debug trace in a single-element list', function (): void {
    expect(new BatchManagerSearchDebugPageContext("line 1\nline 2")->toArray())
        ->toEqual([
            'footer_elements' => [new Html("line 1\nline 2")],
        ]);
});
