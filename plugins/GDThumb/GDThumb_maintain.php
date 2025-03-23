<?php

declare(strict_types=1);

namespace Piwigo\plugins\GDThumb;

use Override;
use Piwigo\inc\functions;
use Piwigo\inc\PluginMaintain;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

final class GDThumb_maintain extends PluginMaintain
{
    private bool $installed = false;

    #[Override]
    public function install(
        string $plugin_version,
        array &$errors = []
    ): void {
        require dirname(__FILE__) . '/config_default.php';
        global $conf;

        if (empty($conf['gdThumb'])) {
            functions::conf_update_param('gdThumb', $config_default, true);
        }

        $this->installed = true;
    }

    #[Override]
    public function update(
        string $old_version,
        string $new_version,
        array &$errors = []
    ): void {
        $this->install($new_version, $errors);
    }

    #[Override]
    public function activate(
        string $plugin_version,
        array &$errors = []
    ): void {
        if (! $this->installed) {
            $this->install($plugin_version, $errors);
            $this->cleanUp();
        }
    }

    #[Override]
    public function uninstall(): void
    {
        $this->cleanUp();
        functions::conf_delete_param('gdThumb');
    }

    private function cleanUp(): void
    {
        if (is_dir('./' . PWG_LOCAL_DIR . 'GDThumb')) {
            $this->gtdeltree('./' . PWG_LOCAL_DIR . 'GDThumb');
        }
    }

    private function gtdeltree(
        string $path
    ): ?bool {
        if (is_dir($path)) {
            $fh = opendir($path);

            while ($file = readdir($fh)) {
                if ($file != '.' and
                    $file != '..'
                ) {
                    $pathfile = $path . '/' . $file;

                    if (is_dir($pathfile)) {
                        self::gtdeltree($pathfile);
                    } else {
                        unlink($pathfile);
                    }
                }
            }

            closedir($fh);
            return rmdir($path);
        }

        return null;
    }
}
