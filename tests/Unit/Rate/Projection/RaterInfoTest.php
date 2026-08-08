<?php

declare(strict_types=1);

use Piwigo\Rate\Projection\RaterInfo;

test('constructs with the given id, name, and status', function (): void {
    $rater = new RaterInfo(1, 'fixture_admin', 'webmaster');

    expect($rater->id)->toBe(1)
        ->and($rater->name)->toBe('fixture_admin')
        ->and($rater->status)->toBe('webmaster');
});
