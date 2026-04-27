<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Minimal static service registry — NOT a full DI container.
 *
 * Piwigo has 358 classes and 2300+ free functions; autowiring would dominate the
 * change budget. This is a simple class-string → object map that provides PSR-11
 * compatible get/has without factories or lazy resolution.
 */
final class ServiceLocator
{
    /** @var array<class-string, object> */
    private static array $services = [];

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
        if (!isset(self::$services[$id])) {
            throw new \InvalidArgumentException("Service '{$id}' not registered in ServiceLocator.");
        }
        /** @var T */
        return self::$services[$id];
    }

    public static function has(string $id): bool
    {
        return isset(self::$services[$id]);
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$services = [];
    }
}
