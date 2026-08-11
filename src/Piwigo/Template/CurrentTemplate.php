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

/**
 * Container-shared instance holding the current request's page-rendering
 * `Template` instance. Constructed/resolved via DI only -- every real
 * consumer resolves it via the DI container (from within
 * `Piwigo\Bootstrap`) or via constructor-injected `private readonly
 * CurrentTemplate $currentTemplate`. Tests resolve it via
 * `Piwigo\Tests\Support\CurrentTemplateTestFactory::get()` instead.
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
    private ?Template $template = null;

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
