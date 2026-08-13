<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed replacement for 4 of the 52 `define()` constants retired from
 * `include/constants.php` (PHPWG_VERSION/PHPWG_DEFAULT_LANGUAGE/
 * PHPWG_DEFAULT_TEMPLATE/REQUIRED_PHP_VERSION) -- verified against the
 * reference's real, stable retirement commit, not invented fresh.
 */
final class AppInfo
{
    // The app/codebase version Piwigo itself tracks (compared against
    // RequestBootstrap::connect()'s own piwigo_installed_version sync,
    // and against each plugin/theme manifest's minPiwigo). Was pinned at
    // '16.3.0' for a while after an earlier '17.0.0' attempt hit a real
    // upgrade.php redirect loop -- re-traced end to end for the P27.9
    // PEM-mirror work: every current AppInfo::VERSION call site was read,
    // and the only real upgrade.php redirect (CoreUpdateService's core
    // self-update wizard) sits behind fetching AppInfo::URL, which is
    // deliberately a non-resolving .invalid domain -- unreachable in
    // practice. The original bug's cause was almost certainly in
    // admin/update machinery since rewritten; bumped back to '17.0.0' for
    // real, live-verified against both a fresh install and an existing DB
    // with a stale piwigo_installed_version.
    public const string VERSION = '17.0.0';

    public const string DEFAULT_LANGUAGE = 'en_UK';

    // Real upstream Piwigo's own PHPWG_DEFAULT_TEMPLATE is 'modus', but
    // this project never actually ships a themes/modus/ directory (only
    // themes/default/, themes/admin/, themes/standard_pages/) -- 'modus'
    // here left every fresh install's themes table permanently empty
    // (InstallService::activateCoreThemes()'s scan never matched it) and
    // seeded CurrentUser::attachGlobals()'s guest placeholder with a theme
    // id that resolves to a nonexistent directory. This
    // project's actual bundled theme really is 'default' (themes/default/
    // themeconf.inc.php's own 'name' key agrees).
    public const string DEFAULT_TEMPLATE = 'default';

    public const string REQUIRED_PHP_VERSION = '8.5.0';

    // Typed replacement for PHPWG_DOMAIN/PHPWG_URL. This fork does not call
    // back to the real piwigo.org -- upstream.example.invalid (.invalid TLD
    // per RFC 2606, guaranteed not to resolve) stops it from sending
    // telemetry or fetching news/updates/merged-extension lists from the
    // upstream server, and makes any accidental outbound call fail fast
    // (DNS failure) rather than hang waiting on a real, possibly
    // rate-limiting host. Unlike PEM_URL (Bootstrap\RequestBootstrap::
    // pemUrl(), which honours CurrentConfig::alternativePemUrl()), DOMAIN
    // and URL have no runtime/Config dependency, so these are real const
    // expressions, not a per-request accessor.
    public const string DOMAIN = 'upstream.example.invalid';

    public const string URL = 'https://' . self::DOMAIN;
}
