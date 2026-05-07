<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Validates that every .tpl filename referenced in set_filename() /
 * set_filenames() calls inside src/ actually exists on disk.
 *
 * Template search path mirrors Template::set_theme():
 *   admin pages  → themes/admin/_base/template/
 *   public pages → themes/default/template/   (including subdirectories)
 *   mail         → themes/default/template/mail/
 */
final class TemplateFilesExistTest extends TestCase
{
    private static string $root;

    /** @var list<string> directories searched for .tpl files */
    private static array $searchDirs;

    /** @var array<string, string>  tplName => first directory that has it */
    private static array $tplIndex = [];

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 3);

        self::$searchDirs = [
            self::$root . '/themes/admin/_base/template',
            self::$root . '/themes/default/template',
            self::$root . '/themes/standard_pages/template',
        ];

        // Build a flat index: basename (and relative sub-paths) → exists
        foreach (self::$searchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'tpl') {
                    // Key by bare filename AND by relative path from the theme root.
                    $bare = $file->getFilename();
                    $rel  = ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
                    self::$tplIndex[$bare]  = $file->getPathname();
                    self::$tplIndex[$rel]   = $file->getPathname();
                    // Also with forward slashes for cross-platform references.
                    self::$tplIndex[str_replace('\\', '/', $rel)] = $file->getPathname();
                }
            }
        }
    }

    public function test_all_referenced_tpl_files_exist(): void
    {
        $srcDir = self::$root . '/src';
        $missing = [];

        $phpFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($phpFiles as $phpFile) {
            if (!$phpFile->isFile() || $phpFile->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($phpFile->getPathname()) ?: '';

            // Match: ->set_filename('handle', 'foo.tpl')
            //        ->set_filenames(['handle' => 'foo.tpl', ...])
            preg_match_all(
                '/[\'"]([a-zA-Z0-9_.\/\-]+\.tpl)[\'"]/',
                $content,
                $matches
            );

            foreach ($matches[1] as $tplName) {
                if (!isset(self::$tplIndex[$tplName])) {
                    $rel = str_replace(self::$root . '/', '', $phpFile->getPathname());
                    $missing["$tplName (in $rel)"] = true;
                }
            }
        }

        self::assertEmpty(
            array_keys($missing),
            "Referenced .tpl files not found in any theme template directory:\n  "
            . implode("\n  ", array_keys($missing))
        );
    }
}
