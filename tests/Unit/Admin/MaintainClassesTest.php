<?php

declare(strict_types=1);

use Piwigo\Admin\DummyPluginMaintain;
use Piwigo\Admin\DummyThemeMaintain;
use Piwigo\Admin\PluginMaintain;
use Piwigo\Admin\ThemeMaintain;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\WsContext;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * PluginMaintain/ThemeMaintain -- the no-op default maintenance-hook base
 * classes every real plugin/theme maintain.class.php extends -- and their
 * Dummy* pre-2.7-procedural-fallback subclasses. Each Dummy* method's own
 * `is_callable('plugin_xxx'/'theme_xxx')` guard checks for a bare global
 * function a real plugin/theme's own maintain.inc.php would have defined
 * (include_once'd entirely outside this codebase). Most of this suite only
 * covers the false branch (no such function defined, this test process's
 * real state) in-process, since permanently defining one of those global
 * functions here would leak into every other test that shares this
 * process. The true branch (below, 2 subprocess tests) is instead
 * exercised in a fresh, throwaway `php -r` child process -- same technique
 * as ContainerDetectorTest's own open_basedir subprocess test, including
 * that file's own caveat: this project's coverage-merge tooling only
 * ingests this CLI test-runner's own --coverage-php dump and
 * CoverageCollector's real-HTTP-request dumps, neither of which sees a
 * bare CLI subprocess, so a coverage report may still show those lines red
 * despite being genuinely exercised here.
 */
test('PluginMaintain\'s base install/activate/deactivate/uninstall/update are all no-ops returning null', function (): void {
    KernelContainerOverride::with(
        [Paths::class => Paths::fromRoot(sys_get_temp_dir())],
        static function (): void {
            $wsContext = Kernel::container()->get(WsContext::class);
            $accessControl = Kernel::container()->get(AccessControl::class);
            if (! $wsContext instanceof WsContext || ! $accessControl instanceof AccessControl) {
                throw new LogicException('Container returned an unexpected type');
            }

            $errors = [];
            $maintain = new PluginMaintain('some-plugin', $wsContext, $accessControl);

            expect($maintain->install('1.0', $errors))->toBeNull();
            expect($maintain->activate('1.0', $errors))->toBeNull();
            expect($maintain->deactivate())->toBeNull();
            expect($maintain->uninstall())->toBeNull();
            $maintain->update('1.0', '2.0', $errors);
            expect($errors)->toBe([]);
        }
    );
});

test('ThemeMaintain\'s base activate/deactivate/delete are all no-ops returning null', function (): void {
    $errors = [];
    $maintain = new ThemeMaintain('some-theme');

    expect($maintain->activate('1.0', $errors))->toBeNull();
    expect($maintain->deactivate())->toBeNull();
    expect($maintain->delete())->toBeNull();
    expect($errors)->toBe([]);
});

test('DummyPluginMaintain returns null for every hook when no procedural plugin_* function is defined', function (): void {
    KernelContainerOverride::with(
        [Paths::class => Paths::fromRoot(sys_get_temp_dir())],
        static function (): void {
            $wsContext = Kernel::container()->get(WsContext::class);
            $accessControl = Kernel::container()->get(AccessControl::class);
            if (! $wsContext instanceof WsContext || ! $accessControl instanceof AccessControl) {
                throw new LogicException('Container returned an unexpected type');
            }

            $errors = [];
            $maintain = new DummyPluginMaintain('legacy-plugin', $wsContext, $accessControl);

            expect(function_exists('plugin_install'))->toBeFalse();
            expect($maintain->install('1.0', $errors))->toBeNull();
            expect($maintain->activate('1.0', $errors))->toBeNull();
            expect($maintain->deactivate())->toBeNull();
            expect($maintain->uninstall())->toBeNull();
            $maintain->update('1.0', '2.0', $errors);
        }
    );
});

test('DummyThemeMaintain returns null for every hook when no procedural theme_* function is defined', function (): void {
    $maintain = new DummyThemeMaintain('legacy-theme');

    expect(function_exists('theme_activate'))->toBeFalse();
    $errors = [];
    expect($maintain->activate('1.0', $errors))->toBeNull();
    expect($maintain->deactivate())->toBeNull();
    expect($maintain->delete())->toBeNull();
});

test('DummyPluginMaintain forwards to the real plugin_install/plugin_activate/plugin_deactivate/plugin_uninstall functions when they are defined', function (): void {
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();

    $script = <<<'PHP'
    function plugin_install($plugin_id, $plugin_version, &$errors) {
        $errors[] = "install-error:$plugin_id";
        return "install-return:$plugin_id:$plugin_version";
    }
    function plugin_activate($plugin_id, $plugin_version, &$errors) {
        $errors[] = "activate-error:$plugin_id";
        return "activate-return:$plugin_id:$plugin_version";
    }
    function plugin_deactivate($plugin_id) {
        return "deactivate-return:$plugin_id";
    }
    function plugin_uninstall($plugin_id) {
        return "uninstall-return:$plugin_id";
    }

    \Piwigo\Core\Env::loadEnvFile(__REPO_ROOT__);
    \Piwigo\Core\Kernel::boot(\Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));
    $wsContext = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\WsContext::class);
    $accessControl = \Piwigo\Core\Kernel::container()->get(\Piwigo\Auth\AccessControl::class);
    if (! $wsContext instanceof \Piwigo\Core\WsContext || ! $accessControl instanceof \Piwigo\Auth\AccessControl) {
        throw new \LogicException('Container returned an unexpected type');
    }

    $maintain = new \Piwigo\Admin\DummyPluginMaintain('subprocess-plugin', $wsContext, $accessControl);

    $installErrors = [];
    $installResult = $maintain->install('1.0', $installErrors);

    $activateErrors = [];
    $activateResult = $maintain->activate('2.0', $activateErrors);

    $deactivateResult = $maintain->deactivate();
    $uninstallResult = $maintain->uninstall();

    echo json_encode([
        'install' => [$installResult, $installErrors],
        'activate' => [$activateResult, $activateErrors],
        'deactivate' => $deactivateResult,
        'uninstall' => $uninstallResult,
    ]);
    PHP;

    $script = str_replace('__REPO_ROOT__', var_export(dirname($autoloadPath, 2), true), $script);
    $cmd = [PHP_BINARY, '-r', 'require ' . var_export($autoloadPath, true) . ';' . $script];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    expect($exit)->toBe(0, 'DummyPluginMaintain subprocess failed: ' . ($stderr === false ? '(no stderr)' : $stderr));
    expect(json_decode((string) $stdout, true))->toBe([
        'install' => ['install-return:subprocess-plugin:1.0', ['install-error:subprocess-plugin']],
        'activate' => ['activate-return:subprocess-plugin:2.0', ['activate-error:subprocess-plugin']],
        'deactivate' => 'deactivate-return:subprocess-plugin',
        'uninstall' => 'uninstall-return:subprocess-plugin',
    ]);
});

test('DummyThemeMaintain forwards to the real theme_activate/theme_deactivate/theme_delete functions when they are defined', function (): void {
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();

    $script = <<<'PHP'
    function theme_activate($theme_id, $theme_version, &$errors) {
        $errors[] = "activate-error:$theme_id";
        return "activate-return:$theme_id:$theme_version";
    }
    function theme_deactivate($theme_id) {
        return "deactivate-return:$theme_id";
    }
    function theme_delete($theme_id) {
        return "delete-return:$theme_id";
    }

    $maintain = new \Piwigo\Admin\DummyThemeMaintain('subprocess-theme');

    $activateErrors = [];
    $activateResult = $maintain->activate('2.0', $activateErrors);
    $deactivateResult = $maintain->deactivate();
    $deleteResult = $maintain->delete();

    echo json_encode([
        'activate' => [$activateResult, $activateErrors],
        'deactivate' => $deactivateResult,
        'delete' => $deleteResult,
    ]);
    PHP;

    $cmd = [PHP_BINARY, '-r', 'require ' . var_export($autoloadPath, true) . ';' . $script];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    expect($exit)->toBe(0, 'DummyThemeMaintain subprocess failed: ' . ($stderr === false ? '(no stderr)' : $stderr));
    expect(json_decode((string) $stdout, true))->toBe([
        'activate' => ['activate-return:subprocess-theme:2.0', ['activate-error:subprocess-theme']],
        'deactivate' => 'deactivate-return:subprocess-theme',
        'delete' => 'delete-return:subprocess-theme',
    ]);
});
