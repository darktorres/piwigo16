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
 * MaintenanceActionDispatcher's 'compiled-templates' case
 * (CurrentTemplate::get()->delete_compiled_templates()), reachable from the
 * admin web UI only. Originally deferred here because that whole path
 * needed the legacy include/common.inc.php bootstrap chain -- that
 * constraint is gone (the legacy bootstrap chain doesn't exist anymore,
 * CurrentTemplate::get() is a plain static facade), so this command could
 * cover it too now; nobody has circled back to wire it in.
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
