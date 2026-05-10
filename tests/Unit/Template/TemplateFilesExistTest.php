<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Validates that every `.latte` template filename referenced in
 * setFilename() / setFilenames() / $block->template / similar under
 * src/ actually exists on disk. `.tpl` references in the `extends for
 * templates` admin UI's hardcoded list (`ExtensionsController::
 * eligible_templates`) are skipped — Phase F deleted the source files;
 * those keys remain as legacy identifiers in the config row format.
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
        foreach (self::$searchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if (!($file instanceof \SplFileInfo) || !$file->isFile()) {
                    continue;
                }
                if ($file->getExtension() !== 'latte') {
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

            // Match any single- or double-quoted literal ending in
            // `.latte`. The path body is restricted to filename-safe
            // chars to avoid false positives on prose.
            preg_match_all(
                '/[\'"]([a-zA-Z0-9_.\/\-]+\.latte)[\'"]/',
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
