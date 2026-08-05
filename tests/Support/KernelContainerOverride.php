<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use ReflectionProperty;
use stdClass;
use Piwigo\Core\Container;
use Piwigo\Core\Kernel;

/**
 * Test-only reflection seam for the Bootstrap\*Accessor "Container
 * returned an unexpected type for X" branches (AdminAccessor,
 * CoreDomainAccessor, ExtendedDomainAccessor, PresentationAccessor,
 * InfrastructureAccessor): every one of those accessors' own
 * `\LogicException` guards can only ever fire when config/container.php's
 * real binding for that class resolves to something other than an
 * instance of it -- something that never legitimately happens through
 * Kernel::boot()'s own public API (real container definitions always
 * produce a real instance of the type they're keyed on).
 *
 * This builds a throwaway Container::build() with one class rebound to a
 * plain stdClass (a real PHP-DI ValueDefinition once normalized -- see
 * vendor/php-di/php-di/src/Definition/Source/DefinitionNormalizer.php,
 * no factory() wrapper needed for a plain, non-Closure value) and installs
 * it directly onto Kernel's private static $container/$booted via
 * Reflection. tests/Arch/StructuralTest.php's "Container::build() is only
 * called from src/Piwigo/Core/Kernel.php" rule only greps
 * src/Piwigo + root/bin entry files, not tests/, so calling it here
 * (matching tests/Unit/Core/ContainerTest.php's own established
 * `Container::build([...])` usage) doesn't violate that boundary.
 */
final class KernelContainerOverride
{
    /**
     * @param array<class-string, mixed> $definitions
     */
    public static function with(array $definitions, callable $fn): mixed
    {
        Kernel::reset();
        $container = Container::build($definitions);

        (new ReflectionProperty(Kernel::class, 'container'))->setValue(null, $container);
        (new ReflectionProperty(Kernel::class, 'booted'))->setValue(null, true);

        try {
            return $fn();
        } finally {
            Kernel::reset();
        }
    }

    /**
     * @param class-string $class
     */
    public static function withWrongTypeFor(string $class, callable $fn): mixed
    {
        return self::with([$class => new stdClass()], $fn);
    }
}
