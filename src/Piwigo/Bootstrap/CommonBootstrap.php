<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Kernel;
use Piwigo\Core\ServerTiming;

/**
 * P7 boot skeleton. Grows into the full boot orchestrator (superglobal
 * sanitization, config/DB/user/plugin/template init) incrementally as P13,
 * P16 and P17-23 land their pieces. index.php calls this once, before all
 * existing legacy request handling runs unchanged.
 *
 * P10 adds Sentry (initialized before anything else so it can capture
 * errors from boot itself -- safe no-op with no DSN configured, see
 * SentryBootstrap's own docblock) + ServerTiming (records boot duration).
 *
 * P13 adds ConfigLoader::applyDefaults()/applyEnvOverrides() -- both pure
 * and non-throwing, seeding Piwigo\Config\Config from SCHEMA + env vars.
 * ConfigLoader::validateRequired() is deliberately NOT called here yet:
 * secret_key (required) has no resolvable source until P14's DB-merge or a
 * PIWIGO_SECRET_KEY env-var convention lands -- calling it today would
 * throw on every real request. See ConfigLoader::validateRequired()'s own
 * docblock.
 */
final class CommonBootstrap
{
    public static function run(): void
    {
        SentryBootstrap::init();

        ServerTiming::start('boot');
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot();
        ServerTiming::stop('boot');
    }
}
