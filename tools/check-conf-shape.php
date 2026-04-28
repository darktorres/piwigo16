<?php

declare(strict_types=1);

/**
 * Drift detector: verifies that every top-level key in the @phpstan-type Conf
 * alias (tools/phpstan-types.php) still exists in config_default.inc.php or
 * is known to come from local/config/database.inc.php (runtime, gitignored).
 *
 * Usage: php tools/check-conf-shape.php
 * Called from the CI lint job.
 *
 * Exit 0 = no drift. Exit 1 = stale keys found in the alias.
 */

$root = dirname(__DIR__);

// Keys that live in local/config/database.inc.php, not config_default.inc.php.
// They are valid alias entries but will never appear in config_default.
const DB_CONFIG_KEYS = ['dblayer', 'db_host', 'db_user', 'db_password', 'db_base'];

// ── Collect keys from config_default.inc.php ─────────────────────────────
$defaultSrc = (string) file_get_contents($root . '/include/config_default.inc.php');
preg_match_all('/\$conf\[\'([^\']+)\'\]\s*=/', $defaultSrc, $defaultMatches);
$defaultKeys = array_unique($defaultMatches[1]);

// ── Extract only the outer @phpstan-type Conf array content ──────────────
// Strip nested array{...} blocks so sub-keys (user_fields, recent_post_dates…)
// are not mistaken for top-level conf keys.
$typesSrc = (string) file_get_contents($root . '/tools/phpstan-types.php');
if (!preg_match('/@phpstan-type Conf array\{(.+?)\}/s', $typesSrc, $typeMatch)) {
    echo "ERROR: Could not parse @phpstan-type Conf alias in tools/phpstan-types.php\n";
    exit(1);
}

// Remove nested array{...} blocks (may nest once) before scanning for keys.
$aliasBody = preg_replace('/array\{[^}]*\}/', 'array{}', $typeMatch[1]);

// Match top-level keys: identifiers followed by : at the start of a type pair.
// Lines look like: "     *     key_name: type," in a docblock.
preg_match_all('/\b([a-z_][a-z0-9_-]*)\s*:/m', (string) $aliasBody, $aliasMatches);

// Filter out PHPStan built-in type keywords that match the pattern.
$typeKeywords = ['string', 'int', 'bool', 'float', 'array', 'list', 'null',
                 'callable', 'max', 'max_dates', 'max_elements', 'max_cats',
                 'rss', 'nbm',
                 // sub-keys of nested array types (user_fields, recent_post_dates…)
                 'id', 'username', 'password', 'email'];
$aliasKeys = array_values(array_unique(array_diff($aliasMatches[1], $typeKeywords)));

// ── Report stale alias keys ───────────────────────────────────────────────
$validKeys = array_merge($defaultKeys, DB_CONFIG_KEYS);
$stale = array_diff($aliasKeys, $validKeys);

if (count($stale) > 0) {
    echo "Stale keys in @phpstan-type Conf alias (not in config_default.inc.php or known db-config keys):\n";
    foreach ($stale as $key) {
        echo "  - $key\n";
    }
    echo "\nRemove these from tools/phpstan-types.php.\n";
    exit(1);
}

echo "Conf shape OK: all " . count($aliasKeys) . " alias keys are valid.\n";
exit(0);
