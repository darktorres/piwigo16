<?php

// Intentionally legacy-looking — plugin-lint must reject this file.
// Skipped by PluginRegistry's PSR-4 autoload (lowercase dirname), so
// this code is never executed in tests; it exists only as input to
// tools/plugin-lint.php for PluginLintTest.

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\Plugins\DirtyPlugin;

final class Plugin
{
    public function badMethod(): void
    {
        $rows = pwg_query('SELECT * FROM ' . IMAGES_TABLE);
        $user = $GLOBALS['user'];
        load_language('plugin.lang', dirname(__FILE__));
        trigger_change('some_event', $user);
    }
}
