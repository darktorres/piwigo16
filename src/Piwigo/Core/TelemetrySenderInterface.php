<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * `Piwigo\Admin\PiwigoInfosSender::send()` (L4Integration -- it constructs
 * Piwigo\Admin\Extensions\{ExtensionScanner,ExtensionRepository} to
 * cross-reference installed extensions against the PEM directory) is the
 * implementation; its one real caller, `Piwigo\Page\PageTailRenderer`
 * (L3Presentation), may not depend upward on L4 per deptrac's ruleset.
 * Lives in `Piwigo\Core` (L1Infrastructure, same direction as
 * `MailerInterface`/`HtmlRenderingInterface`) so PageTailRenderer can
 * depend downward on this instead. `PiwigoInfosSender implements` it,
 * bound in config/container.php; `Piwigo\Bootstrap\PageTail::render()`
 * passes the concrete instance into PageTailRenderer's constructor.
 */
interface TelemetrySenderInterface
{
    /**
     * Anonymously sends technical data and general statistics (photo
     * counts, plugin list, ...) to piwigo.org, subject to its own
     * config-driven throttling/opt-in checks.
     */
    public function send(): void;
}
