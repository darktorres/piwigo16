<?php

declare(strict_types=1);

// PHPWG_ROOT_PATH eliminated in Phase 6; production callers receive Paths
// via DI. PHPWG_VERSION migrated to AppInfo::VERSION. PWG_LOCAL_DIR
// retired (Phase C of define()-retirement) — use $paths->local.

// PHPWG_URL is still defined at runtime by CommonBootstrap/Install/Upgrade —
// retained as a static-analysis placeholder.
if (!defined('PHPWG_URL')) {
    define('PHPWG_URL', 'https://piwigo.org');
}
