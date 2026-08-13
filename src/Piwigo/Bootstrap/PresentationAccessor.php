<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Html\HtmlService;
use Piwigo\Mail\MailService;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Url\UrlService;

/**
 * Typed accessors to container-resolved L3Presentation services, for the
 * many non-Bootstrap callers (static Ws method handlers, Admin page
 * renderers, etc.) that can't call `Kernel::container()` directly
 * (arch-test-restricted to `Bootstrap/` + root `index.php`,
 * `tests/Arch/StructuralTest.php`). Same shape as
 * `Bootstrap\RedirectService::userService()`, consumed cross-namespace by
 * `Ws\Extensions.php`/`Ws\Images.php` -- `Admin`/`Bootstrap`/
 * `Command`/`Controller`/`Job`/`Ws` are all the same deptrac
 * `L4Integration` layer, so this same-layer dependency is architecturally
 * allowed.
 *
 * `HtmlService`/`MailService`/`UrlService` are fully autowirable:
 * `Connection::class` is registered in `config/container.php`, and every
 * interface these classes implement (`HtmlRenderingInterface`,
 * `MailerInterface`, `UrlServiceInterface`) is bound there too.
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

    /**
     * Admin\Extensions\ExtensionLifecycle's own 2 new constructor deps
     * (P27.5) -- both fully autowire (every one of PluginRegistry's/
     * ThemeRegistry's own constructor deps is already bound or a plain
     * autowirable concrete class), so no new config/container.php entry
     * was needed to add these 2 accessors.
     */
    public static function pluginRegistry(): PluginRegistry
    {
        $service = Kernel::container()->get(PluginRegistry::class);
        if (! $service instanceof PluginRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . PluginRegistry::class);
        }
        return $service;
    }

    public static function themeRegistry(): ThemeRegistry
    {
        $service = Kernel::container()->get(ThemeRegistry::class);
        if (! $service instanceof ThemeRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . ThemeRegistry::class);
        }
        return $service;
    }
}
