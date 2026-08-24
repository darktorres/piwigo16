<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\CurrentPathsTestFactory;

/**
 * Piwigo\Bootstrap\RequestBootstrap::configure() is the first step of the
 * HTTP boot sequence; unlike connect()/finalize(), it does no
 * session/plugin-loading/global-handler-installation work, so it carries
 * none of the "shared PHPUnit process" risk those two would.
 *
 * Covers the 2 red branches:
 *  - $_SERVER['PATH_INFO'] is left byte-for-byte untouched (SEC-10 --
 *    configure() used to run every superglobal, PATH_INFO included,
 *    through addslashes(); real values ended up backslash-escaped in
 *    the database with no compensating unescape on write, only on read.
 *    This is a regression guard for that fix, not coverage of a live
 *    branch).
 *  - the "not installed" redirect-to-install.php branch (every existing
 *    caller always points at this project's own already-installed root).
 *
 * configure()'s own several static-setter calls (AccessControl::
 * setHtmlRenderer() etc.) are all idempotent, side-effect-free
 * re-wirings, so no special teardown is needed for those.
 */
final class RequestBootstrapConfigureTest extends IntegrationTestCase
{
    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['PATH_INFO']);
        Kernel::reset();
        parent::tearDown();
    }

    public function testConfigureLeavesANonEmptyStringPathInfoUntouched(): void
    {
        $_SERVER['PATH_INFO'] = "O'Brien's/path";

        RequestBootstrap::configure(Paths::fromRoot(dirname(__DIR__, 2)), microtime(true));

        self::assertSame("O'Brien's/path", $_SERVER['PATH_INFO']);
        self::assertTrue(Kernel::isBooted());
        // The real project root's own local/.installed.test stamp exists
        // -- proves this reached (and passed through) the install-sentinel
        // check without throwing, not just that PATH_INFO happened to get
        // left alone before some earlier unrelated failure. Paths::fromRoot()
        // always normalizes to a trailing slash.
        self::assertSame(dirname(__DIR__, 2) . '/', CurrentPathsTestFactory::get()->root);
    }

    public function testConfigureRedirectResponseHasA302StatusAndInstallphpLocation(): void
    {
        // parent::setUp()'s own conditional default boot (real repo root)
        // would otherwise collide with this test's own configure() call
        // below (a *different* root) -- Kernel::boot() throws on a root
        // mismatch rather than silently keeping the stale binding (see its
        // own docblock), so reset back to a genuinely unbooted baseline
        // first.
        Kernel::reset();
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
