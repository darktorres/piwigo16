<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ServerTiming;
use Piwigo\Users\CurrentUser;

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
 *
 * P16 adds the `Paths` parameter (minted by index.php via
 * `Paths::fromIndex(__FILE__)`, threaded through to `Kernel::boot()`) and
 * `CurrentUser::attachGlobals()` (guest-user init -- called here, not from
 * Kernel, since `Users` is L2aCoreDomain and Kernel/L1Infrastructure may
 * only depend on L0Data). `PageState::attachGlobals()` is also called
 * here rather than from Kernel: it reference-bridges $GLOBALS['page'] etc,
 * an HTTP-only concept (index.php's own include/common.inc.php has
 * already populated those globals by the time this runs) that has no
 * meaning on the CLI path -- CliBootstrap deliberately never calls it.
 * `Lang::attachGlobals()` follows the identical reasoning for
 * $GLOBALS['lang'] (populated by common.inc.php's load_language() calls).
 */
final class CommonBootstrap
{
    public static function run(Paths $paths): void
    {
        SentryBootstrap::init();

        ServerTiming::start('boot');
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot($paths);
        CurrentUser::attachGlobals();
        PageState::attachGlobals();
        Lang::attachGlobals();
        ServerTiming::stop('boot');
    }
}
