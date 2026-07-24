<?php

declare(strict_types=1);

// Regenerates the generated table in docs/CONFIG.md from
// Piwigo\Config\CurrentConfig's own properties, reflectively (Config
// generic-accessor removal retired the former Config::SCHEMA array this
// tool used to read). One row per config-value property, declaration
// order.
//
// Idempotent: re-running produces no diff once CurrentConfig and the
// generated region agree. Run after adding/editing any property:
//
//   php tools/build-config-docs.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\Required;
use Piwigo\Config\Sensitive;

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
    if (is_object($default)) {
        return '`' . $default::class . '`';
    }

    throw new InvalidArgumentException('Unsupported property default type: ' . get_debug_type($default));
}

/**
 * Joins a property's own docblock into a single description line --
 * strips the /** * ... *\/ wrapper, joins wrapped lines with spaces.
 */
function describeProperty(ReflectionProperty $property): string
{
    $doc = $property->getDocComment();
    if ($doc === false) {
        return '';
    }
    $lines = preg_split('/\R/', $doc);
    $words = [];
    foreach ($lines !== false ? $lines : [] as $line) {
        $line = trim($line);
        $line = preg_replace('#^/\*\*|\*/$|^\*#', '', $line);
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '@')) {
            continue;
        }
        $words[] = $line;
    }

    return implode(' ', $words);
}

$rows = ['| Property | Type | Default | Flags | Description |', '| --- | --- | --- | --- | --- |'];
$reflection = new ReflectionClass(CurrentConfig::class);
foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_PRIVATE) as $property) {
    $type = $property->getType();
    $typeName = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
    if ($type !== null && $type->allowsNull() && ! str_starts_with($typeName, '?')) {
        $typeName = '?' . $typeName;
    }

    $default = formatDefault($property->getDefaultValue());

    $flags = [];
    if ($property->getAttributes(Required::class) !== []) {
        $flags[] = 'required';
    }
    if ($property->getAttributes(Sensitive::class) !== []) {
        $flags[] = 'sensitive';
    }

    $rows[] = sprintf(
        '| `%s` | %s | %s | %s | %s |',
        $property->getName(),
        $typeName,
        $default,
        $flags === [] ? '' : implode(', ', $flags),
        str_replace('|', '\|', describeProperty($property))
    );
}

$generatedRegion = $beginMarker . "\n\n" . implode("\n", $rows) . "\n\n" . $endMarker;

$before = substr($source, 0, $beginPos);
$after = substr($source, $endPos + strlen($endMarker));
$newSource = $before . $generatedRegion . $after;

file_put_contents($docPath, $newSource);

fwrite(STDERR, 'Generated ' . (count($rows) - 2) . " config property rows.\n");
