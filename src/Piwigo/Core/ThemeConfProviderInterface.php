<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * `Piwigo\Image\SrcImage` (L2aCoreDomain) is a real caller, and may not
 * depend upward on `Piwigo\Template\Template` (L3Presentation) per
 * deptrac's ruleset. Lives in `Piwigo\Core` (L1Infrastructure, same
 * direction as `HtmlRenderingInterface`/`MailerInterface`) so SrcImage can
 * depend downward on this instead. `Template implements` it;
 * `SrcImage::themeConf()` reads the request's own Template instance via
 * `Piwigo\Template\CurrentTemplate::current()->get()`, not a container
 * binding for this interface itself: Template's constructor takes runtime
 * path/theme strings and is never container-managed, unlike the
 * autowirable implementations behind the other Core interfaces in
 * config/container.php.
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
