<?php

namespace Piwigo\plugins\GDThumb;

use Piwigo\admin\inc\functions_admin;

class functions_GDThumb
{
    public static function int_delete_gdthumb_cache($pattern)
    {
        $contents = opendir(PHPWG_ROOT_PATH . PWG_DERIVATIVE_DIR);

        if ($contents) {
            while (($node = readdir($contents)) !== false) {
                if ($node != '.' and
                    $node != '..' and
                    is_dir(PHPWG_ROOT_PATH . PWG_DERIVATIVE_DIR . $node)
                ) {
                    functions_admin::clear_derivative_cache_rec(PHPWG_ROOT_PATH . PWG_DERIVATIVE_DIR . $node, $pattern);
                }
            }

            closedir($contents);
        }
    }

    public static function delete_gdthumb_cache($height)
    {
        self::int_delete_gdthumb_cache('#.*-cu_s9999x' . $height . '\.[a-zA-Z0-9]{3,4}$#');
        self::int_delete_gdthumb_cache('#.*-cu_s' . $height . 'x9999\.[a-zA-Z0-9]{3,4}$#');
    }
}
