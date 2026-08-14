<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use PHPStan\Analyser\Error;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;

/**
 * Replaces PHPStan's default `errorFormatter.table` service (the same
 * override hook efabrica's own TableErrorFormatter used): errors
 * reported against generated files under `_analysis/phpstan-latte/`
 * are remapped to their real `.latte` source file/line (column
 * appended to the message -- the table only renders a line number)
 * before delegating to the real table formatter for display. Errors in
 * ordinary files pass through untouched.
 *
 * Thin, deliberately logic-free glue over GeneratedFileErrorMapper --
 * this class touches PHPStan-internal types (`Error`'s constructor,
 * `AnalysisResult::withFileSpecificErrors()`, both confirmed against
 * the installed phar, not assumed), so its proof is the live
 * deliberately-broken-template verification; the mapping logic itself
 * is unit-tested in GeneratedFileErrorMapper.
 *
 * @api constructed only by PHPStan's own DI container via the
 * `errorFormatter.table!` service definition in phpstan.neon -- no PHP
 * call site exists for shipmonk/dead-code-detector to find.
 */
final readonly class TemplateErrorFormatter implements ErrorFormatter
{
    private GeneratedFileErrorMapper $mapper;

    public function __construct(
        private ErrorFormatter $inner,
        string $analysisDir,
    ) {
        $this->mapper = new GeneratedFileErrorMapper($analysisDir);
    }

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $errors = [];
        foreach ($analysisResult->getFileSpecificErrors() as $error) {
            $errors[] = $this->remap($error);
        }

        return $this->inner->formatErrors($analysisResult->withFileSpecificErrors($errors), $output);
    }

    private function remap(Error $error): Error
    {
        $mapped = $this->mapper->map($error->getFilePath(), $error->getLine());
        if ($mapped === null) {
            return $error;
        }

        $message = $error->getMessage();
        if ($mapped['column'] !== null) {
            $message .= " (template column {$mapped['column']})";
        }

        // Rebuilding an Error with a remapped file/line has no BC-covered
        // path; the ctor signature is pinned against the installed phar,
        // and the live broken-template verification catches a
        // minor-version change here.
        // @phpstan-ignore phpstanApi.constructor (deliberate non-BC usage, see comment above)
        return new Error(
            $message,
            $mapped['file'],
            $mapped['line'],
            $error->canBeIgnored(),
            $mapped['file'],
            $error->getTraitFilePath(),
            $error->getTip(),
            $error->getNodeLine(),
            $error->getNodeType(),
            $error->getIdentifier(),
            $error->getMetadata(),
            // No fixed-error diff: the original diff targets the *generated*
            // file, which is not where this remapped error points -- applying
            // it against the real .latte source would corrupt it.
            null,
        );
    }
}
