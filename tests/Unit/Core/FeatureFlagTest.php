<?php

declare(strict_types=1);

use Piwigo\Core\FeatureFlag;

// config/feature-flags.php is empty right now (no real flag exists yet --
// see the plan's read-only, no-write-path scoping). Every check is
// correctly false until a later phase adds a real entry.
//
// isEnabled()'s "must return an array" RuntimeException is deliberately
// not exercised: the require path is a hard-coded real production file
// (config/feature-flags.php), not injectable -- the only way to make it
// return something other than an array would be to edit that real file
// to return garbage, which would break the actual application for every
// other reader, not just this one test.

test('a flag not present in config/feature-flags.php is disabled', function (): void {
    expect(FeatureFlag::isEnabled('nonexistent_flag'))->toBeFalse();
});

test('an empty flag name is disabled', function (): void {
    expect(FeatureFlag::isEnabled(''))->toBeFalse();
});
