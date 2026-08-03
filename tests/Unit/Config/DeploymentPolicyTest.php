<?php

declare(strict_types=1);

use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

beforeEach(function (): void {
    DeploymentPolicy::reset();
});

afterEach(function (): void {
    DeploymentPolicy::reset();
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

test('current() memoizes across calls until reset()', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-deployment-policy-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/local/config', 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    $first = DeploymentPolicy::current();
    $second = DeploymentPolicy::current();

    expect($second)->toBe($first);

    DeploymentPolicy::reset();

    expect(DeploymentPolicy::current())->not->toBe($first);

    deployment_policy_test_rrmdir($root);
});

test('set() lets a test inject a specific policy', function (): void {
    $policy = new DeploymentPolicy(apacheAuthentication: true);

    DeploymentPolicy::set($policy);

    expect(DeploymentPolicy::current())->toBe($policy);
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
