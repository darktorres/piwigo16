<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Validates that every template filename referenced in set_filename() /
 * set_filenames() / setFilenames() / $block->template / similar in src/
 * actually exists on disk.
 *
 * Both `.tpl` (Smarty) and `.latte` (Latte) suffixes are covered. After
 * the §1.2 Wave 2 F.0 flip, every core/admin call-site names `.latte`,
 * but a few `.tpl` references survive on the plugin-compat surface
 * (`ExtensionsController::eligible_templates`, `MailService` dynamic
 * plugin templates) — the parallel `.tpl` source files still ship for
 * the migration window, so both extensions resolve.
 *
 * Template search path mirrors Template::setTheme():
 *   admin pages  → themes/admin/_base/template/
 *   public pages → themes/_base/template/   (including subdirectories)
 *   mail         → themes/_base/template/mail/
 *   standard_pages → themes/standard_pages/template/
 */
final class TemplateFilesExistTest extends TestCase
{
    private static string $root;

    /** @var list<string> directories searched for template files */
    private static array $searchDirs;

    /** @var array<string, string>  filename (bare or relative) → full path */
    private static array $templateIndex = [];

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 3);

        self::$searchDirs = [
            self::$root . '/themes/admin/_base/template',
            self::$root . '/themes/_base/template',
            self::$root . '/themes/standard_pages/template',
        ];

        // Build a flat index keyed by bare filename and by relative path.
        // Both .tpl and .latte files are indexed — a Smarty reference and
        // a Latte reference are equally valid against the same template dir.
        foreach (self::$searchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if (!($file instanceof \SplFileInfo) || !$file->isFile()) {
                    continue;
                }
                $ext = $file->getExtension();
                if ($ext !== 'tpl' && $ext !== 'latte') {
                    continue;
                }
                $bare = $file->getFilename();
                $rel  = ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
                self::$templateIndex[$bare] = $file->getPathname();
                self::$templateIndex[$rel]  = $file->getPathname();
                self::$templateIndex[str_replace('\\', '/', $rel)] = $file->getPathname();
            }
        }
    }

    public function test_all_referenced_template_files_exist(): void
    {
        $srcDir = self::$root . '/src';
        $missing = [];

        $phpFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($phpFiles as $phpFile) {
            if (!($phpFile instanceof \SplFileInfo) || !$phpFile->isFile() || $phpFile->getExtension() !== 'php') {
                continue;
            }

            $phpFileContent = file_get_contents($phpFile->getPathname());
            $content = $phpFileContent !== false ? $phpFileContent : '';
            // Skip Template.php's own psalm @psalm-param docblock — it
            // pins a stale literal-string union to the legacy .tpl
            // extension space; not a runtime reference.
            $content = preg_replace('/@psalm-param[^*]+(?:\*(?!\/)[^*]+)*\*\//', '', $content) ?? $content;

            // Match any single- or double-quoted literal ending in `.tpl`
            // or `.latte`. The path body is restricted to filename-safe
            // chars to avoid false positives on prose like
            // `'inside the .tpl file'`.
            preg_match_all(
                '/[\'"]([a-zA-Z0-9_.\/\-]+\.(?:tpl|latte))[\'"]/',
                $content,
                $matches
            );

            foreach ($matches[1] as $name) {
                if (!isset(self::$templateIndex[$name])) {
                    $rel = str_replace(self::$root . '/', '', $phpFile->getPathname());
                    $missing["$name (in $rel)"] = true;
                }
            }
        }

        self::assertEmpty(
            array_keys($missing),
            "Referenced template files not found in any theme template directory:\n  "
            . implode("\n  ", array_keys($missing))
        );
    }
}
