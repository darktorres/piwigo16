<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Piwigo\inc\functions_url;

class functions_plugins_admin
{
    /**
     * Retrieves an url for a plugin page.
     * @param string $file - php script full name
     */
    public static function get_admin_plugin_menu_link($file)
    {
        global $page;
        $real_file = realpath($file);
        $url = functions_url::get_root_url() . 'admin.php?page=plugin';
        if ($real_file !== false) {
            $real_plugin_path = rtrim(realpath(PHPWG_PLUGINS_PATH), '\\/');
            $file = substr($real_file, strlen($real_plugin_path) + 1);
            $file = str_replace('\\', '/', $file); //Windows
            $url .= '&amp;section=' . urlencode($file);
        } elseif (isset($page['errors'])) {
            $page['errors'][] = 'PLUGIN ERROR: "' . $file . '" is not a valid file';
        }
        return $url;
    }
}
