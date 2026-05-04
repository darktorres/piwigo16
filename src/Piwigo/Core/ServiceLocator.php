<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Psr\Container\ContainerInterface;

/**
 * Thin shim over the PHP-DI container.
 *
 * After Kernel::boot() wires the container via setContainer(), every call
 * delegates to PHP-DI. Before boot (e.g. in isolated unit tests), the internal
 * $services array acts as a simple fallback so tests can seed specific entries
 * without booting the full container.
 */
final class ServiceLocator
{
    private static ?ContainerInterface $container = null;

    /** @var array<class-string, object> */
    private static array $services = [];

    public static function setContainer(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     */
    public static function register(string $id, object $service): void
    {
        self::$services[$id] = $service;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public static function get(string $id): object
    {
        if (self::$container !== null) {
            $service = self::$container->get($id);
            if (!is_object($service)) {
                throw new \InvalidArgumentException("Service '{$id}' resolved to a non-object value.");
            }
            /** @var T */
            return $service;
        }

        if (!isset(self::$services[$id])) {
            throw new \InvalidArgumentException("Service '{$id}' not registered in ServiceLocator.");
        }

        /** @var T */
        return self::$services[$id];
    }

    public static function has(string $id): bool
    {
        if (self::$container !== null) {
            return self::$container->has($id);
        }
        return isset(self::$services[$id]);
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$container = null;
        self::$services = [];
    }
}
