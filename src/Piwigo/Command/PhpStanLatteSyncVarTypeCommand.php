<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Core\Paths;
use Piwigo\Tools\PhpStan\Latte\LatteTemplateFiles;
use Piwigo\Tools\PhpStan\Latte\VariableMapContext;
use Piwigo\Tools\PhpStan\Latte\VarTypeSyncer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes (or checks) a `{varType}` block at the top of every real `.latte`
 * file, sourced from the same VariableMap the compiled-analysis `@var`
 * docblock injection already uses -- see VarTypeSyncer's own docblock for
 * why this can't drift the way a hand-written `{varType}` pass would.
 *
 * Default (no `--fix`): check mode, mirroring `format:latte`/
 * `format:latte:fix` -- reports every template whose committed source
 * doesn't match what the live VariableMap currently produces and fails,
 * without writing anything. `--fix` writes the recomputed content.
 *
 * Constructor deps are src-only so the command registry resolves on a
 * `--no-dev` install; the tools classes (autoload-dev) are guarded at
 * execute() time, same as PhpStanLatteCompileCommand.
 */
#[AsCommand(name: 'phpstan-latte:sync-vartype', description: 'Checks or writes {varType} blocks in every .latte template from the live variable map')]
final class PhpStanLatteSyncVarTypeCommand extends Command
{
    public function __construct(
        private readonly Paths $paths,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('fix', null, InputOption::VALUE_NONE, 'Write the recomputed {varType} blocks instead of only checking for drift');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! class_exists(VariableMapContext::class)) {
            $output->writeln('<error>' . VariableMapContext::class . ' is not autoloadable -- dev dependencies (autoload-dev) are required.</error>');

            return Command::FAILURE;
        }

        $fix = $input->getOption('fix') === true;
        $root = rtrim($this->paths->root, '/');

        $context = VariableMapContext::build($root);
        $syncer = new VarTypeSyncer();

        $synced = 0;
        $changed = 0;
        $drifted = [];
        $notices = $context->notices;
        foreach (LatteTemplateFiles::discover($root) as $templatePath) {
            $rawSource = file_get_contents($templatePath);
            if ($rawSource === false) {
                $notices[] = "cannot read: {$templatePath}";
                continue;
            }

            $result = $syncer->sync($rawSource, $context->map->forTemplate($templatePath, $context->globals), $templatePath);
            $synced++;
            $notices = [...$notices, ...$result->notices];

            if (! $result->changed) {
                continue;
            }

            $changed++;
            if ($fix) {
                if (file_put_contents($templatePath, $result->content) === false) {
                    $notices[] = "cannot write: {$templatePath}";
                }
            } else {
                $drifted[] = $templatePath;
            }
        }

        foreach ($notices as $notice) {
            $output->writeln("<comment>notice: {$notice}</comment>");
        }

        if (! $fix && $drifted !== []) {
            foreach ($drifted as $templatePath) {
                $output->writeln("<comment>{$templatePath} needs its {varType} block updated -- run with --fix to update.</comment>");
            }
            $output->writeln(sprintf('%d of %d templates need their {varType} block updated.', count($drifted), $synced));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Synced %d templates (%d changed, %d unchanged).',
            $synced,
            $changed,
            $synced - $changed,
        ));

        return Command::SUCCESS;
    }
}
