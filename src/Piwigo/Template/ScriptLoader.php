<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Registry of scripts for a page. Resolves entry IDs to hashed filenames
 * via the Vite manifest and deduplicates across partials.
 */
final class ScriptLoader
{
    /** @var array<string, Script> */
    private array $registered_scripts = [];

    private bool $done = false;

    public function add(string $id, ?string $path, string|int $version = 0): void
    {
        if ($this->done) {
            throw new \LogicException("Attempt to add script $id but scripts have been written");
        }
        if (($manifest = self::manifest()) !== null) {
            $entry = $manifest[$id] ?? null;
            if (is_array($entry) && is_string($entry['file'] ?? null)) {
                $path = 'dist/' . $entry['file'];
            }
        }
        if (! isset($this->registered_scripts[$id])) {
            $this->registered_scripts[$id] = new Script($id, $path, $version);
        }
    }

    /** @return Combinable[] */
    public function getScripts(): array
    {
        $this->done = true;

        return array_values($this->registered_scripts);
    }

    /** @var array<mixed, mixed>|false|null */
    private static array|false|null $manifest = null;

    /**
     * @return array<mixed, mixed>|null
     */
    public static function getManifest(): ?array
    {
        return self::manifest();
    }

    /** @return array<mixed, mixed>|null */
    private static function manifest(): ?array
    {
        if (self::$manifest !== null) {
            return self::$manifest !== false ? self::$manifest : null;
        }
        $f = Kernel::service(Paths::class)->root . 'dist/manifest.json';
        if (!is_file($f)) {
            self::$manifest = false;
            return null;
        }
        $decoded = json_decode((string) file_get_contents($f), true);
        self::$manifest = is_array($decoded) ? $decoded : false;
        return self::$manifest !== false ? self::$manifest : null;
    }
}
