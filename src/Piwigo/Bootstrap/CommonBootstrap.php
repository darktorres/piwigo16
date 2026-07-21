<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
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
 *
 * P23 batch 1 adds the ConfigService::loadConfFromDb() call: common.inc.php
 * already merges DB-persisted config overrides into $GLOBALS['conf'] (via
 * the legacy load_conf_from_db()), but nothing merged them into
 * Config::$data -- ConfigLoader::applyDefaults()/applyEnvOverrides() above
 * only seed schema defaults + env vars. Config::xxx() accessors therefore
 * silently returned stale/default values whenever a setting had been
 * changed from the DB (the CsrfService secret_key bug, fixed directly in
 * P17/P18, was one symptom of this exact gap). Called here, not from
 * common.inc.php itself: this needs Kernel::container() (only available
 * post Kernel::boot()) to resolve ConfigService, and common.inc.php's own
 * body executes before Kernel ever boots on the HTTP path. Only wired for
 * the HTTP path (this class), not CliBootstrap -- CLI commands can run
 * before the `config` table exists (e.g. `migrations:migrate` on a fresh
 * DB), where an unconditional loadConfFromDb() would throw; every real
 * request reaching here has already passed common.inc.php's
 * install-check, so the table is guaranteed to exist.
 *
 * Phase 5 adds CurrentConfigService::set($configService), right after
 * the same resolution -- wires the per-request static registry Tier 2
 * classes (static utilities, or classes constructed throwaway/pre-
 * container so a constructor-injected ConfigService is impossible) read
 * lazily via CurrentConfigService::get() when they need to persist a
 * config write. See CurrentConfigService's own docblock.
 *
 * Legacy Coupling Retirement Phase 8, 8d: RequestBootstrap::configure()
 * now calls Kernel::boot() as its own first statement (Phase 8a), and
 * RequestBootstrap::connect() (which always runs before this method, via
 * the common.inc.php seam) now resolves its own ConfigService and calls
 * CurrentConfigService::set() + loadConfFromDb() already. On the real HTTP
 * path this method's own resolve-and-load below would just repeat that
 * exact work -- CurrentConfigService::isSet() lets it reuse the existing
 * instance instead, so the DB's config table is read once per request,
 * not twice. The full resolve-and-load path stays for the standalone-
 * callable contract tests/Unit/Bootstrap/CommonBootstrapTest.php exercises
 * directly (calling this method with no RequestBootstrap::connect() run
 * first) and for CLI-adjacent callers that reach this method without ever
 * having gone through the legacy $GLOBALS bootstrap.
 *
 * Legacy Coupling Retirement gap-closure: ConfigLoader::applyLocalFileOverrides()
 * added right after applyEnvOverrides(), before Kernel::boot() -- a site's
 * own local/config/config.inc.php had been included into a bare $conf
 * array by common.inc.php this whole time, but nothing ever synced it
 * into Config::$data, so every real request silently ignored any
 * non-DB-persisted site customization. See ConfigLoader::
 * applyLocalFileOverrides()'s own docblock.
 */
final class CommonBootstrap
{
    public static function run(Paths $paths): void
    {
        SentryBootstrap::init();

        ServerTiming::start('boot');
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        ConfigLoader::applyLocalFileOverrides($paths);
        Kernel::boot($paths);
        if (CurrentConfigService::isSet()) {
            $configService = CurrentConfigService::get();
        } else {
            $configService = Kernel::container()->get(ConfigService::class);
            if (! $configService instanceof ConfigService) {
                throw new \LogicException('Container returned an unexpected type for ' . ConfigService::class);
            }
            CurrentConfigService::set($configService);
            $configService->loadConfFromDb();
        }
        CurrentUser::attachGlobals();
        PageState::attachGlobals();
        Lang::attachGlobals();
        ServerTiming::stop('boot');
    }
}
