<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tools;

use function escapeshellarg;
use function exec;

use PHPUnit\Framework\TestCase;

/**
 * Exercises `tools/plugin-lint.php` end-to-end via shell so the test
 * mirrors how a plugin author actually invokes `composer piwigo:lint`.
 *
 * - A clean fixture (`valid_plugin`) must exit 0.
 * - A dirty fixture (`dirty_plugin`) must exit 1 and surface every
 *   rule we ship in the regex table.
 */
final class PluginLintTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../tools/plugin-lint.php';

    public function testCleanPluginExitsZero(): void
    {
        [$exit, $output] = $this->runLint(dirname(__DIR__, 3) . '/tests/fixtures/plugins/valid_plugin');

        self::assertSame(0, $exit, 'Clean fixture must exit 0; got output: ' . implode("\n", $output));
        self::assertStringContainsString('plugin-lint: clean', implode("\n", $output));
    }

    public function testDirtyPluginExitsOneAndFlagsEveryRule(): void
    {
        [$exit, $output] = $this->runLint(dirname(__DIR__, 3) . '/tests/fixtures/plugins/dirty_plugin');

        $joined = implode("\n", $output);
        self::assertSame(1, $exit, 'Dirty fixture must exit 1; got output: ' . $joined);

        foreach (['pwg_query', 'images_table', 'globals', 'load_language', 'trigger_change'] as $expectedRule) {
            self::assertStringContainsString("[{$expectedRule}]", $joined, "lint output missing rule: {$expectedRule}");
        }
    }

    /**
     * @return array{0:int,1:list<string>}
     */
    private function runLint(string $target): array
    {
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' ' . escapeshellarg($target) . ' 2>&1';
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $lines = [];
        foreach ($output as $line) {
            $lines[] = (string) $line;
        }
        return [$exit, $lines];
    }
}
