<?php

declare(strict_types=1);

use Piwigo\Core\FeatureFlag;

// FeatureFlagDefinitions::all() is empty right now -- no real flag exists
// yet. Every check is correctly false until a real entry is added.

test('a flag not present in FeatureFlagDefinitions::all() is disabled', function (): void {
    expect(FeatureFlag::isEnabled('nonexistent_flag'))->toBeFalse();
});

test('an empty flag name is disabled', function (): void {
    expect(FeatureFlag::isEnabled(''))->toBeFalse();
});

test('isEnabled returns true only for a flag whose config value is the exact boolean true, not merely truthy', function (): void {
    // Real gap, found via adversarial mutation testing: no existing test
    // ever exercised a flag list with a real entry at all (the real
    // FeatureFlagDefinitions::all() is genuinely empty right now -- see
    // this file's own top-of-file comment), so `=== true` (strict) could
    // have silently regressed to a loose `(bool)` cast -- which would
    // also enable a flag set to a truthy non-bool ('yes', 1, '1') -- with
    // every existing test still green.
    $overrideFlags = [
        'on_flag' => true,
        'off_flag' => false,
        'truthy_string_flag' => 'yes',
        'truthy_int_flag' => 1,
    ];

    expect(FeatureFlag::isEnabled('on_flag', $overrideFlags))->toBeTrue()
        ->and(FeatureFlag::isEnabled('off_flag', $overrideFlags))->toBeFalse()
        ->and(FeatureFlag::isEnabled('truthy_string_flag', $overrideFlags))->toBeFalse()
        ->and(FeatureFlag::isEnabled('truthy_int_flag', $overrideFlags))->toBeFalse();
});
