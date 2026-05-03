<?php

declare(strict_types=1);

/**
 * Generates docs/config-reference.md from Config::SCHEMA. Lists every
 * supported config key with its type, default, accessor method, and any
 * SCHEMA flags (nullable, custom).
 *
 * Run after editing SCHEMA. CI's SchemaIntegrityTest does NOT enforce that
 * the reference is in sync (the reference is documentation, not enforcement);
 * regenerate manually before merging schema changes:
 *
 *   php tools/build-config-reference.php
 *
 * Usage:
 *   php tools/build-config-reference.php          # rewrite docs/config-reference.md
 *   php tools/build-config-reference.php --check  # exit 1 if file would change
 */

require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Config\Config;

$check = in_array('--check', $argv, true);
$path  = __DIR__ . '/../docs/config-reference.md';

$lines = [
    '# Piwigo configuration reference',
    '',
    '> **Generated** by `tools/build-config-reference.php` from `Piwigo\\Config\\Config::SCHEMA`.',
    '> Do not edit by hand — re-run the generator after editing SCHEMA.',
    '',
    'Total keys: **' . count(Config::SCHEMA) . '**.',
    '',
    'Access pattern:',
    '',
    '```php',
    'use Piwigo\\Config\\Config;',
    '',
    '$dir = Config::uploadDir();           // typed accessor — preferred',
    '$mb  = Config::raw(\'blk_\' . $id);   // dynamic key escape hatch',
    '```',
    '',
    'Static keys MUST go through a typed accessor. The private `getString` /',
    '`getInt` / `getBool` helpers throw `UnknownConfigKeyException` if called',
    'with a key not in the table below.',
    '',
    '## Schema',
    '',
    '| Key | Type | Default | Accessor | Notes |',
    '| --- | --- | --- | --- | --- |',
];

$schema = Config::SCHEMA;
ksort($schema);
foreach ($schema as $key => $entry) {
    $type    = $entry['type'];
    $method  = $entry['method'];
    $default = renderDefaultForDocs($entry['default']);
    $notes   = [];
    if (!empty($entry['nullable'])) {
        $notes[] = 'nullable';
    }
    if (!empty($entry['custom'])) {
        $notes[] = 'custom accessor';
    }
    $notesStr = implode(', ', $notes) ?: '—';
    $lines[]  = sprintf('| `%s` | `%s` | `%s` | `Config::%s()` | %s |', $key, $type, $default, $method, $notesStr);
}

$lines[] = '';
$lines[] = '## Environment variable overrides';
$lines[] = '';
$lines[] = 'A small curated subset of keys can be overridden at runtime via env vars,';
$lines[] = 'loaded by `Piwigo\\Config\\ConfigLoader` from `.env` and `.env.local`:';
$lines[] = '';
$lines[] = '| Env var | Schema key |';
$lines[] = '| --- | --- |';
$lines[] = '| `PIWIGO_DB_HOST` | `db_host` |';
$lines[] = '| `PIWIGO_DB_USER` | `db_user` |';
$lines[] = '| `PIWIGO_DB_PASSWORD` | `db_password` |';
$lines[] = '| `PIWIGO_DB_BASE` | `db_base` |';
$lines[] = '';
$lines[] = 'Env values win over `local/config/database.inc.php` (12-factor precedence).';
$lines[] = 'Existing installs that rely solely on `database.inc.php` keep working';
$lines[] = 'unchanged when no `.env` is present.';
$lines[] = '';

$content = implode("\n", $lines);

$existing = is_file($path) ? file_get_contents($path) : '';
if ($existing === $content) {
    if ($check) {
        echo "docs/config-reference.md is in sync with SCHEMA\n";
    }
    exit(0);
}

if ($check) {
    fwrite(STDERR, "docs/config-reference.md is OUT OF SYNC with SCHEMA — re-run tools/build-config-reference.php\n");
    exit(1);
}

file_put_contents($path, $content);
echo 'wrote ' . strlen($content) . " bytes to docs/config-reference.md\n";

function renderDefaultForDocs(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if ($value === true) {
        return 'true';
    }
    if ($value === false) {
        return 'false';
    }
    if (is_string($value)) {
        return $value === '' ? '(empty)' : $value;
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        return (string) $value;
    }
    return var_export($value, true);
}
