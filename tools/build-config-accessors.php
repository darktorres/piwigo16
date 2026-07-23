<?php

declare(strict_types=1);

// Regenerates the accessor region of src/Piwigo/Config/Config.php from
// Config::SCHEMA (P13). Every non-custom SCHEMA entry gets a mechanical
// public static accessor; 'custom' => true entries are skipped -- their
// bodies are hand-written below the <<<CONFIG-ACCESSORS-END>>> sentinel.
//
// Idempotent: re-running produces no diff once the SCHEMA and the
// generated region agree. Run after adding/editing any non-custom SCHEMA
// entry:
//
//   php tools/build-config-accessors.php
//
// Kept as live tooling (not a one-shot, unlike the reference implementation
// which retired its generator once its SCHEMA stopped growing) -- this
// project's SCHEMA keeps growing through P17-23 domain migrations.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Config\Config;

$configPath = __DIR__ . '/../src/Piwigo/Config/Config.php';
$source = file_get_contents($configPath);
if ($source === false) {
    fwrite(STDERR, "Could not read {$configPath}\n");
    exit(1);
}

$beginMarker = '    // <<<CONFIG-ACCESSORS-BEGIN>>>';
$endMarker = '    // <<<CONFIG-ACCESSORS-END>>>';
$beginPos = strpos($source, $beginMarker);
$endPos = strpos($source, $endMarker);
if ($beginPos === false || $endPos === false || $endPos < $beginPos) {
    fwrite(STDERR, "Could not locate accessor sentinels in {$configPath}\n");
    exit(1);
}

$methods = [];
foreach (Config::SCHEMA as $key => $entry) {
    if (($entry['custom'] ?? false) === true) {
        continue;
    }

    $method = $entry['method'];
    $nullable = ($entry['nullable'] ?? false) === true;
    $keyLiteral = var_export($key, true);

    if ($nullable) {
        // Every nullable non-custom entry in this SCHEMA is type=string --
        // asserted below so a future nullable int/bool/float addition
        // fails loudly instead of silently generating a wrong accessor.
        if ($entry['type'] !== 'string') {
            fwrite(STDERR, "Unsupported nullable non-custom type '{$entry['type']}' for key '{$key}' -- add generator support or mark it 'custom' => true.\n");
            exit(1);
        }
        $methods[] = <<<PHP
    public static function {$method}(): ?string
    {
        \$v = self::src()[{$keyLiteral}] ?? null;
        return \$v !== null ? (is_scalar(\$v) ? (string) \$v : null) : null;
    }
PHP;
        continue;
    }

    $defaultLiteral = var_export($entry['default'], true);
    $getter = match ($entry['type']) {
        'bool' => 'getBool',
        'int' => 'getInt',
        'string' => 'getString',
        default => null,
    };
    if ($getter === null) {
        fwrite(STDERR, "Unsupported non-custom type '{$entry['type']}' for key '{$key}' -- add generator support or mark it 'custom' => true.\n");
        exit(1);
    }

    $returnType = match ($entry['type']) {
        'bool' => 'bool',
        'int' => 'int',
        'string' => 'string',
    };

    $methods[] = <<<PHP
    public static function {$method}(): {$returnType}
    {
        return self::{$getter}({$keyLiteral}, {$defaultLiteral});
    }
PHP;
}

$generatedRegion = $beginMarker . "\n" . implode("\n\n", $methods) . "\n" . $endMarker;

$before = substr($source, 0, $beginPos);
$after = substr($source, $endPos + strlen($endMarker));
$newSource = $before . $generatedRegion . $after;

file_put_contents($configPath, $newSource);

fwrite(STDERR, 'Generated ' . count($methods) . " accessors.\n");
