<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Bootstrap for PHPStan's own analysis run (docs/PLAN-REPLAY.md P0 step 5).
// Most legacy constants are define()'d within analyzed files, so PHPStan
// already sees them during the same run. PREFIX_TABLE is the exception:
// the upgrade.php/upgrade_feed.php entry shells define() it before driving
// Piwigo\Admin\Install\UpgradeService/UpgradeRunner/UpgradeFeedRunner (its
// readers since P23 sub-batch 8f-6), which PHPStan can't resolve as
// flow-sensitive across that file boundary — stub it so its real,
// always-defined-by-the-time-it's-read value doesn't need tracing.
if (! defined('PREFIX_TABLE')) {
    define('PREFIX_TABLE', '');
}

// Same shell-defined-constant situation for CURRENT_DATE (P23 sub-batch
// 8g-6): upgrade.php define()s it (SEC-60 keeps the define out of src/)
// before AbstractRangeVersionUpgrade::markPreRangeNotApplied() reads it.
if (! defined('CURRENT_DATE')) {
    define('CURRENT_DATE', '');
}
