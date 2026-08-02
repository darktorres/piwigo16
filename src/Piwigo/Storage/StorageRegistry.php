<?php

declare(strict_types=1);

namespace Piwigo\Storage;

use League\Flysystem\FilesystemOperator;

/**
 * Named-disk registry. Each entry is a lazy closure that creates the
 * FilesystemOperator for that disk on first access.
 *
 * Named disks: uploads, derivatives, watermarks, themes, plugins, exports,
 *              local, temp.
 *
 * Singleton/service-locator elimination campaign, Phase 2: the value
 * never actually varies per request (always built from the same
 * config/storage.php), so -- same lesson as the Phase 0 pilot
 * (CurrentPersistentCache) -- there's no "current instance" concept
 * genuinely needed here: this is a plain container-resolved service
 * (`factory(...)` entry in config/container.php calling fromConfig()
 * below), not a self-managed static facade. Most real readers take it via
 * constructor injection; `disk()` remains as a `@deprecated` transitional
 * shim for callers not yet converted (procedural upload code reached from
 * the still-static Ws\Pwg* dispatch layer, Phase 10).
 */
final class StorageRegistry
{
    /**
     * @var array<string, FilesystemOperator>
     */
    private array $resolved = [];

    /**
     * @param array<string, \Closure(): FilesystemOperator> $factories
     */
    public function __construct(
        private readonly array $factories
    ) {}

    /**
     * Load factories from config/storage.php (returns an array of closures)
     * -- the container's own factory(...) entry in config/container.php
     * calls this with CurrentPaths::get()->root . 'config/storage.php'.
     */
    public static function fromConfig(string $configPath): self
    {
        /** @var array<string, \Closure(): FilesystemOperator> $factories */
        $factories = require $configPath;

        return new self($factories);
    }

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection (`Piwigo\Ws\PwgImages` -- Phase 10's
     * still-static dispatch layer) -- delete once
     * `grep -rn "StorageRegistry::disk("` outside tests/ returns nothing.
     * No safe default to gracefully fall back to (a missing storage disk
     * has no sensible substitute), so this throws via
     * `Kernel::container()` itself if `Kernel::boot()` hasn't run, same as
     * every other shim in this campaign backing a value with no sensible
     * default.
     */
    public static function disk(string $name): FilesystemOperator
    {
        $instance = \Piwigo\Core\Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new \LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance->get($name);
    }

    public function get(string $name): FilesystemOperator
    {
        if (! isset($this->resolved[$name])) {
            if (! array_key_exists($name, $this->factories)) {
                $available = implode(', ', array_keys($this->factories));

                throw new \InvalidArgumentException("Unknown storage disk '{$name}'. Available: {$available}.");
            }
            $this->resolved[$name] = ($this->factories[$name])();
        }

        return $this->resolved[$name];
    }

    /**
     * Strip $root from the beginning of $absolutePath to produce a relative
     * Flysystem path. Normalises backslashes and /./ segments so that paths
     * built with Paths::$root . './upload' match correctly.
     */
    public static function stripRoot(string $root, string $absolutePath): string
    {
        $normalize = static fn (string $p): string => rtrim(
            str_replace(['\\', '/./'], ['/', '/'], $p),
            '/',
        );
        $normRoot = $normalize($root) . '/';
        $normPath = $normalize($absolutePath);
        if (str_starts_with($normPath, $normRoot)) {
            return substr($normPath, strlen($normRoot));
        }

        return ltrim($normPath, '/');
    }
}
