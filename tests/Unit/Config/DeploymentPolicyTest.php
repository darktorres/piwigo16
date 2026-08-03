<?php

declare(strict_types=1);

use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

afterEach(function (): void {
    Kernel::reset();
});

test('load() returns all-default values when local/config/config.php does not exist', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-deployment-policy-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);

    $policy = DeploymentPolicy::load(Paths::fromRoot($root));

    expect($policy->showPhpErrors)->toBe(30719)
        ->and($policy->showPhpErrorsOnFrontend)->toBeTrue()
        ->and($policy->apacheAuthentication)->toBeFalse()
        ->and($policy->externalAuthentification)->toBeFalse()
        ->and($policy->allowedHosts)->toBe([]);

    deployment_policy_test_rrmdir($root);
});

test('load() returns the file\'s own values when local/config/config.php exists', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-deployment-policy-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    file_put_contents(
        $root . '/local/config/config.php',
        "<?php\nreturn new \\Piwigo\\Config\\DeploymentPolicy(showPhpErrorsOnFrontend: false, allowedHosts: ['gallery.example.test']);\n"
    );

    $policy = DeploymentPolicy::load(Paths::fromRoot($root));

    expect($policy->showPhpErrorsOnFrontend)->toBeFalse()
        ->and($policy->allowedHosts)->toBe(['gallery.example.test'])
        // Untouched properties keep their own constructor defaults.
        ->and($policy->showPhpErrors)->toBe(30719);

    deployment_policy_test_rrmdir($root);
});

test('load() throws when local/config/config.php does not return a DeploymentPolicy', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-deployment-policy-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    file_put_contents($root . '/local/config/config.php', "<?php\nreturn ['showPhpErrorsOnFrontend' => false];\n");

    expect(static fn () => DeploymentPolicy::load(Paths::fromRoot($root)))
        ->toThrow(LogicException::class);

    deployment_policy_test_rrmdir($root);
});

test('load() throws with the exact message naming the real file path and get_debug_type()', function (): void {
    // The test above only asserts the generic exception class -- the
    // message itself is built from 6 concatenated fragments (the real
    // file path, self::class, and get_debug_type($policy) among them),
    // none of which were previously pinned.
    $root = sys_get_temp_dir() . '/piwigo-deployment-policy-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    $configFile = $root . '/local/config/config.php';
    file_put_contents($configFile, "<?php\nreturn ['showPhpErrorsOnFrontend' => false];\n");

    expect(static fn () => DeploymentPolicy::load(Paths::fromRoot($root)))->toThrow(
        LogicException::class,
        $configFile . ' must `return new ' . DeploymentPolicy::class . '(...)`, got array.'
    );

    deployment_policy_test_rrmdir($root);
});

test('current() memoizes across calls when Kernel is booted (container-shared instance)', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-deployment-policy-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    $first = DeploymentPolicy::current();
    $second = DeploymentPolicy::current();

    expect($second)->toBe($first);

    deployment_policy_test_rrmdir($root);
});

test('current() returns a fresh, unmemoized all-defaults instance on every call when Kernel is not booted', function (): void {
    $first = DeploymentPolicy::current();
    $second = DeploymentPolicy::current();

    expect($second)->not->toBe($first)
        ->and($first->showPhpErrors)->toBe(30719)
        ->and($second->showPhpErrors)->toBe(30719);
});

test('current() falls back to a fresh all-defaults instance when Kernel is booted without a real Paths', function (): void {
    // Unlike CurrentPaths::get() (no sensible default, so it throws), a
    // missing Paths here just means the factory's own Paths autowiring
    // attempt fails -- caught and treated the same as Kernel-not-booted,
    // since an all-defaults DeploymentPolicy is always a safe fallback.
    Kernel::boot();

    try {
        $first = DeploymentPolicy::current();
        $second = DeploymentPolicy::current();

        expect($second)->not->toBe($first)
            ->and($first->showPhpErrors)->toBe(30719);
    } finally {
        Kernel::reset();
    }
});

function deployment_policy_test_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $entries = scandir($dir);
    foreach ($entries !== false ? $entries : [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? deployment_policy_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}
