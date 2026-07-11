<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clears what the NEW cache infrastructure owns: the Latte compiled-template
 * cache dir and the CacheFactory-created PSR-6 pool. Deliberately does NOT
 * touch the legacy Smarty _data/templates_c/*.tpl.php compiled files or the
 * legacy _data/cache/*.cache PersistentCache files -- those are owned by
 * admin/maintenance_actions.php's 'compiled-templates' action, which needs
 * the full legacy include/common.inc.php bootstrap (see docs/PLAN-REPLAY.md
 * P12's scope-decision section). Grows to cover them once whichever phase
 * either retires that legacy bootstrap requirement or gives this command a
 * verified-safe way to reach it.
 */
#[AsCommand(name: 'cache:clear', description: 'Purge the Latte compiled-template cache and the PSR-6 cache pool')]
final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $latteDir = dirname(__DIR__, 3) . '/_data/templates_c/latte';
        if (is_dir($latteDir)) {
            $this->removeDir($latteDir);
            $output->writeln("Removed Latte compiled-template cache: {$latteDir}");
        } else {
            $output->writeln('Latte compiled-template cache is already empty.');
        }

        $this->cache->clear();
        $output->writeln('Cleared the PSR-6 cache pool.');

        return Command::SUCCESS;
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
