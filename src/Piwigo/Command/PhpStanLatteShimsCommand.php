<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Latte\Engine;
use Override;
use Piwigo\Core\Paths;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Tools\PhpStan\Latte\ShimClassGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Regenerates `tools/phpstan/Latte/Generated/LatteAnalysisShims.php`
 * (checked in, a build artifact like the composer autoload files) from
 * `PiwigoExtension`'s real filter/function registrations. Run after any
 * `PiwigoExtension` signature change; `composer generate:latte-shims`.
 *
 * `ShimClassGenerator` lives under `autoload-dev`
 * (`Piwigo\Tools\PhpStan\Latte\`) -- dev tooling, not shipped. The
 * class_exists guard keeps `bin/piwigo` usable on a `--no-dev` install,
 * where this command exists in the registry but its generator is
 * genuinely absent (constructor deps are all src classes, so the
 * registry itself always resolves).
 */
#[AsCommand(name: 'phpstan-latte:generate-shims', description: 'Regenerates the PHPStan Latte analysis shim class from PiwigoExtension')]
final class PhpStanLatteShimsCommand extends Command
{
    private const DEFAULT_OUTPUT = 'tools/phpstan/Latte/Generated/LatteAnalysisShims.php';

    public function __construct(
        private readonly PiwigoExtension $piwigoExtension,
        private readonly Paths $paths,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(
            'output',
            InputArgument::OPTIONAL,
            'Output path for the generated class (relative to the project root)',
            self::DEFAULT_OUTPUT,
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! class_exists(ShimClassGenerator::class)) {
            $output->writeln('<error>' . ShimClassGenerator::class . ' is not autoloadable -- dev dependencies (autoload-dev) are required.</error>');

            return Command::FAILURE;
        }

        $outputArg = $input->getArgument('output');
        if (! is_string($outputArg) || $outputArg === '') {
            $output->writeln('<error>Output path must be a non-empty string.</error>');

            return Command::FAILURE;
        }
        $outputPath = str_starts_with($outputArg, '/') ? $outputArg : $this->paths->root . $outputArg;

        // The full real Engine, not PiwigoExtension alone -- compiled
        // templates call Latte's own built-in filters (escape, checkUrl,
        // ...) through the same property-invoke convention, so the merged
        // Engine registration set is the set that needs shims.
        $engine = new Engine();
        $engine->addExtension($this->piwigoExtension);
        $source = (new ShimClassGenerator($engine))->generate();

        $dir = dirname($outputPath);
        if (! is_dir($dir) && ! mkdir($dir, 0o775, true) && ! is_dir($dir)) {
            $output->writeln("<error>Cannot create directory: {$dir}</error>");

            return Command::FAILURE;
        }

        if (file_put_contents($outputPath, $source) === false) {
            $output->writeln("<error>Cannot write: {$outputPath}</error>");

            return Command::FAILURE;
        }

        $output->writeln("Generated: {$outputPath}");

        return Command::SUCCESS;
    }
}
