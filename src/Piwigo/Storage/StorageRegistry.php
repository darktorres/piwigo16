<?php

declare(strict_types=1);

namespace Piwigo\Storage;

use League\Flysystem\FilesystemOperator;
use Piwigo\Core\Paths;

/**
 * Named-disk registry. Each entry is a lazy closure that creates the
 * FilesystemOperator for that disk on first access.
 *
 * Named disks: uploads, derivatives, watermarks, themes, plugins, exports,
 *              local, temp.
 *
 * Boot wiring: the DI factory (config/container.php) calls
 * StorageRegistry::setInstance() so that the static disk() shortcut works
 * from procedural call sites without going through the container.
 */
final class StorageRegistry
{
    private static ?self $instance = null;

    /** @var array<string, FilesystemOperator> */
    private array $resolved = [];

    /** @param array<string, \Closure(): FilesystemOperator> $factories */
    public function __construct(private readonly array $factories)
    {
    }

    /**
     * Load factories from config/storage.php. The file returns a wrapper
     * closure of shape `Closure(Paths): array<string, Closure(): FilesystemOperator>`
     * — we invoke it with the supplied Paths so each disk closure can build
     * its adapter root from the install layout.
     */
    public static function fromConfig(string $configPath, Paths $paths): self
    {
        /** @var \Closure(Paths): array<string, \Closure(): FilesystemOperator> $loader */
        // Config path is a parameter — Psalm cannot follow the require.
        /** @psalm-suppress UnresolvableInclude */
        $loader = require $configPath;
        return new self($loader($paths));
    }

    public static function setInstance(self $registry): void
    {
        self::$instance = $registry;
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    public static function current(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('StorageRegistry not initialised — Kernel::boot() has not run yet.');
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /** Static shortcut for procedural call sites. */
    public static function disk(string $name): FilesystemOperator
    {
        return self::current()->get($name);
    }

    public function get(string $name): FilesystemOperator
    {
        if (!isset($this->resolved[$name])) {
            if (!array_key_exists($name, $this->factories)) {
                $available = implode(', ', array_keys($this->factories));
                throw new \InvalidArgumentException("Unknown storage disk '$name'. Available: $available.");
            }
            $this->resolved[$name] = ($this->factories[$name])();
        }
        return $this->resolved[$name];
    }

    /**
     * Strip $root from the beginning of $absolutePath to produce a relative
     * Flysystem path. Normalises backslashes and /./  segments so that paths
     * built with $paths->root . './upload' match correctly.
     */
    public static function stripRoot(string $root, string $absolutePath): string
    {
        $normalize = static fn (string $p): string => rtrim(
            str_replace(['\\', '/./'], ['/', '/'], $p),
            '/'
        );
        $normRoot = $normalize($root) . '/';
        $normPath = $normalize($absolutePath);
        if (str_starts_with($normPath, $normRoot)) {
            return substr($normPath, strlen($normRoot));
        }
        return ltrim($normPath, '/');
    }
}
