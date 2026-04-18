<?php
namespace Piwigo\Vite;

use Piwigo\Vite\ViteManifest;

/**
 * Helper function to assign multiple Vite module filenames to a template
 *
 * Usage: vite_assign_modules($template, ['maintenance', 'tags', 'common'])
 * Creates template variables: $vite_maintenance, $vite_tags, $vite_common
 *
 * @param object $template Smarty template object
 * @param array $modules Array of module names (without .js extension)
 */
function vite_assign_modules($template, $modules) {
    $data = [];
    foreach ($modules as $key) {
        $file = ViteManifest::getFile($key);
        if ($file) {
            $data['vite_' . $key] = $file;
        }
    }
    if (!empty($data)) {
        $template->assign($data);
    }
}
?>
