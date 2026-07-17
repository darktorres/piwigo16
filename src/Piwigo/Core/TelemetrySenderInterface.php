<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8f-4: the legacy send_piwigo_infos() free function
 * (include/functions.inc.php, now deleted) delegated to
 * `Piwigo\Admin\PiwigoInfosSender::send()` (L4Integration -- it constructs
 * Piwigo\Admin\plugins/themes to cross-reference installed extensions
 * against the PEM directory), but its one real caller,
 * `Piwigo\Page\PageTailRenderer` (L3Presentation), may not depend upward
 * on L4 per deptrac's ruleset. Lives in `Piwigo\Core` (L1Infrastructure,
 * same direction as `MailerInterface`/`HtmlRenderingInterface`) so
 * PageTailRenderer can depend downward on this instead.
 * `PiwigoInfosSender implements` it; bound in config/container.php; the
 * single construction site (Piwigo\Bootstrap\PageTail::render(), the L4
 * orchestrator that replaced the include/page_tail.php seam in P23
 * sub-batch 8f-5) passes the concrete instance into PageTailRenderer's
 * constructor.
 */
interface TelemetrySenderInterface
{
    /**
     * Anonymously sends technical data and general statistics (photo
     * counts, plugin list, ...) to piwigo.org, subject to its own
     * config-driven throttling/opt-in checks.
     *
     * @since 15
     */
    public function send(): void;
}
