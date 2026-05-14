<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * Static accessor for the active Template singleton.
 *
 * Local-scope Template instances (e.g. mail templates created by MailService)
 * are NOT registered here — they are throwaway objects that intentionally
 * never become the active template.
 */
final class TemplateRegistry
{
    private static ?Template $instance = null;

    public static function set(Template $template): void
    {
        self::$instance = $template;
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    public static function current(): Template
    {
        if (self::$instance === null) {
            throw new \LogicException('TemplateRegistry not initialised — no global Template has been constructed yet.');
        }
        return self::$instance;
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$instance = null;
    }
}
