<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;

// Guards the property <-> accessor contract on Piwigo\Config\CurrentConfig
// (Config generic-accessor removal) -- replaces the former 3 SCHEMA<->
// generated-accessor invariants (there's no more SCHEMA/generator) with 2
// property<->accessor ones: every config-value property has a same-named
// getter and setter, and every getter/setter genuinely reads/writes its
// own property rather than a computed value -- minus a small allow-list
// for framework methods and the 2 properties with a real, documented
// reason to compute instead of trivially return/assign (see their own
// docblocks: chmodValue's SAPI-dependent fallback, recentPostDates's
// can't-`new`-in-a-property-default lazy build).

const SCHEMA_INTEGRITY_METHOD_ALLOW_LIST = [
    // Bulk readers / test helpers
    'dumpForLog', 'defaultsArray', 'reset',
    // Composed accessors -- no property of their own, just composition
    // over existing accessors (themesDir()/dataLocation()).
    'themesPath', 'combinedDir', 'derivativeDir',
];

const SCHEMA_INTEGRITY_TRIVIAL_BODY_ALLOW_LIST = [
    'chmodValue', 'recentPostDates',
];

/**
 * @return list<ReflectionProperty>
 */
function schemaIntegrityConfigProperties(): array
{
    $reflection = new ReflectionClass(CurrentConfig::class);

    return $reflection->getProperties(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_PRIVATE);
}

function schemaIntegrityMethodBody(ReflectionMethod $method): string
{
    $sourceLines = file((string) $method->getFileName());
    if ($sourceLines === false) {
        throw new RuntimeException('Unable to read ' . $method->getFileName());
    }
    $start = $method->getStartLine();
    $end = $method->getEndLine();
    if ($start === false || $end === false) {
        throw new RuntimeException("Unable to determine source lines for {$method->getName()}().");
    }

    // getStartLine() is the signature line, getStartLine()+1 is the opening
    // `{`, getEndLine() is the closing `}` -- body lines are strictly
    // between the brace lines.
    return trim(implode('', array_slice($sourceLines, $start + 1, max(0, $end - $start - 2))));
}

test('every property has a same-named getter and a setNamed setter', function (): void {
    foreach (schemaIntegrityConfigProperties() as $property) {
        $name = $property->getName();
        expect(method_exists(CurrentConfig::class, $name))
            ->toBeTrue("Property CurrentConfig::\${$name} has no matching CurrentConfig::{$name}() getter.");

        $setter = 'set' . ucfirst($name);
        expect(method_exists(CurrentConfig::class, $setter))
            ->toBeTrue("Property CurrentConfig::\${$name} has no matching CurrentConfig::{$setter}() setter.");
    }
});

test('every public zero-arg static method not backed by a property is on the allow-list', function (): void {
    $propertyNames = array_map(static fn (ReflectionProperty $p): string => $p->getName(), schemaIntegrityConfigProperties());

    $reflection = new ReflectionClass(CurrentConfig::class);
    $unlisted = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_STATIC) as $method) {
        if (! $method->isPublic() || $method->getNumberOfParameters() !== 0) {
            continue;
        }
        $name = $method->getName();
        if (in_array($name, $propertyNames, true) || in_array($name, SCHEMA_INTEGRITY_METHOD_ALLOW_LIST, true)) {
            continue;
        }
        $unlisted[] = $name;
    }

    expect($unlisted)->toBe([], 'Public static zero-arg method(s) have no matching property and aren\'t on SCHEMA_INTEGRITY_METHOD_ALLOW_LIST: ' . implode(', ', $unlisted));
});

test('every getter trivially returns its own property, minus the documented allow-list', function (): void {
    foreach (schemaIntegrityConfigProperties() as $property) {
        $name = $property->getName();
        if (in_array($name, SCHEMA_INTEGRITY_TRIVIAL_BODY_ALLOW_LIST, true)) {
            continue;
        }
        $body = schemaIntegrityMethodBody(new ReflectionMethod(CurrentConfig::class, $name));
        expect($body)->toBe("return self::\${$name};", "CurrentConfig::{$name}() does more than trivially return its own property.");
    }
});

test('every setter trivially assigns its own property, minus the documented allow-list', function (): void {
    foreach (schemaIntegrityConfigProperties() as $property) {
        $name = $property->getName();
        if (in_array($name, SCHEMA_INTEGRITY_TRIVIAL_BODY_ALLOW_LIST, true)) {
            continue;
        }
        $setter = 'set' . ucfirst($name);
        $body = schemaIntegrityMethodBody(new ReflectionMethod(CurrentConfig::class, $setter));
        if ($body === "self::\${$name} = \$value;") {
            continue;
        }
        // The 38 custom-shaped properties' setters validate/normalize
        // $value before assigning -- still real, just not a bare
        // one-liner. Confirm it's a genuine write to the right property,
        // not a silent no-op or a write to the wrong one.
        expect(str_contains($body, "self::\${$name} ="))
            ->toBeTrue("CurrentConfig::{$setter}() doesn't assign CurrentConfig::\${$name}.");
    }
});
