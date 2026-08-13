<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

/**
 * Maps `(generated analysis file, line)` back to
 * `(real .latte template, line, column)` using only what Latte's own
 * compiler already embeds: the `/** source: <path> *``/` header and the
 * `/* pos L:C *``/` comment trailing every generated statement.
 * LatteTemplateCompiler's transforms deliberately keep both intact
 * (line-preserving edits plus whole-line insertions), so walking
 * backward from a reported line to the nearest preceding pos marker is
 * exact, not heuristic.
 *
 * Pure logic, no PHPStan types -- unit-tested directly, while the thin
 * TemplateErrorFormatter glue around it is proven by the live
 * deliberately-broken-template check instead.
 */
final class GeneratedFileErrorMapper
{
    /**
     * @var array<string, list<string>> generated file => lines, read once
     */
    private array $lineCache = [];

    public function __construct(
        private readonly string $analysisDir,
    ) {}

    public function isGeneratedFile(string $filePath): bool
    {
        return str_starts_with($filePath, rtrim($this->analysisDir, '/') . '/');
    }

    /**
     * @return array{file: string, line: int|null, column: int|null}|null
     *   null when the file is not a generated analysis file or carries
     *   no source header (never invent a mapping)
     */
    public function map(string $filePath, ?int $line): ?array
    {
        if (! $this->isGeneratedFile($filePath)) {
            return null;
        }
        $lines = $this->lines($filePath);
        if ($lines === []) {
            return null;
        }

        $source = null;
        foreach ($lines as $candidate) {
            if (preg_match('#^/\*\* source: (.+) \*/$#', trim($candidate), $m) === 1) {
                $source = $m[1];
                break;
            }
        }
        if ($source === null) {
            return null;
        }

        if ($line === null) {
            return [
                'file' => $source,
                'line' => null,
                'column' => null,
            ];
        }

        for ($i = min($line, count($lines)) - 1; $i >= 0; $i--) {
            if (preg_match('#/\* pos (\d+):(\d+) \*/#', $lines[$i], $m) === 1) {
                return [
                    'file' => $source,
                    'line' => (int) $m[1],
                    'column' => (int) $m[2],
                ];
            }
        }

        return [
            'file' => $source,
            'line' => null,
            'column' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function lines(string $filePath): array
    {
        if (! isset($this->lineCache[$filePath])) {
            $content = is_file($filePath) ? file_get_contents($filePath) : false;
            $this->lineCache[$filePath] = $content === false ? [] : explode("\n", $content);
        }

        return $this->lineCache[$filePath];
    }
}
