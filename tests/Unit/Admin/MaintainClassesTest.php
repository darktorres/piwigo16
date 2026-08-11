<?php

declare(strict_types=1);

use Piwigo\Admin\PluginMaintain;
use Piwigo\Admin\ThemeMaintain;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\WsContext;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * PluginMaintain/ThemeMaintain -- the no-op default maintenance-hook base
 * classes every real plugin/theme maintain.class.php extends. Their
 * pre-2.7-procedural-fallback Dummy* subclasses (which used to forward to
 * a bare `plugin_xxx()`/`theme_xxx()` global function dynamically defined
 * by a plugin/theme's own maintain.inc.php) were deleted -- plugin/theme
 * compatibility isn't a blocker for this rewrite, so
 * ExtensionLifecycle::buildPluginMaintain()/buildThemeMaintain() now fall
 * back directly to these base classes' own no-op hooks instead.
 */
test('PluginMaintain\'s base install/activate/deactivate/uninstall/update are all no-ops returning null', function (): void {
    KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
        ],
        static function (): void {
            $wsContext = Kernel::container()->get(WsContext::class);
            $accessControl = Kernel::container()->get(AccessControl::class);
            if (! $wsContext instanceof WsContext || ! $accessControl instanceof AccessControl) {
                throw new LogicException('Container returned an unexpected type');
            }

            $errors = [];
            $maintain = new PluginMaintain('some-plugin', $wsContext, $accessControl);

            expect($maintain->install('1.0', $errors))
                ->toBeNull();
            expect($maintain->activate('1.0', $errors))
                ->toBeNull();
            expect($maintain->deactivate())
                ->toBeNull();
            expect($maintain->uninstall())
                ->toBeNull();
            $maintain->update('1.0', '2.0', $errors);
            expect($errors)
                ->toBe([]);
        }
    );
});

test('ThemeMaintain\'s base activate/deactivate/delete are all no-ops returning null', function (): void {
    $errors = [];
    $maintain = new ThemeMaintain('some-theme');

    expect($maintain->activate('1.0', $errors))
        ->toBeNull();
    expect($maintain->deactivate())
        ->toBeNull();
    expect($maintain->delete())
        ->toBeNull();
    expect($errors)
        ->toBe([]);
});
