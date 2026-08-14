<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The real `.latte` tree's file set -- themes/ + template-extension/, every
 * `.latte` file including ones reached only via {include}. Shared by
 * PhpStanLatteCompileCommand and PhpStanLatteSyncVarTypeCommand: both must
 * always walk the identical file set, or the compiled-docblock injection and
 * the {varType} source write-back could silently diverge on which templates
 * exist.
 */
final class LatteTemplateFiles
{
    /**
     * @return list<string>
     */
    public static function discover(string $root): array
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
}
