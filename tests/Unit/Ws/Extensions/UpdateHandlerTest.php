<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Ws\Extensions\UpdateHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Extensions\UpdateHandler -- `pwg.extensions.update`
 * (admin_only, webmaster-only). Resolved via `Kernel::container()->get()`,
 * same rationale as PluginsPerformActionHandlerTest.php.
 *
 * Covers only the "extensions install disabled" 401 (its very first
 * check, before webmaster/CSRF) -- avoids ever reaching
 * `ExtensionScanner::scan()`/`ExtensionLifecycle::performAction()` (real
 * filesystem scans + writes) or `PemCatalog::extractArchive()` (real PEM
 * network calls).
 */
function pwgUpdateHandlerTestSubject(): UpdateHandler
{
    $handler = Kernel::container()->get(UpdateHandler::class);
    if (! $handler instanceof UpdateHandler) {
        throw new LogicException('Container returned an unexpected type for ' . UpdateHandler::class);
    }

    return $handler;
}

function pwgUpdateHandlerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-extensions-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function pwgUpdateHandlerTestRrmdir(string $dir): void
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
        is_dir($path) ? pwgUpdateHandlerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('returns a 401 WsErrorResponse when extensions install is disabled', function (): void {
    $root = pwgUpdateHandlerTestRoot();

    try {
        CurrentConfigTestFactory::get()->enableExtensionsInstall = false;

        $handler = pwgUpdateHandlerTestSubject();
        $result = $handler([
            'type' => 'plugins',
            'id' => 'my_plugin',
            'revision' => '1',
            'pwg_token' => 'anything',
        ]);

        expect($result)
            ->toBeInstanceOf(WsErrorResponse::class);
        if ($result instanceof WsErrorResponse) {
            expect($result->code())
                ->toBe(401)
                ->and($result->message())
                ->toBe('Piwigo extensions install/update system is disabled');
        }
    } finally {
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pwgUpdateHandlerTestRrmdir($root);
    }
});
