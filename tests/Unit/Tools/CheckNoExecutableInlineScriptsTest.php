<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tools;

use function escapeshellarg;
use function exec;

use PHPUnit\Framework\TestCase;

/**
 * Exercises `tools/check-no-executable-inline-scripts.php` end-to-end
 * via shell so the test mirrors how CI invokes
 * `composer lint:no-inline-scripts`. Self-contained: each test stages
 * a tiny tree of .latte files under sys_get_temp_dir() and asserts
 * the scanner's exit code + stderr.
 */
final class CheckNoExecutableInlineScriptsTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../tools/check-no-executable-inline-scripts.php';

    private string $root = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/piwigo-inline-script-lint-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/template', recursive: true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    public function test_allowed_script_types_pass(): void
    {
        $this->write('template/allowed.latte', <<<'LATTE'
            <script type="application/json">{"x":1}</script>
            <script type="application/ld+json">{}</script>
            <script type="importmap">{}</script>
            LATTE);

        [$exit, $stdout, $stderr] = $this->runLint();

        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertStringContainsString('OK', $stdout);
    }

    public function test_missing_type_fails(): void
    {
        $this->write('template/bad.latte', '<script>alert(1)</script>');

        [$exit, , $stderr] = $this->runLint();

        self::assertSame(1, $exit);
        self::assertStringContainsString('without type=', $stderr);
        self::assertStringContainsString('bad.latte', $stderr);
    }

    public function test_disallowed_type_fails(): void
    {
        $this->write('template/bad.latte', '<script type="text/javascript">alert(1)</script>');

        [$exit, , $stderr] = $this->runLint();

        self::assertSame(1, $exit);
        self::assertStringContainsString('not in allow-list', $stderr);
    }

    public function test_mail_templates_are_skipped(): void
    {
        mkdir($this->root . '/template/mail/text/html', recursive: true);
        $this->write('template/mail/text/html/header.latte', '<script>alert(1)</script>');

        [$exit] = $this->runLint();

        self::assertSame(0, $exit, 'mail templates must be excluded from the CSP-aligned scan');
    }

    private function write(string $relativePath, string $contents): void
    {
        file_put_contents($this->root . '/' . $relativePath, $contents);
    }

    /** @return array{0:int,1:string,2:string} */
    private function runLint(): array
    {
        $pid = getmypid();
        $stderrFile = sys_get_temp_dir() . '/inline-script-lint-stderr-' . ($pid !== false ? $pid : 0);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::SCRIPT) . ' ' . escapeshellarg($this->root) . ' 2>' . escapeshellarg($stderrFile);
        exec($cmd, $stdoutLines, $exit);
        $stderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';
        if (is_file($stderrFile)) {
            unlink($stderrFile);
        }
        return [$exit, implode("\n", $stdoutLines), $stderr];
    }

    private function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        rmdir($path);
    }
}
