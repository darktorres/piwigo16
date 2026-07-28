<?php

declare(strict_types=1);

use Piwigo\Admin\DummyPluginMaintain;
use Piwigo\Admin\DummyThemeMaintain;
use Piwigo\Admin\PluginMaintain;
use Piwigo\Admin\ThemeMaintain;

/**
 * PluginMaintain/ThemeMaintain -- the no-op default maintenance-hook base
 * classes every real plugin/theme maintain.class.php extends -- and their
 * Dummy* pre-2.7-procedural-fallback subclasses. Each Dummy* method's own
 * `is_callable('plugin_xxx'/'theme_xxx')` guard checks for a bare global
 * function a real plugin/theme's own maintain.inc.php would have defined
 * (include_once'd entirely outside this codebase) -- this suite only
 * covers the false branch (no such function defined, this test process's
 * real state), not the true branch, since permanently defining a global
 * function for one test would leak into every other test in the same
 * process.
 */
test('PluginMaintain\'s base install/activate/deactivate/uninstall/update are all no-ops returning null', function (): void {
    $errors = [];
    $maintain = new PluginMaintain('some-plugin');

    expect($maintain->install('1.0', $errors))->toBeNull();
    expect($maintain->activate('1.0', $errors))->toBeNull();
    expect($maintain->deactivate())->toBeNull();
    expect($maintain->uninstall())->toBeNull();
    $maintain->update('1.0', '2.0', $errors);
    expect($errors)->toBe([]);
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
    $errors = [];
    $maintain = new DummyPluginMaintain('legacy-plugin');

    expect(function_exists('plugin_install'))->toBeFalse();
    expect($maintain->install('1.0', $errors))->toBeNull();
    expect($maintain->activate('1.0', $errors))->toBeNull();
    expect($maintain->deactivate())->toBeNull();
    expect($maintain->uninstall())->toBeNull();
    $maintain->update('1.0', '2.0', $errors);
});

test('DummyThemeMaintain returns null for every hook when no procedural theme_* function is defined', function (): void {
    $maintain = new DummyThemeMaintain('legacy-theme');

    expect(function_exists('theme_activate'))->toBeFalse();
    $errors = [];
    expect($maintain->activate('1.0', $errors))->toBeNull();
    expect($maintain->deactivate())->toBeNull();
    expect($maintain->delete())->toBeNull();
});
