<?php

declare(strict_types=1);

// Regenerates the generated table in docs/CONFIG.md from Config::SCHEMA
// (P13; docs/PLAN-REPLAY-AUDIT.md finding #10 -- this file was a genuine gap,
// SCHEMA existed but nothing documented it). One row per SCHEMA entry, in
// declaration order.
//
// Idempotent: re-running produces no diff once SCHEMA and the generated
// region agree. Run after adding/editing any SCHEMA entry:
//
//   php tools/build-config-docs.php
//
// Kept as live tooling, not a one-shot, matching build-config-accessors.php's
// own reasoning -- this project's SCHEMA keeps growing through P17-23 domain
// migrations, and a hand-maintained copy would just go stale again.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Config\Config;

$docPath = __DIR__ . '/../docs/CONFIG.md';
$source = file_get_contents($docPath);
if ($source === false) {
    fwrite(STDERR, "Could not read {$docPath}\n");
    exit(1);
}

$beginMarker = '<!-- <<<CONFIG-TABLE-BEGIN>>> -->';
$endMarker = '<!-- <<<CONFIG-TABLE-END>>> -->';
$beginPos = strpos($source, $beginMarker);
$endPos = strpos($source, $endMarker);
if ($beginPos === false || $endPos === false || $endPos < $beginPos) {
    fwrite(STDERR, "Could not locate table sentinels in {$docPath}\n");
    exit(1);
}

function formatDefault(mixed $default): string
{
    if (is_bool($default)) {
        return $default ? 'true' : 'false';
    }
    if ($default === null) {
        return 'null';
    }
    if (is_array($default)) {
        return '`' . json_encode($default, JSON_THROW_ON_ERROR) . '`';
    }
    if (is_string($default)) {
        return $default === '' ? '_(empty string)_' : '`' . str_replace('|', '\|', $default) . '`';
    }
    if (is_int($default) || is_float($default)) {
        return (string) $default;
    }

    throw new InvalidArgumentException('Unsupported SCHEMA default type: ' . get_debug_type($default));
}

$rows = ['| Key | Type | Default | Flags | Description |', '| --- | --- | --- | --- | --- |'];
foreach (Config::SCHEMA as $key => $entry) {
    $type = $entry['type'];
    if (($entry['nullable'] ?? false) === true) {
        $type .= '?';
    }

    $default = ($entry['nullable'] ?? false) === true && ($entry['default'] ?? null) === null
        ? 'null'
        : formatDefault($entry['default'] ?? null);

    $flags = [];
    if (($entry['required'] ?? false) === true) {
        $flags[] = 'required';
    }
    if (($entry['sensitive'] ?? false) === true) {
        $flags[] = 'sensitive';
    }
    if (($entry['custom'] ?? false) === true) {
        $flags[] = 'custom accessor';
    }

    $description = $entry['description'];

    $rows[] = sprintf(
        '| `%s` | %s | %s | %s | %s |',
        $key,
        $type,
        $default,
        $flags === [] ? '' : implode(', ', $flags),
        str_replace('|', '\|', $description)
    );
}

$generatedRegion = $beginMarker . "\n\n" . implode("\n", $rows) . "\n\n" . $endMarker;

$before = substr($source, 0, $beginPos);
$after = substr($source, $endPos + strlen($endMarker));
$newSource = $before . $generatedRegion . $after;

file_put_contents($docPath, $newSource);

fwrite(STDERR, 'Generated ' . (count($rows) - 2) . " config key rows.\n");
