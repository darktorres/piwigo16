<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | Piwigo - a PHP based photo gallery                                    |
// +-----------------------------------------------------------------------+
// | Copyright(C) 2008-2014 Piwigo Team                  http://piwigo.org |
// | Copyright(C) 2003-2008 PhpWebGallery Team    http://phpwebgallery.net |
// | Copyright(C) 2002-2003 Pierrick LE GALL   http://le-gall.net/pierrick |
// +-----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify  |
// | it under the terms of the GNU General Public License as published by  |
// | the Free Software Foundation                                          |
// |                                                                       |
// | This program is distributed in the hope that it will be useful, but   |
// | WITHOUT ANY WARRANTY; without even the implied warranty of            |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU      |
// | General Public License for more details.                              |
// |                                                                       |
// | You should have received a copy of the GNU General Public License     |
// | along with this program; if not, write to the Free Software           |
// | Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, |
// | USA.                                                                  |
// +-----------------------------------------------------------------------+

namespace Piwigo\plugins\LocalFilesEditor\inc;

use Piwigo\inc\functions;

class functions_LocalFilesEditor
{
    /**
     * returns $code if php syntax is correct
     * else return false
     *
     * @param string $code php code
     */
    public static function eval_syntax(
        string $code
    ): false|string {
        $code = str_replace(['<?php', '?>'], '', $code);

        $b = 0;

        foreach (token_get_all($code) as $token) {
            if ($token == '{') {
                ++$b;
            } elseif ($token == '}') {
                --$b;
            }
        }

        if ($b !== 0) {
            return false;
        }

        ob_start();
        $eval = eval('if(0){' . $code . '}');
        ob_end_clean();

        if ($eval === false) {
            return false;
        }

        return '<?php' . $code . '?>';
    }

    /**
     * returns true or false if $str is bool
     * returns $str if $str is integer
     * else "$str"
     */
    public static function editarea_quote(
        string $value
    ): string {
        return match (gettype($value)) {
            'boolean' => $value ? 'true' : 'false',
            'integer' => $value,
            default => '"' . $value . '"',
        };
    }

    /**
     * returns bak file for restore
     */
    public static function get_bak_file(
        string $file
    ): array|string {
        if (functions::get_extension($file) === 'php') {
            return substr_replace($file, '.bak', strrpos($file, '.'), 0);
        }

        return $file . '.bak';

    }

    /**
     * returns dirs and subdirs
     */
    public static function get_rec_dirs(
        string $path = ''
    ): array {
        $options = [];

        if (is_dir($path)) {
            $fh = opendir($path);

            while ($file = readdir($fh)) {
                $pathfile = $path . '/' . $file;

                if ($file !== '.' &&
                    $file !== '..' &&
                    $file !== '.svn' &&
                    is_dir($pathfile)
                ) {
                    $options[$pathfile] = str_replace(['./', '/'], ['', ' / '], $pathfile);
                    $options = array_merge($options, self::get_rec_dirs($pathfile));
                }
            }

            closedir($fh);
        }

        return $options;
    }
}
