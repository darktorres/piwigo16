<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

/**
 * Finds every real `.latte` file's own `{templateType FqcnHere}` line --
 * Latte's native declaration a `View` implementation's `#[Template]`
 * attribute points at. Plain-text scan (no Latte parse), same convention
 * `VarTypeSyncer`'s own `{* BEGIN varType *}` marker already uses in this
 * pipeline.
 */
final class TemplateTypeScanner
{
    /**
     * @param list<string> $templatePaths
     * @return array<string, string> template realpath => View class FQCN
     */
    public static function scan(array $templatePaths): array
    {
        $result = [];
        foreach ($templatePaths as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }
            if (preg_match('/\{templateType\s+([^\s}]+)\}/', $source, $m) === 1) {
                $result[$path] = ltrim($m[1], '\\');
            }
        }

        return $result;
    }
}
