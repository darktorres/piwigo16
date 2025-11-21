<?php

declare(strict_types=1);

namespace Piwigo\plugins\GDThumb;

use Piwigo\admin\inc\functions_admin;

final class functions_GDThumb
{
    public static function int_delete_gdthumb_cache(
        string $pattern
    ): void {
        error_log("[GDThumb] int_delete_gdthumb_cache() called with pattern: $pattern");
        $deleted = 0;
        $contents = opendir('./' . PWG_DERIVATIVE_DIR);

        if ($contents) {
            while (($node = readdir($contents)) !== false) {
                if ($node !== '.' &&
                    $node !== '..' &&
                    is_dir('./' . PWG_DERIVATIVE_DIR . $node)
                ) {
                    error_log("[GDThumb] Scanning directory: " . PWG_DERIVATIVE_DIR . $node);
                    functions_admin::clear_derivative_cache_rec('./' . PWG_DERIVATIVE_DIR . $node, $pattern);
                }
            }

            closedir($contents);
        } else {
            error_log("[GDThumb] Failed to open derivative directory");
        }
        error_log("[GDThumb] Deletion complete for pattern: $pattern");
    }

    public static function delete_gdthumb_cache(
        int|string $height
    ): void {
        error_log("[GDThumb] delete_gdthumb_cache() called with height: $height");
        $pattern1 = '#.*-cu_s9999x' . $height . '\.[a-zA-Z0-9]{3,4}$#';
        $pattern2 = '#.*-cu_s' . $height . 'x9999\.[a-zA-Z0-9]{3,4}$#';
        error_log("[GDThumb] Pattern 1: $pattern1");
        error_log("[GDThumb] Pattern 2: $pattern2");
        self::int_delete_gdthumb_cache($pattern1);
        self::int_delete_gdthumb_cache($pattern2);
        error_log("[GDThumb] Cache deletion finished");
    }
}
