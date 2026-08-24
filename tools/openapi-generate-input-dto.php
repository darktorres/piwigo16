<?php

declare(strict_types=1);

use openapiphp\openapi\Reader;
use openapiphp\openapi\ReferenceContext;
use openapiphp\openapi\spec\MediaType;
use openapiphp\openapi\spec\OpenApi;
use openapiphp\openapi\spec\Operation;
use openapiphp\openapi\spec\RequestBody;
use openapiphp\openapi\spec\Schema;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

/**
 * One-time, throwaway scaffolding for P27's Phase 5 (see docs/PLAN.md and
 * the OpenAPI plan this tool was built against). Reads one operation's
 * request body schema from the finished, hand-reviewed
 * `openapi/openapi.yaml` and emits a `final readonly class ...Input`
 * matching this codebase's own established `*Input` DTO shape exactly
 * (constructor + `fromArray(array $raw): self`, real narrowing per
 * field, a private `xList()` helper per array-typed field) -- the same
 * pattern every one of the 44 hand-written `*Input` DTOs already
 * follows.
 *
 * Built for a flat object schema (string/integer/boolean/number
 * properties, plus arrays of those scalars) -- it has no reason to
 * understand `oneOf`/`anyOf`/nested-object JSON Schema constructs for
 * the one real controller (`ImageFilteredSearchCreateController`) that
 * needs those; that DTO is hand-written instead, same as this tool's
 * own docblock in the plan says.
 *
 * Usage:
 *   php tools/openapi-generate-input-dto.php <operationId> <ClassName>
 *       <Namespace> <outputPath> [--only=field1,field2,...]
 *
 * --only restricts which of the operation's request body properties
 * become part of this DTO -- needed when a real controller only reads
 * some fields directly and hands the rest of the same decoded body to a
 * separate, already-existing shared parser (ImageMissingDerivativesController's
 * own `f*`-prefixed filter fields, parsed via the shared
 * ImageFilterQueryInput::fromQueryParams(), stay out of the generated
 * DTO for exactly this reason -- generating a second, competing parser
 * for the same fields would be a real duplication bug, not a
 * convenience).
 */
function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$rawArgs = $_SERVER['argv'] ?? [];
/**
 * @psalm-suppress RedundantCondition
 * @psalm-suppress TypeDoesNotContainType Psalm's $_SERVER superglobal
 *   stub types 'argv' as always a list<string>, but it's only actually
 *   populated when register_argc_argv is enabled -- this guard is
 *   genuinely needed.
 */
if (! is_array($rawArgs)) {
    fail('$_SERVER[\'argv\'] is not an array -- register_argc_argv must be enabled for CLI SAPI.');
}
$args = [];
foreach (array_slice($rawArgs, 1) as $rawArg) {
    /** @psalm-suppress RedundantCondition same $_SERVER stub-optimism. */
    if (is_string($rawArg)) {
        $args[] = $rawArg;
    }
}

$positional = [];
$onlyFields = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $onlyFields = array_values(array_filter(
            array_map(trim(...), explode(',', substr($arg, strlen('--only=')))),
            static fn (string $field): bool => $field !== '',
        ));
        continue;
    }
    $positional[] = $arg;
}

if (count($positional) !== 4) {
    fail('Usage: php tools/openapi-generate-input-dto.php <operationId> <ClassName> <Namespace> <outputPath> [--only=field1,field2,...]');
}

[$operationId, $className, $namespace, $outputPath] = $positional;

$openapi = Reader::readFromYamlFile(
    __DIR__ . '/../openapi/openapi.yaml',
    OpenApi::class,
    ReferenceContext::RESOLVE_MODE_ALL
);

$operation = null;
foreach ($openapi->paths->getPaths() as $path => $item) {
    foreach ($item->getOperations() as $candidate) {
        if ($candidate->operationId === $operationId) {
            $operation = $candidate;
            break 2;
        }
    }
}
if (! $operation instanceof Operation) {
    fail("No operation with operationId '{$operationId}' found in openapi/openapi.yaml.");
}

$requestBody = $operation->requestBody;
if (! $requestBody instanceof RequestBody) {
    fail("Operation '{$operationId}' has no requestBody.");
}
$mediaType = $requestBody->content['application/json'] ?? null;
if (! $mediaType instanceof MediaType || ! $mediaType->schema instanceof Schema) {
    fail("Operation '{$operationId}' has no application/json request body schema.");
}
$schema = $mediaType->schema;

$rawProperties = $schema->properties;
if ($rawProperties === []) {
    fail("Operation '{$operationId}'s request body schema has no properties.");
}

/** @var array<string, Schema> $properties */
$properties = [];
foreach ($rawProperties as $propertyName => $propertySchema) {
    if (! $propertySchema instanceof Schema) {
        fail("Property '{$propertyName}' on operation '{$operationId}'s request body didn't resolve to a real Schema (RESOLVE_MODE_ALL should have resolved every \$ref already).");
    }
    $properties[$propertyName] = $propertySchema;
}

$fieldNames = $onlyFields ?? array_keys($properties);

/**
 * @return array{phpType: string, nullable: bool, itemPhpType: ?string, default: mixed}
 */
function describeField(Schema $fieldSchema): array
{
    // Schema's own @property annotation declares $type as always
    // string|string[] (never null), but SpecBaseObject::__get()'s real
    // fallback returns null for a schema with no `type:` key at all --
    // isset() (routed through the class's own __isset(), which already
    // accounts for that) is the accurate "is a type declared here" check;
    // a direct `=== null` comparison is the shape PHPStan (correctly,
    // given the class's own inaccurate annotation) flags as unreachable.
    /** @psalm-suppress DocblockTypeContradiction same __isset()/wrong-library-annotation reasoning as above */
    if (! isset($fieldSchema->type)) {
        $types = [];
    } else {
        $rawType = $fieldSchema->type;
        $types = is_array($rawType) ? $rawType : [$rawType];
    }
    $nullable = in_array('null', $types, true);
    /** @var list<string> $nonNullTypes */
    $nonNullTypes = array_values(array_filter($types, static fn (string $t): bool => $t !== 'null'));
    $primary = $nonNullTypes[0] ?? 'mixed';

    $phpType = match ($primary) {
        'integer' => 'int',
        'string' => 'string',
        'boolean' => 'bool',
        'number' => 'float',
        'array' => 'array',
        default => 'mixed',
    };

    $itemPhpType = null;
    if ($primary === 'array' && $fieldSchema->items instanceof Schema) {
        $itemRawType = $fieldSchema->items->type;
        $itemType = is_array($itemRawType) ? ($itemRawType[0] ?? null) : $itemRawType;
        $itemPhpType = match ($itemType) {
            'integer' => 'int',
            'string' => 'string',
            'boolean' => 'bool',
            'number' => 'float',
            default => 'mixed',
        };
    }

    return [
        'phpType' => $phpType,
        'nullable' => $nullable,
        'itemPhpType' => $itemPhpType,
        'default' => $fieldSchema->default,
    ];
}

/**
 * @param array{phpType: string, nullable: bool, itemPhpType: ?string, default: mixed} $field
 */
function propertyType(array $field): string
{
    if ($field['phpType'] === 'array') {
        return 'array';
    }

    return $field['nullable'] ? '?' . $field['phpType'] : $field['phpType'];
}

/**
 * @param array{phpType: string, nullable: bool, itemPhpType: ?string, default: mixed} $field
 */
function narrowingExpr(string $rawVar, array $field, string $fieldName): string
{
    if ($field['phpType'] === 'array') {
        $helper = $fieldName . 'List';

        return "self::{$helper}({$rawVar})";
    }

    $checker = match ($field['phpType']) {
        'int' => 'is_int',
        'string' => 'is_string',
        'bool' => 'is_bool',
        'float' => 'is_float',
        default => 'isset',
    };

    $rawDefault = $field['default'];
    $default = match (true) {
        is_int($rawDefault) && $field['phpType'] === 'int' => (string) $rawDefault,
        is_string($rawDefault) && $field['phpType'] === 'string' => var_export($rawDefault, true),
        is_bool($rawDefault) && $field['phpType'] === 'bool' => $rawDefault === true ? 'true' : 'false',
        $field['nullable'] => 'null',
        $field['phpType'] === 'int' => '0',
        $field['phpType'] === 'string' => "''",
        $field['phpType'] === 'bool' => 'false',
        default => 'null',
    };

    return "{$checker}({$rawVar}) ? {$rawVar} : {$default}";
}

$fields = [];
foreach ($fieldNames as $fieldName) {
    if (! isset($properties[$fieldName])) {
        fail("Field '{$fieldName}' (from --only) is not a property of operation '{$operationId}'s request body.");
    }
    $fields[$fieldName] = describeField($properties[$fieldName]);
}

$constructorLines = [];
$docblockLines = [];
foreach ($fields as $fieldName => $field) {
    $type = propertyType($field);
    if ($field['phpType'] === 'array' && $field['itemPhpType'] !== null) {
        $docblockLines[] = "     * @param list<{$field['itemPhpType']}> \${$fieldName}";
    }
    $constructorLines[] = "        public {$type} \${$fieldName},";
}

$fromArrayReadLines = [];
$fromArrayBuildLines = [];
foreach ($fields as $fieldName => $field) {
    $rawVar = '$' . $fieldName;
    $fromArrayReadLines[] = "        {$rawVar} = \$raw['{$fieldName}'] ?? null;";
    $fromArrayBuildLines[] = "            {$fieldName}: " . narrowingExpr($rawVar, $field, $fieldName) . ',';
}

$listHelpers = [];
foreach ($fields as $fieldName => $field) {
    if ($field['phpType'] !== 'array') {
        continue;
    }
    $itemType = $field['itemPhpType'] ?? 'mixed';
    $helper = $fieldName . 'List';
    $checker = match ($itemType) {
        'int' => 'is_int($v) ? $v : (is_numeric($v) ? (int) $v : null)',
        'string' => 'is_string($v) ? $v : null',
        'bool' => 'is_bool($v) ? $v : null',
        'float' => 'is_float($v) ? $v : (is_numeric($v) ? (float) $v : null)',
        default => '$v',
    };
    $listHelpers[] = <<<PHP

    /**
     * @return list<{$itemType}>
     */
    private static function {$helper}(mixed \$raw): array
    {
        if (! is_array(\$raw)) {
            return [];
        }

        \$values = [];
        foreach (\$raw as \$v) {
            \$narrowed = {$checker};
            if (\$narrowed !== null) {
                \$values[] = \$narrowed;
            }
        }

        return \$values;
    }
PHP;
}

$constructorBlock = implode("\n", $constructorLines);
$docblockBlock = $docblockLines === [] ? '' : "\n    /**\n" . implode("\n", $docblockLines) . "\n     */";
$fromArrayReadBlock = implode("\n", $fromArrayReadLines);
$fromArrayBuildBlock = implode("\n", $fromArrayBuildLines);
$listHelpersBlock = $listHelpers === [] ? '' : "\n" . implode("\n", $listHelpers);

$classBody = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

/**
 * Generated by tools/openapi-generate-input-dto.php from
 * openapi/openapi.yaml's own '{$operationId}' operation -- do not hand-edit
 * without also updating the spec, or re-run the generator instead.
 */
final readonly class {$className}
{{$docblockBlock}
    public function __construct(
{$constructorBlock}
    ) {}

    /**
     * @param array<int|string, mixed> \$raw
     */
    public static function fromArray(array \$raw): self
    {
{$fromArrayReadBlock}

        return new self(
{$fromArrayBuildBlock}
        );
    }{$listHelpersBlock}
}

PHP;

$written = file_put_contents($outputPath, $classBody);
if ($written === false) {
    fail("Could not write {$outputPath}.");
}

fwrite(STDERR, "Generated {$className} ({$namespace}) -> {$outputPath}\n");
