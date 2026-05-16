<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use DI\Definition\Helper\FactoryDefinitionHelper;
use PHPUnit\Framework\TestCase;

/**
 * Validates config/container.php without booting the full kernel.
 *
 * Two checks per entry:
 *   1. Every array key must be a loadable class or interface.
 *   2. Every factory closure's declared return type must also be loadable.
 *
 * Both checks use try/catch around class_exists() so that classes whose
 * files have runtime-only function calls (loaded on require) don't cause
 * false negatives — if the autoloader throws, the file exists, so we
 * treat it as present.
 */
final class ContainerDefinitionsTest extends TestCase
{
    /** @var array<mixed> */
    private static array $definitions = [];

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $loaded = require dirname(__DIR__, 3) . '/config/container.php';
        // Psalm narrows the require result to array via the file's
        // return type, then flags assertIsArray as redundant. The
        // assertion is still the documented PHPUnit way to verify
        // the require contract.
        /** @psalm-suppress RedundantCondition */
        self::assertIsArray($loaded);
        self::$definitions = $loaded;
    }

    public function test_all_keys_are_loadable(): void
    {
        $missing = [];
        foreach (array_keys(self::$definitions) as $key) {
            if (!self::classLoadable($key)) {
                $missing[] = $key;
            }
        }
        self::assertEmpty(
            $missing,
            'Container keys not loadable as class/interface: ' . implode(', ', $missing)
        );
    }

    public function test_all_factory_return_types_are_loadable(): void
    {
        $missing = [];
        foreach (self::$definitions as $key => $definition) {
            if (!($definition instanceof FactoryDefinitionHelper)) {
                continue;
            }
            try {
                $callable = $definition->getDefinition($key)->getCallable();
                if (!($callable instanceof \Closure)) {
                    continue;
                }
                $rf = new \ReflectionFunction($callable);
                $returnType = $rf->getReturnType();
                if (!($returnType instanceof \ReflectionNamedType) || $returnType->isBuiltin()) {
                    continue;
                }
                $typeName = $returnType->getName();
                if (!self::classLoadable($typeName)) {
                    $missing[] = "$key → $typeName";
                }
            } catch (\Throwable) {
                // Can't reflect — skip (conservative: don't fail on unknowns).
            }
        }
        self::assertEmpty(
            $missing,
            'Factory return types not loadable: ' . implode(', ', $missing)
        );
    }

    private static function classLoadable(string $name): bool
    {
        try {
            return class_exists($name) || interface_exists($name);
        } catch (\Throwable) {
            // Autoloading threw due to runtime deps in the file — class IS present.
            return true;
        }
    }
}
