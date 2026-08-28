<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

/**
 * [P58] Regenerates the Campaign A/B finding census.
 *
 * `phpstan.neon`'s CAMPAIGN-PENDING block suppresses every finding both
 * campaigns exist to fix, so a normal `composer analyse:phpstan` reports
 * zero and says nothing about progress. This strips that block into a
 * scratch config and analyses against it, which is the only way to see the
 * work remaining.
 *
 * The scratch config is written *beside* `phpstan.neon` deliberately: its
 * `paths`/`excludePaths` are relative to the config file's own directory,
 * so a config in `/tmp` silently analyses nothing.
 *
 * Usage:
 *   php tools/p58/census.php                 # summary to stdout
 *   php tools/p58/census.php --json=out.json # raw findings for trace.php
 *
 * Run `php bin/piwigo phpstan-latte:compile` first if templates changed --
 * this reads whatever the last compile produced.
 */
$root = dirname(__DIR__, 2);
chdir($root);

/** @var list<string> $argv */
$argv = $_SERVER['argv'];

$jsonOut = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--json=')) {
        $jsonOut = substr($arg, 7);
    }
}

$neon = file_get_contents($root . '/phpstan.neon');
if ($neon === false) {
    fwrite(STDERR, "cannot read phpstan.neon\n");
    exit(1);
}

$start = strpos($neon, '# CAMPAIGN-PENDING');
$end = strpos($neon, 'services:');
if ($start === false || $end === false || $end < $start) {
    fwrite(STDERR, "could not locate the CAMPAIGN-PENDING block; has phpstan.neon been restructured?\n");
    exit(1);
}

// Back up to the start of the comment banner that introduces the block.
$banner = strrpos(substr($neon, 0, $start), "\n        # ---");
$cut = $banner !== false ? $banner + 1 : $start;

$scratch = $root . '/phpstan.p58-census.neon';
file_put_contents($scratch, substr($neon, 0, $cut) . substr($neon, $end));

// The scratch config has to sit beside phpstan.neon, so a crash would leave
// a stray config in the repo root that later runs would pick up.
register_shutdown_function(static function () use ($scratch): void {
    if (is_file($scratch)) {
        unlink($scratch);
    }
});

$cmd = escapeshellarg(PHP_BINARY) . ' vendor/bin/phpstan analyse -c '
    . escapeshellarg($scratch) . ' --no-progress --error-format=json 2>/dev/null';
$raw = shell_exec($cmd);

if (! is_string($raw) || trim($raw) === '') {
    fwrite(STDERR, "phpstan produced no output\n");
    exit(1);
}

$decoded = json_decode($raw, true);
if (! is_array($decoded) || ! isset($decoded['files'])) {
    fwrite(STDERR, "phpstan output was not the expected JSON shape\n");
    exit(1);
}

if ($jsonOut !== null) {
    file_put_contents($jsonOut, $raw);
}

/**
 * Identifiers each campaign's ignore entries cover, mirroring the two
 * groups in phpstan.neon. Kept as literals rather than parsed back out of
 * the neon: this file is what says which campaign a finding belongs to, and
 * a parse would make the two drift silently.
 */
const CAMPAIGN_B = [
    'equal.notAllowed', 'notEqual.notAllowed', 'empty.notAllowed',
    'if.alwaysTrue', 'booleanOr.rightAlwaysFalse', 'identical.alwaysFalse',
];

$byIdentifier = [
    'A' => [],
    'B' => [],
];
$total = 0;
$files = $decoded['files'];
if (! is_array($files)) {
    fwrite(STDERR, "phpstan output had no files map\n");
    exit(1);
}

foreach ($files as $info) {
    $messages = is_array($info) ? ($info['messages'] ?? null) : null;
    if (! is_array($messages)) {
        continue;
    }
    foreach ($messages as $message) {
        $raw = is_array($message) ? ($message['identifier'] ?? null) : null;
        $identifier = is_string($raw) ? $raw : '(none)';
        $campaign = in_array($identifier, CAMPAIGN_B, true) ? 'B' : 'A';
        $byIdentifier[$campaign][$identifier] = ($byIdentifier[$campaign][$identifier] ?? 0) + 1;
        $total++;
    }
}

foreach (['A', 'B'] as $campaign) {
    $sum = array_sum($byIdentifier[$campaign]);
    printf("Campaign %s: %d findings\n", $campaign, $sum);
    arsort($byIdentifier[$campaign]);
    foreach ($byIdentifier[$campaign] as $identifier => $count) {
        printf("  %5d  %s\n", $count, $identifier);
    }
    echo "\n";
}
printf("total: %d\n", $total);
