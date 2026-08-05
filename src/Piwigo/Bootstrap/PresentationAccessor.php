<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Html\HtmlService;
use Piwigo\Mail\MailService;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Url\UrlService;

/**
 * DI-migration follow-on to gap-closure Stage 4: typed accessors to
 * container-resolved L3Presentation services, for the many non-Bootstrap
 * callers (static Ws method handlers, Admin page renderers, etc.) that
 * can't call `Kernel::container()` directly (arch-test-restricted to
 * `Bootstrap/` + root `index.php`, `tests/Arch/StructuralTest.php`).
 * Same shape as the established `Bootstrap\RedirectService::userService()`
 * precedent, already consumed cross-namespace today by
 * `Ws\PwgExtensions.php`/`Ws\PwgImages.php` -- `Admin`/`Bootstrap`/
 * `Command`/`Controller`/`Job`/`Ws` are all the same deptrac
 * `L4Integration` layer, so this same-layer dependency is already
 * architecturally allowed, not just tolerated.
 *
 * `HtmlService`/`MailService`/`UrlService` were previously constructed
 * manually (`new HtmlService()` etc.) at ~440 real call sites across the
 * codebase despite being fully autowirable already -- `Connection::class`
 * is registered in `config/container.php`, and every interface these
 * classes implement (`HtmlRenderingInterface`, `MailerInterface`,
 * `UrlServiceInterface`) is already bound there too.
 */
final class PresentationAccessor
{
    public static function htmlService(): HtmlService
    {
        $service = Kernel::container()->get(HtmlService::class);
        if (! $service instanceof HtmlService) {
            throw new LogicException('Container returned an unexpected type for ' . HtmlService::class);
        }
        return $service;
    }

    public static function mailService(): MailService
    {
        $service = Kernel::container()->get(MailService::class);
        if (! $service instanceof MailService) {
            throw new LogicException('Container returned an unexpected type for ' . MailService::class);
        }
        return $service;
    }

    public static function urlService(): UrlService
    {
        $service = Kernel::container()->get(UrlService::class);
        if (! $service instanceof UrlService) {
            throw new LogicException('Container returned an unexpected type for ' . UrlService::class);
        }
        return $service;
    }

    public static function notificationByMailSender(): NotificationByMailSender
    {
        $service = Kernel::container()->get(NotificationByMailSender::class);
        if (! $service instanceof NotificationByMailSender) {
            throw new LogicException('Container returned an unexpected type for ' . NotificationByMailSender::class);
        }
        return $service;
    }

    public static function pictureRateRenderer(): PictureRateRenderer
    {
        $service = Kernel::container()->get(PictureRateRenderer::class);
        if (! $service instanceof PictureRateRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . PictureRateRenderer::class);
        }
        return $service;
    }
}
