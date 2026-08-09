<?php

declare(strict_types=1);

use Piwigo\Core\FeatureFlag;

// config/feature-flags.php is empty right now -- no real flag exists
// yet. Every check is correctly false until a real entry is added.

test('a flag not present in config/feature-flags.php is disabled', function (): void {
    expect(FeatureFlag::isEnabled('nonexistent_flag'))->toBeFalse();
});

test('an empty flag name is disabled', function (): void {
    expect(FeatureFlag::isEnabled(''))->toBeFalse();
});

test('isEnabled returns true only for a flag whose config value is the exact boolean true, not merely truthy', function (): void {
    // Real gap, found via adversarial mutation testing: no existing test
    // ever exercised a flag config file with a real entry at all (the
    // real config/feature-flags.php is genuinely empty right now -- see
    // this file's own top-of-file comment), so `=== true` (strict) could
    // have silently regressed to a loose `(bool)` cast -- which would
    // also enable a flag set to a truthy non-bool ('yes', 1, '1') -- with
    // every existing test still green.
    $configPath = sys_get_temp_dir() . '/piwigo-feature-flags-real-entries-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($configPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['on_flag' => true, 'off_flag' => false, 'truthy_string_flag' => 'yes', 'truthy_int_flag' => 1];\n");

    try {
        expect(FeatureFlag::isEnabled('on_flag', $configPath))->toBeTrue()
            ->and(FeatureFlag::isEnabled('off_flag', $configPath))->toBeFalse()
            ->and(FeatureFlag::isEnabled('truthy_string_flag', $configPath))->toBeFalse()
            ->and(FeatureFlag::isEnabled('truthy_int_flag', $configPath))->toBeFalse();
    } finally {
        unlink($configPath);
    }
});

test('isEnabled throws when the config file does not return an array', function (): void {
    // $configPath mirrors CliBootstrap::buildApplication()'s own
    // $commandsFile seam -- a disposable fixture under sys_get_temp_dir()
    // instead of ever overwriting the real, shared config/feature-flags.php.
    $configPath = sys_get_temp_dir() . '/piwigo-feature-flags-not-array-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($configPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn 'not-an-array';\n");

    try {
        FeatureFlag::isEnabled('any_flag', $configPath);
    } finally {
        unlink($configPath);
    }
})->throws(RuntimeException::class, 'config/feature-flags.php must return an array of flag => bool.');
