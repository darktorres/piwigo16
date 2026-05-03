<?php

declare(strict_types=1);

/**
 * Generates typed accessor methods on Piwigo\Config\Config from its SCHEMA
 * constant. Replaces text between the
 *
 *   // <<<CONFIG-ACCESSORS-BEGIN>>>
 *   // <<<CONFIG-ACCESSORS-END>>>
 *
 * sentinels in src/Piwigo/Config/Config.php with one accessor per non-custom
 * SCHEMA entry. Custom entries (those with `'custom' => true`) are skipped —
 * their accessors are hand-written below the END sentinel.
 *
 * Run after editing SCHEMA. CI's SchemaIntegrityTest re-runs this in
 * dry-run mode and fails the build if Config.php would change.
 *
 * Usage:
 *   php tools/build-config-accessors.php           # rewrites Config.php in place
 *   php tools/build-config-accessors.php --check   # exits 1 if file would change
 */

require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Config\Config;

$check  = in_array('--check', $argv, true);
$path   = __DIR__ . '/../src/Piwigo/Config/Config.php';
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "cannot read $path\n");
    exit(2);
}

$beginSentinel = '    // <<<CONFIG-ACCESSORS-BEGIN>>>';
$endSentinel   = '    // <<<CONFIG-ACCESSORS-END>>>';
$beginPos      = strpos($source, $beginSentinel);
$endPos        = strpos($source, $endSentinel);
if ($beginPos === false || $endPos === false || $endPos < $beginPos) {
    fwrite(STDERR, "sentinel comments not found in $path\n");
    exit(2);
}

$preamble = substr($source, 0, $beginPos + strlen($beginSentinel));
$suffix   = substr($source, $endPos);

$body = "\n";
foreach (Config::SCHEMA as $key => $entry) {
    if (!empty($entry['custom'])) {
        continue;
    }
    $body .= renderAccessor($key, $entry);
}

$rebuilt = $preamble . $body . $suffix;

if ($rebuilt === $source) {
    if ($check) {
        echo "Config.php is in sync with SCHEMA\n";
    }
    exit(0);
}

if ($check) {
    fwrite(STDERR, "Config.php is OUT OF SYNC with SCHEMA — re-run tools/build-config-accessors.php\n");
    exit(1);
}

file_put_contents($path, $rebuilt);
$diff = strlen($rebuilt) - strlen($source);
echo "Config.php updated (" . ($diff >= 0 ? '+' : '') . "$diff bytes)\n";

/**
 * @param array{type: string, default: mixed, method: string, nullable?: bool, custom?: bool} $entry
 */
function renderAccessor(string $key, array $entry): string
{
    $type     = $entry['type'];
    $method   = $entry['method'];
    $nullable = !empty($entry['nullable']);
    $default  = $entry['default'];

    if ($nullable && $type === 'string') {
        return <<<PHP
    public static function {$method}(): ?string
    {
        \$v = self::src()['{$key}'] ?? null;
        return \$v !== null ? (is_scalar(\$v) ? (string) \$v : null) : null;
    }

PHP;
    }

    $callee = match ($type) {
        'string' => 'getString',
        'int'    => 'getInt',
        'bool'   => 'getBool',
        default  => throw new RuntimeException("non-custom entry '$key' has unsupported type '$type' — mark as 'custom' => true"),
    };

    $defaultRepr = renderDefault($default);

    return <<<PHP
    public static function {$method}(): {$type}
    {
        return self::{$callee}('{$key}', {$defaultRepr});
    }

PHP;
}

function renderDefault(mixed $value): string
{
    if (is_string($value))     { return var_export($value, true); }
    if (is_bool($value))       { return $value ? 'true' : 'false'; }
    if (is_int($value))        { return (string) $value; }
    if (is_float($value))      { return (string) $value; }
    if ($value === null)       { return 'null'; }
    throw new RuntimeException('unsupported default literal: ' . var_export($value, true));
}
