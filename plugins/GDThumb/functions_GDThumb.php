<?php

declare(strict_types=1);

namespace Piwigo\plugins\GDThumb;

use Piwigo\admin\inc\functions_admin;

final class functions_GDThumb
{
    public static function int_delete_gdthumb_cache(
        string $pattern
    ): void {
        $contents = opendir('./' . PWG_DERIVATIVE_DIR);

        if ($contents) {
            while (($node = readdir($contents)) !== false) {
                if ($node !== '.' &&
                    $node !== '..' &&
                    is_dir('./' . PWG_DERIVATIVE_DIR . $node)
                ) {
                    functions_admin::clear_derivative_cache_rec('./' . PWG_DERIVATIVE_DIR . $node, $pattern);
                }
            }

            closedir($contents);
        }
    }

    public static function delete_gdthumb_cache(): void
    {
        self::int_delete_gdthumb_cache('#.*-cu_s9999x\d+\.[a-zA-Z0-9]{3,4}$#');
    }
}
