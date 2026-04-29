<?php

/**
 * Remove orphaned 'global $conf;' declarations from files where
 * $conf is no longer referenced (all reads/writes migrated).
 */
declare(strict_types=1);

$targets = [
    __DIR__ . '/../admin',
    __DIR__ . '/../include',
    __DIR__ . '/../src',
];

$exempt = ['config_default.inc.php', 'Config.php', 'wave_b5_migrate.php', 'remove_global_conf.php', 'vendor'];

function is_exempt(string $path): bool
{
    global $exempt;
    foreach ($exempt as $f) {
        if (str_contains($path, $f)) {
            return true;
        }
    }
    return false;
}

function has_bare_conf(string $content): bool
{
    // Any remaining $conf[ reference (not inside Config::)
    // We look for $conf[ not preceded by $GLOBALS['
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        if (str_contains($line, '$conf[') &&
            !str_contains($line, "\$GLOBALS['conf']") &&
            !str_contains($line, '// ') && // skip full-line comments
            !str_contains($line, ' * ')) {  // skip docblock lines
            return true;
        }
    }
    return false;
}

function remove_global_conf_from(string $content): string
{
    // Remove entire line: '    global $conf;' (sole or with only spaces/tabs)
    $content = preg_replace('/^([ \t]*)global\s+\$conf\s*;\s*\r?\n/m', '', $content);
    // Remove $conf from multi-var: 'global $conf, $x;' → 'global $x;'
    $content = preg_replace('/\bglobal\s+\$conf\s*,\s*/', 'global ', $content);
    // Remove $conf as trailing: 'global $x, $conf;' → 'global $x;'
    $content = preg_replace('/,\s*\$conf\b/', '', $content);
    return $content;
}

function collect_php_files(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
    sort($files);
    return $files;
}

$changed = 0;
foreach ($targets as $target) {
    foreach (collect_php_files($target) as $file) {
        if (is_exempt($file)) {
            continue;
        }
        $content = file_get_contents($file);
        if (!str_contains($content, 'global $conf')) {
            continue;
        }
        // Only remove if file has no bare $conf[ remaining
        if (has_bare_conf($content)) {
            continue;
        }
        $new = remove_global_conf_from($content);
        if ($new !== $content) {
            file_put_contents($file, $new);
            echo "[ok]  $file\n";
            $changed++;
        }
    }
}
echo "\nRemoved global \$conf from $changed files.\n";
