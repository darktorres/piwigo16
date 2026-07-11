<?php

declare(strict_types=1);

use Piwigo\Core\FeatureFlag;

// config/feature-flags.php is empty right now (no real flag exists yet --
// see the plan's read-only, no-write-path scoping). Every check is
// correctly false until a later phase adds a real entry.

test('a flag not present in config/feature-flags.php is disabled', function (): void {
    expect(FeatureFlag::isEnabled('nonexistent_flag'))->toBeFalse();
});

test('an empty flag name is disabled', function (): void {
    expect(FeatureFlag::isEnabled(''))->toBeFalse();
});
