<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8f-4: the legacy get_themeconf() free function
 * (include/functions.inc.php, now deleted) read the request's
 * `$GLOBALS['template']` (Piwigo\Template\Template, L3Presentation) -- but
 * one of its real callers, `Piwigo\Image\SrcImage` (L2aCoreDomain), may not
 * depend upward on L3 per deptrac's ruleset. Lives in `Piwigo\Core`
 * (L1Infrastructure, same direction as `HtmlRenderingInterface`/
 * `MailerInterface`) so SrcImage can depend downward on this instead.
 * `Template implements` it; the request's own Template instance is handed
 * to `SrcImage::setThemeConfProvider()` from include/common.inc.php right
 * after `$template` is constructed (a static setter, not a container
 * binding: Template's constructor takes runtime path/theme strings and is
 * never container-managed, unlike the autowirable implementations behind
 * the other Core interfaces in config/container.php).
 */
interface ThemeConfProviderInterface
{
    /**
     * Returns the corresponding value from the active theme's $themeconf
     * if existing and a string, or an empty string -- the exact contract
     * of the legacy get_themeconf() free function.
     */
    public function themeConf(string $key): string;
}
