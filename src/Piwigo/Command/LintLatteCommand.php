<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Latte\Engine;
use Latte\Tools\Linter;
use Latte\Tools\LinterExtension;
use Override;
use Piwigo\Template\Latte\PiwigoExtension;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Inner half of `composer lint:latte` (`tools/latte-lint.php`, the real
 * entry point -- run that or `composer lint:latte`, not this directly).
 * `hidden: true` keeps it out of `bin/piwigo list`.
 *
 * Two-process design: `Latte\Tools\Linter` is `final` and writes
 * `[WARNING]`/`[DEPRECATED]` markers only to STDERR via a private
 * per-file `set_error_handler()` -- nothing in this class can hook into
 * that from inside the same process to turn it into a real failure, so
 * `tools/latte-lint.php` runs this command as a subprocess and inspects
 * its captured STDERR instead.
 *
 * `PiwigoExtension` is constructor-injected directly (resolves cleanly
 * via the container's own autowiring, confirmed live) rather than its 5
 * individual dependencies -- registering it makes the bundled `Linter`
 * recognize Piwigo's own filters/functions (`|translate`, `|noescape`,
 * `combineScript`, ...) instead of reporting them as unknown. No
 * `Feature::StrictTypes` -- production's own `Template::latteEngine()`
 * doesn't set it either, and this must match production's real wiring,
 * not the reference project's.
 */
#[AsCommand(name: 'lint:latte:inner', description: 'Internal: lints .latte templates via Latte\'s bundled Linter -- run `composer lint:latte` instead', hidden: true)]
final class LintLatteCommand extends Command
{
    public function __construct(
        private readonly PiwigoExtension $piwigoExtension,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(
            'paths',
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'Directories/files to scan',
            ['themes'],
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<mixed> $paths */
        $paths = $input->getArgument('paths');

        $engine = new Engine();
        $engine->addExtension($this->piwigoExtension);
        // Bundled validator: warns on unknown filters/functions/classes/
        // methods. Required because a custom engine is passed in --
        // Linter::createEngine() (the bundled engine builder, which adds
        // LinterExtension by default) is bypassed when the constructor
        // receives one.
        $engine->addExtension(new LinterExtension());

        $linter = new Linter(engine: $engine, strict: true);

        $ok = true;
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }
            if (! is_dir($path) && ! is_file($path)) {
                $output->writeln("Skip: {$path} (not found)");

                continue;
            }
            $ok = $linter->scanDirectory($path) && $ok;
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
