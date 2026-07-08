<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Bootstrap for PHPStan's own analysis run (docs/PLAN-REPLAY.md P0 step 5).
// Most legacy constants are define()'d within analyzed files, so PHPStan
// already sees them during the same run. PREFIX_TABLE is the exception:
// upgrade.php/upgrade_feed.php define() it right before `include`-ing
// admin/include/functions_upgrade.php, which PHPStan can't resolve as
// flow-sensitive across that include boundary — stub it so its real,
// always-defined-by-the-time-it's-read value doesn't need tracing.
if (! defined('PREFIX_TABLE')) {
    define('PREFIX_TABLE', '');
}
