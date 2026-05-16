<?php

declare(strict_types=1);

/**
 * Plugin-author pre-publish lint.
 *
 * Walks every .php file under one or more plugin directories and flags
 * forbidden patterns inherited from the legacy procedural runtime —
 * Piwigo 17+ plugins use the typed PluginInterface contract, not the
 * global facade.
 *
 * Usage:
 *   composer piwigo:lint                    # lints every dir under ./plugins/
 *   composer piwigo:lint -- path/to/plugin  # lints a single plugin tree
 *
 * Exit code 0 = clean, 1 = violations found.
 */
$violations = [];
$rules = [
    // (regex, human-readable rule id, why-it-matters)
    ['/\bpwg_query\s*\(/',    'pwg_query', 'Use the injected Doctrine DBAL Connection (Piwigo\\Db\\Connection-via-DI) instead of the legacy facade.'],
    ['/\$GLOBALS\s*\[/',      'globals',   'Read state via injected services (Config, CurrentUser, …) — globals are gone in v17.'],
    ['/\bIMAGES_TABLE\b/',    'images_table', 'Legacy table-name constants are removed; reference table names via the repository layer.'],
    ['/\bCATEGORIES_TABLE\b/', 'categories_table', 'Legacy table-name constants are removed; reference table names via the repository layer.'],
    ['/\bTAGS_TABLE\b/',      'tags_table', 'Legacy table-name constants are removed; reference table names via the repository layer.'],
    ['/\bUSERS_TABLE\b/',     'users_table', 'Legacy table-name constants are removed; reference table names via the repository layer.'],
    ['/\bscript_basename\s*\(/', 'script_basename', 'Legacy request inspection; plugins receive route/request data via the framework.'],
    ['/\bload_language\s*\(/', 'load_language', 'Translations auto-load via PluginRegistry::loadActiveLanguages() — plugin code only calls $lang->t().'],
    ['/\bget_l10n_args\s*\(|\bl10n_args\s*\(|\bl10n\s*\(/', 'global_l10n', 'Use the injected LangService and call $lang->t() instead of the global helpers.'],
    ['/\btrigger_change\s*\(|\btrigger_notify\s*\(/', 'trigger_change', 'Dispatch typed events via PSR-14 (EventDispatcherInterface), not the legacy trigger.'],
    ['/\bset_event_handler\s*\(|\badd_event_handler\s*\(/', 'set_event_handler', 'Subscribe via PluginInterface::subscribedEvents(); the legacy registry is gone.'],
];

$targets = array_slice($argv, 1);
if ($targets === []) {
    $pluginsDir = __DIR__ . '/../plugins';
    if (!is_dir($pluginsDir)) {
        fwrite(STDERR, "plugin-lint: nothing to scan — pass a plugin directory or populate ./plugins/\n");
        exit(0);
    }
    foreach (scandir($pluginsDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $pluginsDir . '/' . $entry;
        if (is_dir($path)) {
            $targets[] = $path;
        }
    }
}

foreach ($targets as $target) {
    if (!is_dir($target)) {
        fwrite(STDERR, "plugin-lint: not a directory: {$target}\n");
        exit(1);
    }
    /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        /** @var \SplFileInfo $fileInfo */
        if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
            continue;
        }
        $path = $fileInfo->getPathname();
        $contents = file_get_contents($path);
        if ($contents === false) {
            continue;
        }
        $lines = explode("\n", $contents);
        foreach ($lines as $lineNo => $line) {
            foreach ($rules as [$regex, $rule, $reason]) {
                if (preg_match($regex, $line) === 1) {
                    $violations[] = [
                        'path'   => $path,
                        'line'   => $lineNo + 1,
                        'rule'   => $rule,
                        'snippet' => trim($line),
                        'reason' => $reason,
                    ];
                }
            }
        }
    }
}

if ($violations === []) {
    echo "plugin-lint: clean.\n";
    exit(0);
}

foreach ($violations as $v) {
    echo "{$v['path']}:{$v['line']}  [{$v['rule']}]\n";
    echo "    {$v['snippet']}\n";
    echo "    {$v['reason']}\n\n";
}
echo 'plugin-lint: ' . count($violations) . " violation(s)\n";
exit(1);
