<?php

// Intentionally legacy-looking. tools/plugin-lint.php scans this body
// statically (pwg_query / IMAGES_TABLE / $GLOBALS / load_language /
// trigger_change) and PluginLintTest asserts every rule fires. The
// class is never instantiated, so the legacy patterns are inert.

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
