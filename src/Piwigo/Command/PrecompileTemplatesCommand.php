<?php

declare(strict_types=1);

namespace Piwigo\Command;

use FilesystemIterator;
use Override;
use Piwigo\Core\Paths;
use Piwigo\Template\Template;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Walks every real `.latte` template under `themes/` (this repo's full
 * real tree, confirmed 131 files, matching `tools/latte-prettier/`'s own
 * coverage figure) and warms the Latte compile cache, catching a real
 * syntax error as a build failure
 * (`bin/piwigo precompile:templates` / `composer precompile:templates`)
 * while also warming the production compile cache. First-class command
 * like `cache:clear`/`backup:create` -- an admin might reasonably want to
 * warm the cache manually after a theme change, not just as a CI gate.
 *
 * `Template::warmupLatteCache()` (not a standalone `Latte\Engine`
 * constructed here) reuses `Template`'s own already-correct engine
 * construction (cache dir resolution, mkgetdir, force-compile clearing)
 * rather than duplicating it -- `resolveLatteTemplatePath()`'s own
 * absolute-path fast path means a freshly-resolved `Template` (never had
 * `setTheme()` called) handles the already-absolute paths this class
 * passes it without needing any real theme configured first.
 */
#[AsCommand(name: 'precompile:templates', description: 'Compiles every real .latte template into the Latte cache, failing on a real syntax error')]
final class PrecompileTemplatesCommand extends Command
{
    public function __construct(
        private readonly Template $template,
        private readonly Paths $paths,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = [];
        $count = 0;

        if (is_dir($this->paths->themes)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->paths->themes, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (! $file->isFile() || $file->getExtension() !== 'latte') {
                    continue;
                }

                $absolutePath = $file->getPathname();

                try {
                    $this->template->warmupLatteCache($absolutePath);
                    $count++;
                } catch (Throwable $e) {
                    $failed[] = "{$absolutePath}: {$e->getMessage()}";
                }
            }
        }

        if ($failed !== []) {
            foreach ($failed as $failure) {
                $output->writeln("<error>{$failure}</error>");
            }
            $output->writeln(sprintf('Failed: %d, succeeded: %d', count($failed), $count));

            return Command::FAILURE;
        }

        $output->writeln("Compiled successfully: {$count} templates.");

        return Command::SUCCESS;
    }
}
