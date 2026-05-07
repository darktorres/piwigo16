<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * Static accessor for the active Template singleton.
 *
 * Wave A reference bridge: every global-scope `$template = new Template(...)`
 * site (install.php, upgrade.php, common.inc.php, no_photo_yet.inc.php,
 * functions.inc.php Util::get()->redirectHtml()) calls TemplateRegistry::set() so that
 * $GLOBALS['template'] and TemplateRegistry::current() return the same
 * instance. Untouched files and plugins keep working through the global;
 * migrated call sites use the typed accessor.
 *
 * Local-scope Template instances (e.g. mail templates in ServiceLocator::get(MailService::class)->getMailTemplate())
 * are NOT registered here — they are throwaway objects that intentionally
 * never become the active template.
 */
final class TemplateRegistry
{
    private static ?Template $instance = null;

    public static function set(Template $template): void
    {
        self::$instance = $template;
        $GLOBALS['template'] = $template;
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
