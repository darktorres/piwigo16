<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use LogicException;
use Piwigo\Core\Kernel;

/**
 * Container-shared instance holding the current request's page-rendering
 * `Template` instance.
 *
 * `current()` is a permanent accessor for two real, ongoing uses: (1) the
 * pre-boot fallback path itself (memoized via
 * `self::$fallback ??= new self()`, same "load once, read/write many
 * times per request" shape `Translator`/`EventDispatcher`/`ImageStdParams`/
 * `PageState`/`CurrentUser` share), and (2) Unit/Integration test setup --
 * ~230 call sites across ~30 test files reach the shared/fallback instance
 * directly through `current()` to seed or assert template state, matching
 * `reset()`'s own "test-only" role below. Neither use carries the
 * worker-mode risk of stale cross-request state, since tests never run
 * inside a FrankenPHP worker serving concurrent real requests.
 *
 * Not every `Template` instance in the app goes through this registry --
 * e.g. `MailService`'s email-rendering `Template` instances are genuinely
 * separate, throwaway objects, never "the" current page template. Only
 * `Piwigo\Bootstrap\RequestBootstrap::finalize()` and the handful of real
 * mid-request reassignment sites (e.g. `Piwigo\Page\NoPhotoYetRenderer`,
 * which swaps in a different theme) call `set()`.
 */
final class CurrentTemplate
{
    private static ?self $fallback = null;

    private ?Template $template = null;

    public static function current(): self
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(self::class);
            if (! $instance instanceof self) {
                throw new LogicException('Container returned an unexpected type for ' . self::class);
            }

            return $instance;
        }

        return self::$fallback ??= new self();
    }

    public function get(): Template
    {
        if (! $this->template instanceof Template) {
            throw new LogicException('CurrentTemplate not initialised -- call Piwigo\Bootstrap\RequestBootstrap::finalize() first.');
        }

        return $this->template;
    }

    public function set(Template $template): void
    {
        $this->template = $template;
    }

    public function isInitialized(): bool
    {
        return $this->template instanceof Template;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on SessionService's/CurrentUser's own reset()
     * methods.
     */
    public function reset(): void
    {
        $this->template = null;
    }
}
