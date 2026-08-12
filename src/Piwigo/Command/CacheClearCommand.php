<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clears what the NEW cache infrastructure owns: the Latte compiled-template
 * cache dir and the CacheFactory-created PSR-6 pool. Deliberately does NOT
 * touch FileCombiner's own combined-files cache or the legacy
 * _data/cache/*.cache PersistentCache files -- those are owned by
 * MaintenanceActionDispatcher's 'compiled-templates' case (which also calls
 * CurrentTemplate::get()->deleteCompiledTemplates() -- the SAME Latte cache
 * dir this command clears, not a separate mechanism now that Smarty is
 * gone), reachable from the admin web UI only. Originally deferred here
 * because that whole path needed the legacy include/common.inc.php
 * bootstrap chain -- that constraint is gone (the legacy bootstrap chain
 * doesn't exist anymore, CurrentTemplate::get() is a plain static facade),
 * so this command could cover it too now; nobody has circled back to wire
 * it in.
 *
 * $latteCacheDir is injectable (defaulting to the real, hardcoded path)
 * so tests can point removeDir()'s real recursive delete at an isolated
 * fixture directory instead of ever constructing/tearing down real files
 * under this project's own _data/ -- config/commands.php's DI wiring
 * passes no override, so production still resolves the real path.
 * basenameGuard() is a second, independent layer on top of that: even
 * with the real hardcoded path, a corrupted computation (a bug, or -- as
 * happened for real during mutation testing -- a test deliberately
 * running mutated production code) must not be able to walk this delete
 * up to the project root or beyond.
 */
#[AsCommand(name: 'cache:clear', description: 'Purge the Latte compiled-template cache and the PSR-6 cache pool')]
final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ?string $latteCacheDir = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $latteDir = $this->latteCacheDir ?? self::defaultLatteCacheDir();

        if (is_dir($latteDir)) {
            if (! self::looksLikeLatteCacheDir($latteDir)) {
                throw new RuntimeException("Refusing to clear a path that doesn't look like the Latte compiled-template cache dir (expected .../templates_c/latte): {$latteDir}");
            }

            $this->removeDir($latteDir);
            $output->writeln("Removed Latte compiled-template cache: {$latteDir}");
        } else {
            $output->writeln('Latte compiled-template cache is already empty.');
        }

        $this->cache->clear();
        $output->writeln('Cleared the PSR-6 cache pool.');

        return Command::SUCCESS;
    }

    private static function defaultLatteCacheDir(): string
    {
        return dirname(__DIR__, 3) . '/_data/templates_c/latte';
    }

    /**
     * Only ever called on the outermost $latteDir, never on removeDir()'s
     * own recursive sub-calls -- a real nested subdirectory (e.g.
     * .../latte/2026/07) is legitimately not itself named "latte".
     */
    private static function looksLikeLatteCacheDir(string $dir): bool
    {
        return basename($dir) === 'latte' && basename(dirname($dir)) === 'templates_c';
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
