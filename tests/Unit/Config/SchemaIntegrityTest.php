<?php

declare(strict_types=1);

use Piwigo\Config\Config;

// Guards the SCHEMA <-> generated-accessors contract on Piwigo\Config\Config
// (P13). Ported from the reference implementation's own test with the same
// three invariants -- fully self-contained, no later-phase dependency.

const SCHEMA_INTEGRITY_ALLOW_LIST = [
    // Preamble / framework
    'instance',
    // Bulk readers
    'all', 'dumpForLog', 'defaultsArray',
    // Test helper
    'reset',
    // P16: composed accessors replacing retired define() constants -- no
    // SCHEMA key of their own, just composition over existing accessors
    // (themesDir()/dataLocation()).
    'themesPath', 'combinedDir', 'derivativeDir',
];

test('every SCHEMA entry references an existing method', function (): void {
    foreach (Config::SCHEMA as $key => $entry) {
        expect(method_exists(Config::class, $entry['method']))
            ->toBeTrue("SCHEMA entry '{$key}' references method Config::{$entry['method']}() which does not exist.");
    }
});

test('every zero-arg public static accessor has a SCHEMA entry', function (): void {
    $methodToKey = [];
    foreach (Config::SCHEMA as $key => $entry) {
        $methodToKey[$entry['method']] = $key;
    }

    $reflection = new ReflectionClass(Config::class);
    foreach ($reflection->getMethods(ReflectionMethod::IS_STATIC) as $method) {
        if (! $method->isPublic() || $method->getNumberOfParameters() !== 0) {
            continue;
        }
        $name = $method->getName();
        if (in_array($name, SCHEMA_INTEGRITY_ALLOW_LIST, true)) {
            continue;
        }
        expect(array_key_exists($name, $methodToKey))->toBeTrue(
            "Public static accessor Config::{$name}() has no SCHEMA entry. Either add one, or add '{$name}' to SCHEMA_INTEGRITY_ALLOW_LIST if it's a framework/derived method."
        );
    }
});

test('custom flag matches which side of the accessor sentinel the method lives on', function (): void {
    $reflection = new ReflectionClass(Config::class);
    $sourceLines = file((string) $reflection->getFileName());
    expect($sourceLines)->not->toBeFalse();

    $endSentinelLine = null;
    foreach ($sourceLines as $lineNo => $text) {
        if (str_starts_with(ltrim($text), '// <<<CONFIG-ACCESSORS-END>>>')) {
            $endSentinelLine = $lineNo + 1;
            break;
        }
    }
    expect($endSentinelLine)->not->toBeNull('CONFIG-ACCESSORS-END sentinel not found in Config.php.');

    foreach (Config::SCHEMA as $key => $entry) {
        $method = new ReflectionMethod(Config::class, $entry['method']);
        $isCustom = ($entry['custom'] ?? false) === true;
        $belowSentinel = $method->getStartLine() > $endSentinelLine;

        if ($belowSentinel) {
            expect($isCustom)->toBeTrue(
                "SCHEMA entry '{$key}' resolves to hand-written method Config::{$entry['method']}() (line {$method->getStartLine()}) but lacks 'custom' => true."
            );
        } else {
            expect($isCustom)->toBeFalse(
                "SCHEMA entry '{$key}' has 'custom' => true but Config::{$entry['method']}() (line {$method->getStartLine()}) lives in the generated region."
            );
        }
    }
});
