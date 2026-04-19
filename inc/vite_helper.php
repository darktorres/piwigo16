<?php

namespace Piwigo\Vite;

require_once __DIR__ . '/ViteManifest.php';

/**
 * Assign Vite module filenames to Smarty template variables.
 *
 * Supports two formats:
 *   Old: ['admin', 'helpPopin']              → $vite_admin, $vite_helpPopin  (admin path)
 *   New: ['mcs' => 'themes/default/js/mcs']  → $vite_mcs                    (full source path)
 *
 * @param object $template Smarty template object
 * @param array  $modules  Array of module names or varName => sourcePath pairs
 */
function vite_assign_modules($template, $modules): void
{
    $data = [];
    foreach ($modules as $varName => $module) {
        if (is_int($varName)) {
            $varName = $module;
        }

        $file = ViteManifest::getFile($module);
        if ($file) {
            $data['vite_' . $varName] = $file;
        }
    }

    if ($data !== []) {
        $template->assign($data);
    }
}
