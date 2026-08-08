<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\Projection\CheckIntegrityPageContext;

test('toArray flattens both submit flags', function (): void {
    expect((new CheckIntegrityPageContext(showSubmitAutomaticCorrection: true, showSubmitIgnore: false))->toArray())
        ->toBe([
            'c13y_show_submit_automatic_correction' => true,
            'c13y_show_submit_ignore' => false,
        ]);
});
