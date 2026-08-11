<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\BatchManagerSearchDebugPageContext;

test('toArray wraps the search debug trace in a single-element list', function (): void {
    expect(new BatchManagerSearchDebugPageContext("line 1\nline 2")->toArray())
        ->toBe([
            'footer_elements' => ["line 1\nline 2"],
        ]);
});
