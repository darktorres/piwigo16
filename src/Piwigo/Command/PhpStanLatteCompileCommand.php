<?php

declare(strict_types=1);

namespace Piwigo\Command;

use FilesystemIterator;
use Latte\Engine;
use Latte\Runtime\Html;
use Override;
use Piwigo\Core\Paths;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Tools\PhpStan\Latte\ContextVariableExtractor;
use Piwigo\Tools\PhpStan\Latte\LatteTemplateCompiler;
use Piwigo\Tools\PhpStan\Latte\TemplateCallSiteScanner;
use Piwigo\Tools\PhpStan\Latte\VariableMapBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The `composer analyse:phpstan` pre-step: compiles the full real
 * `.latte` tree (themes/ + template-extension/, all of it -- templates
 * reached only via another template's `{include}` are still analysed,
 * a coverage improvement over the retired efabrica integration, which
 * only saw resolver-reached files) into `_analysis/phpstan-latte/`,
 * where the subsequent plain `phpstan analyse` run picks the generated
 * files up as ordinary paths -- parallel workers, native result cache,
 * no in-analysis template machinery at all.
 *
 * Runs entirely outside PHPStan (php-parser + native reflection -- see
 * TemplateCallSiteScanner/ContextVariableExtractor) because PHPStan
 * fixes its file list at startup: files written mid-run by a rule would
 * only be analysed on the *next* invocation.
 *
 * Constructor deps are src-only so the command registry resolves on a
 * `--no-dev` install; the tools classes (autoload-dev) are guarded at
 * execute() time.
 */
#[AsCommand(name: 'phpstan-latte:compile', description: 'Compiles every .latte template into the PHPStan analysis directory with typed variables')]
final class PhpStanLatteCompileCommand extends Command
{
    public const OUTPUT_DIR = '_analysis/phpstan-latte';

    public function __construct(
        private readonly PiwigoExtension $piwigoExtension,
        private readonly Paths $paths,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! class_exists(TemplateCallSiteScanner::class)) {
            $output->writeln('<error>' . TemplateCallSiteScanner::class . ' is not autoloadable -- dev dependencies (autoload-dev) are required.</error>');

            return Command::FAILURE;
        }

        $root = rtrim($this->paths->root, '/');
        $outputDir = $root . '/' . self::OUTPUT_DIR;

        $scan = (new TemplateCallSiteScanner($root))->scan();

        $extractor = new ContextVariableExtractor();
        $varsByContext = [];
        $notices = $scan->notices;
        foreach ($scan->contextsByClass as $contexts) {
            foreach ($contexts as $context) {
                if (isset($varsByContext[$context])) {
                    continue;
                }
                $extracted = $extractor->extract($context);
                $varsByContext[$context] = $extracted->vars;
                $notices = [...$notices, ...$extracted->notices];
            }
        }

        $map = (new VariableMapBuilder($scan->templatesByClass, $scan->contextsByClass, $varsByContext))->build();

        // Always-available variables from outside the context system:
        // Template.php's own framework assigns, every theme's parseable
        // $theme_template_vars literal, and assignVarFromTemplate()'s
        // rendered-Html outputs.
        $themeVars = $extractor->themeTemplateVars($root);
        $notices = [...$notices, ...$themeVars['notices']];
        $globals = $extractor->frameworkGlobals() + $themeVars['vars'];
        foreach ($scan->assignedTemplateVars as $name) {
            // Leading backslash required: this feeds
            // LatteTemplateCompiler's `/** @var {$type} ... */` docblock
            // generation, spliced directly into generated PHP source
            // text -- ::class alone never carries one, which would leave
            // the emitted @var namespace-relative instead of absolute.
            // Concatenation, not a bare string literal, so Rector's
            // StringClassNameToClassConstantRector has nothing to
            // rewrite here (empirically verified).
            $globals[$name] ??= '\\' . Html::class;
        }

        $engine = new Engine();
        $engine->addExtension($this->piwigoExtension);
        $compiler = new LatteTemplateCompiler($engine, $root, $outputDir);

        $compiled = 0;
        $changed = 0;
        $failed = [];
        $produced = [];
        foreach ($this->allTemplates($root) as $templatePath) {
            try {
                $result = $compiler->compile($templatePath, $map->forTemplate($templatePath, $globals));
            } catch (Throwable $e) {
                $failed[] = "{$templatePath}: {$e->getMessage()}";
                continue;
            }
            $compiled++;
            if ($result->changed) {
                $changed++;
            }
            $produced[basename($result->outputPath)] = true;
            $notices = [...$notices, ...$result->notices];
        }

        $pruned = $this->pruneStale($outputDir, $produced);

        foreach ($notices as $notice) {
            $output->writeln("<comment>notice: {$notice}</comment>");
        }
        if ($map->fallbackContexts !== []) {
            $output->writeln(sprintf(
                '<comment>%d context classes assigned outside any rendering class contribute to the global fallback union: %s</comment>',
                count($map->fallbackContexts),
                implode(', ', $map->fallbackContexts),
            ));
        }

        if ($failed !== []) {
            foreach ($failed as $failure) {
                $output->writeln("<error>{$failure}</error>");
            }
            $output->writeln(sprintf('Failed: %d, compiled: %d', count($failed), $compiled));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Compiled %d templates into %s (%d changed, %d unchanged, %d stale outputs pruned).',
            $compiled,
            self::OUTPUT_DIR,
            $changed,
            $compiled - $changed,
            $pruned,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function allTemplates(string $root): array
    {
        $templates = [];
        foreach ([$root . '/themes', $root . '/template-extension'] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (! $file->isFile() || $file->getExtension() !== 'latte') {
                    continue;
                }
                $realPath = $file->getRealPath();
                if ($realPath !== false) {
                    $templates[] = $realPath;
                }
            }
        }
        sort($templates);

        return $templates;
    }

    /**
     * A renamed/deleted template must not leave its stale compiled copy
     * behind to be analysed forever.
     *
     * @param array<string, true> $produced basenames written this run
     */
    private function pruneStale(string $outputDir, array $produced): int
    {
        if (! is_dir($outputDir)) {
            return 0;
        }
        $pruned = 0;
        foreach (new FilesystemIterator($outputDir, FilesystemIterator::SKIP_DOTS) as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && ! isset($produced[$file->getFilename()])) {
                unlink($file->getPathname());
                $pruned++;
            }
        }

        return $pruned;
    }
}
