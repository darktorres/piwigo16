<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Core\Kernel;
use Piwigo\Core\ServerTiming;

/**
 * P7 boot skeleton. Grows into the full boot orchestrator (superglobal
 * sanitization, config/DB/user/plugin/template init) incrementally as P13,
 * P16 and P17-23 land their pieces. index.php calls this once, before all
 * existing legacy request handling runs unchanged.
 *
 * P10 adds the first two real steps: Sentry is initialized before anything
 * else so it can capture errors from boot itself (safe no-op with no DSN
 * configured -- see SentryBootstrap's own docblock); ServerTiming records
 * the one real timing signal available this phase, boot duration.
 */
final class CommonBootstrap
{
    public static function run(): void
    {
        SentryBootstrap::init();

        ServerTiming::start('boot');
        Kernel::boot();
        ServerTiming::stop('boot');
    }
}
