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
 * `Template` instance -- Legacy Coupling Retirement Track A, replacing the
 * legacy `global $template;` bridge (singleton/service-locator elimination
 * campaign, Phase 5).
 *
 * `current()` is a memoized `@deprecated` transitional bridge for callers
 * not yet converted to constructor injection -- same "load once, read/write
 * many times per request" reasoning as `Translator`/`EventDispatcher`/
 * `ImageStdParams`/`PageState`/`CurrentUser`: the not-booted fallback is
 * memoized (`self::$fallback ??= new self()`), not fresh-per-call, so a
 * caller that writes via `current()` in one call and reads via `current()`
 * in a later call sees the same instance.
 *
 * Not every `Template` instance in the app goes through this registry --
 * e.g. `MailService`'s email-rendering `Template` instances are genuinely
 * separate, throwaway objects, never "the" current page template. Only
 * `Piwigo\Bootstrap\RequestBootstrap::finalize()` (the original
 * `global $template;` construction site) and the handful of real
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
