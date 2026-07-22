<?php

declare(strict_types=1);

namespace Piwigo\Storage;

use League\Flysystem\FilesystemOperator;
use Piwigo\Core\CurrentPaths;

/**
 * Named-disk registry. Each entry is a lazy closure that creates the
 * FilesystemOperator for that disk on first access.
 *
 * Named disks: uploads, derivatives, watermarks, themes, plugins, exports,
 *              local, temp.
 *
 * Self-managed singleton (current()/set()/reset()) -- same "no container
 * wiring, lazy-built on first procedural access" pattern as
 * Piwigo\Core\PageState/Piwigo\Lang\Translator/Piwigo\Session\
 * SessionService, not the reference implementation's Kernel::boot()-eager-
 * resolution approach: the Kernel's own DI-container accessor is
 * arch-test-restricted to Bootstrap/ and index.php (services must receive
 * dependencies via constructor injection, never a service locator), so
 * procedural upload code (functions_upload.inc.php etc.) cannot reach it
 * that way.
 */
final class StorageRegistry
{
    private static ?self $instance = null;

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
     * Load factories from config/storage.php (returns an array of closures).
     */
    public static function fromConfig(string $configPath): self
    {
        /** @var array<string, \Closure(): FilesystemOperator> $factories */
        $factories = require $configPath;

        return new self($factories);
    }

    /**
     * Self-managed singleton, lazily built from config/storage.php on
     * first access -- mirrors Translator::get()/PageState::current().
     */
    public static function current(): self
    {
        return self::$instance ??= self::fromConfig(CurrentPaths::get()->root . 'config/storage.php');
    }

    public static function set(self $registry): void
    {
        self::$instance = $registry;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Static shortcut for procedural call sites.
     */
    public static function disk(string $name): FilesystemOperator
    {
        return self::current()->get($name);
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
