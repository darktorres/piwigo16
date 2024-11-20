<?php

namespace Piwigo\themes\smartpocket\admin;

use Piwigo\inc\functions;
use Piwigo\inc\ThemeMaintain;

class smartpocket_maintain extends ThemeMaintain
{
    private bool $installed = false;

    private array $default_conf = [
        'loop' => true, //true - false
        'autohide' => 5000, //5000 - 0
    ];

    public function activate(
        string $theme_version,
        array &$errors = []
    ): void {
        global $conf;

        if (empty($conf['smartpocket'])) {
            functions::conf_update_param('smartpocket', $this->default_conf, true);
        } elseif (count(functions::safe_unserialize($conf['smartpocket'])) != 2) {
            $conff = functions::safe_unserialize($conf['smartpocket']);

            $config = [
                'loop' => (! empty($conff['loop'])) ? $conff['loop'] : true,
                'autohide' => (! empty($conff['autohide'])) ? $conff['autohide'] : 5000,
            ];

            functions::conf_update_param('smartpocket', $config, true);
        }

        $this->installed = true;
    }

    public function deactivate(): void {}

    public function delete(): void
    {
        // delete configuration
        functions::conf_delete_param('smartpocket');
    }
}
