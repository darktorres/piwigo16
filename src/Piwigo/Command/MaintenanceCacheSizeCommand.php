<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Admin\Maintenance\CacheSizeCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper for `pwg.getCacheSize` -- reports (and persists, via
 * `CacheSizeCalculator`) the cache/derivative/compiled-template
 * directory sizes real admin pages (Maintenance Actions, Maintenance
 * Environment, the dashboard Intro page) display via
 * `CurrentConfig::cacheSizes`.
 */
#[AsCommand(name: 'maintenance:cache-size', description: 'Calculate and report cache/derivative/compiled-template directory sizes')]
final class MaintenanceCacheSizeCommand extends Command
{
    public function __construct(
        private readonly CacheSizeCalculator $cacheSizeCalculator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->cacheSizeCalculator->calculate();

        $output->writeln('Cache size: ' . self::describe($result['cacheSize']));
        $output->writeln('Compiled templates size: ' . self::describe($result['templatesSize']));
        $output->writeln('Derivative sizes:');
        foreach ($result['msizes'] as $type => $bytes) {
            $output->writeln("  {$type}: " . self::describe($bytes));
        }

        return Command::SUCCESS;
    }

    private static function describe(?int $bytes): string
    {
        return $bytes === null ? 'unknown (exec() unavailable?)' : number_format($bytes / 1024 / 1024, 2) . ' MiB';
    }
}
