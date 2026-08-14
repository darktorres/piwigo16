<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Ws\Extensions\ThemesPerformActionHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Extensions\ThemesPerformActionHandler --
 * `pwg.themes.performAction` (admin_only). Resolved via
 * `Kernel::container()->get()`, same rationale as
 * PluginsPerformActionHandlerTest.php.
 *
 * Covers only the CSRF-mismatch 403 (its very first check, before the
 * config guard) -- avoids ever reaching `ExtensionScanner::scan()`/
 * `ExtensionLifecycle::performAction()` (real filesystem scans + writes).
 */
function pwgThemesPerformActionHandlerTestSubject(): ThemesPerformActionHandler
{
    $handler = Kernel::container()->get(ThemesPerformActionHandler::class);
    if (! $handler instanceof ThemesPerformActionHandler) {
        throw new LogicException('Container returned an unexpected type for ' . ThemesPerformActionHandler::class);
    }

    return $handler;
}

function pwgThemesPerformActionHandlerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-extensions-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function pwgThemesPerformActionHandlerTestRrmdir(string $dir): void
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
        is_dir($path) ? pwgThemesPerformActionHandlerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $root = pwgThemesPerformActionHandlerTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        $handler = pwgThemesPerformActionHandlerTestSubject();
        $server = Kernel::container()->get(Server::class);
        if (! $server instanceof Server) {
            throw new LogicException('Container returned an unexpected type for ' . Server::class);
        }

        $result = $handler([
            'action' => 'activate',
            'theme' => 'my_theme',
            'pwg_token' => 'wrong-token',
        ], $server);

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
        pwgThemesPerformActionHandlerTestRrmdir($root);
    }
});
