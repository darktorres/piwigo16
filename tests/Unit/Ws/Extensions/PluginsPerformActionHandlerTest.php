<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Ws\Extensions\PluginsPerformActionHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Extensions\PluginsPerformActionHandler --
 * `pwg.plugins.performAction` (admin_only). Resolved via
 * `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) -- 16 constructor deps, none of which
 * are touched by the CSRF-mismatch guard under test. No dedicated
 * Integration/Browser spec of its own.
 *
 * Covers only the CSRF-mismatch 403 (its very first check, before the
 * webmaster/config guards) -- avoids ever reaching
 * `ExtensionScanner::scan()`/`ExtensionLifecycle::performAction()` (real
 * filesystem scans + writes), the same wall `UpdatesSubControllerTest.php`
 * already documents.
 */
function pwgPluginsPerformActionHandlerTestSubject(): PluginsPerformActionHandler
{
    $handler = Kernel::container()->get(PluginsPerformActionHandler::class);
    if (! $handler instanceof PluginsPerformActionHandler) {
        throw new LogicException('Container returned an unexpected type for ' . PluginsPerformActionHandler::class);
    }

    return $handler;
}

function pwgPluginsPerformActionHandlerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-extensions-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function pwgPluginsPerformActionHandlerTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? pwgPluginsPerformActionHandlerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $root = pwgPluginsPerformActionHandlerTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        $handler = pwgPluginsPerformActionHandlerTestSubject();
        $result = $handler([
            'action' => 'activate',
            'plugin' => 'my_plugin',
            'pwg_token' => 'wrong-token',
        ]);

        expect($result)
            ->toBeInstanceOf(WsErrorResponse::class);
        if ($result instanceof WsErrorResponse) {
            expect($result->code())
                ->toBe(403);
        }
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pwgPluginsPerformActionHandlerTestRrmdir($root);
    }
});
