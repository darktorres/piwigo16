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
 * below), not a self-managed static facade. Every real reader takes it
 * via constructor injection -- the transitional `disk()` shim (Phase 2)
 * was deleted in Phase 10 once `Piwigo\Ws\PwgImages`, its last remaining
 * caller, converted to real injection.
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
     * Load factories from a plain array-returning PHP file at $configPath
     * (whatever it's given, verbatim -- generic, not project-root-relative
     * itself) -- the container's own factory(...) entry in
     * config/container.php calls this with a fixed
     * dirname(__DIR__) . '/config/storage.php' path, same "value never
     * varies per request" reasoning as Router::class's own routes.php
     * binding. config/storage.php's own disk closures read Paths::class
     * (via CurrentPaths::get()) internally to compute each disk's root, but
     * that's independent of which storage.php file gets loaded here.
     */
    public static function fromConfig(string $configPath): self
    {
        /** @var array<string, \Closure(): FilesystemOperator> $factories */
        $factories = require $configPath;

        return new self($factories);
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
