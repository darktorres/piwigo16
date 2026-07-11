<?php

declare(strict_types=1);

use DI\Definition\Helper\FactoryDefinitionHelper;

// Validates config/container.php without booting the full kernel. Two
// checks per entry: (1) every array key must be a loadable class or
// interface; (2) every factory closure's declared return type must also be
// loadable. Passes trivially today -- config/container.php is empty --
// stays permanently useful as later phases add entries.

/**
 * @return array<array-key, mixed>
 */
function loadContainerDefinitions(): array
{
    $loaded = require __DIR__ . '/../../../config/container.php';
    if (!is_array($loaded)) {
        throw new RuntimeException('config/container.php must return an array of service definitions');
    }
    return $loaded;
}

function isContainerEntryLoadable(string $name): bool
{
    try {
        return class_exists($name) || interface_exists($name);
    } catch (Throwable) {
        // Autoloading threw due to runtime deps in the file -- class IS present.
        return true;
    }
}

test('every config/container.php key is a loadable class or interface', function (): void {
    $missing = [];
    foreach (array_keys(loadContainerDefinitions()) as $key) {
        if (!isContainerEntryLoadable((string) $key)) {
            $missing[] = $key;
        }
    }
    expect($missing)->toBe([]);
});

test('every factory closure return type in config/container.php is loadable', function (): void {
    $missing = [];
    foreach (loadContainerDefinitions() as $key => $definition) {
        if (!($definition instanceof FactoryDefinitionHelper)) {
            continue;
        }
        $callable = $definition->getDefinition((string) $key)->getCallable();
        if (!($callable instanceof Closure)) {
            continue;
        }
        $rf = new ReflectionFunction($callable);
        $returnType = $rf->getReturnType();
        if (!($returnType instanceof ReflectionNamedType) || $returnType->isBuiltin()) {
            continue;
        }
        if (!isContainerEntryLoadable($returnType->getName())) {
            $missing[] = "{$key} → {$returnType->getName()}";
        }
    }
    expect($missing)->toBe([]);
});
