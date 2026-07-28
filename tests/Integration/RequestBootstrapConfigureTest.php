<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;

/**
 * Piwigo\Bootstrap\RequestBootstrap::configure() -- Phase 1 of the HTTP
 * boot sequence. Never called directly by any existing Unit/Integration
 * test (only reachable, until now, through the Browser suite's real HTTP
 * requests via bootEntryPoint()); safe to call standalone here since,
 * unlike connect()/finalize(), it does no session/plugin-loading/global-
 * handler-installation work -- confirmed by reading its own body -- so it
 * carries none of the "shared PHPUnit process" risk those two would.
 *
 * Covers the 2 red branches:
 *  - the $_SERVER['PATH_INFO'] addslashes() sanitization (every existing
 *    caller of *anything* in this bootstrap chain runs through a request
 *    with no PATH_INFO set at all).
 *  - the "not installed" redirect-to-install.php branch (every existing
 *    caller always points at this project's own already-installed root).
 *
 * configure()'s own several static-setter calls (AccessControl::
 * setHtmlRenderer() etc.) are all idempotent, side-effect-free
 * re-wirings -- already proven harmless by this project's own inline
 * documentation ("harmless no-op if it ever runs in the same process") --
 * so no special teardown is needed for those.
 */
final class RequestBootstrapConfigureTest extends IntegrationTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        unset($_SERVER['PATH_INFO']);
        Kernel::reset();
        parent::tearDown();
    }

    public function test_configure_addslashes_a_non_empty_string_path_info(): void
    {
        $_SERVER['PATH_INFO'] = "O'Brien's/path";

        RequestBootstrap::configure(Paths::fromRoot(dirname(__DIR__, 2)), microtime(true));

        self::assertSame("O\\'Brien\\'s/path", $_SERVER['PATH_INFO']);
        self::assertTrue(Kernel::isBooted());
        // The real project root's own local/.installed.test stamp exists
        // -- proves this reached (and passed through) the install-sentinel
        // check without throwing, not just that PATH_INFO happened to get
        // sanitised before some earlier unrelated failure. Paths::fromRoot()
        // always normalizes to a trailing slash.
        self::assertSame(dirname(__DIR__, 2) . '/', CurrentPaths::get()->root);
    }

    public function test_configure_redirect_response_has_a_302_status_and_installphp_location(): void
    {
        $tempRoot = sys_get_temp_dir() . '/piwigo-configure-test-' . bin2hex(random_bytes(6));
        mkdir($tempRoot . '/local', 0777, true);

        try {
            RequestBootstrap::configure(Paths::fromRoot($tempRoot), microtime(true));
            self::fail('configure() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('install.php', $response->getHeaderLine('Location'));
        } finally {
            $this->removeDirectoryRecursively($tempRoot);
        }
    }

    private function removeDirectoryRecursively(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        self::assertIsArray($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectoryRecursively($path) : unlink($path);
        }
        rmdir($dir);
    }
}
