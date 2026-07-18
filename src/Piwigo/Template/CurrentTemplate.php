<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

/**
 * Static accessor for the current request's page-rendering `Template`
 * instance -- Legacy Coupling Retirement Track A, replacing the legacy
 * `global $template;` bridge. Same shape as this codebase's other
 * per-request singleton facades (`Piwigo\Session\SessionService`,
 * `Piwigo\Users\CurrentUser`): a self-managed static instance with
 * `get()`/`set()`/`reset()`, not constructor-injected DI. Deliberately
 * consistent with those rather than a different pattern -- in this
 * request-per-process (pre-worker-mode) execution model a static facade
 * and a per-request DI-injected instance are equally safe, and consistency
 * with the established pattern for "the current request's X" beats
 * introducing a second competing shape for the same problem.
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
    private static ?Template $instance = null;

    public static function get(): Template
    {
        if (! self::$instance instanceof \Piwigo\Template\Template) {
            throw new \LogicException('CurrentTemplate not initialised -- call Piwigo\Bootstrap\RequestBootstrap::finalize() first.');
        }

        return self::$instance;
    }

    public static function set(Template $template): void
    {
        self::$instance = $template;
    }

    public static function isInitialized(): bool
    {
        return self::$instance instanceof \Piwigo\Template\Template;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on SessionService's/CurrentUser's own reset()
     * methods.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
