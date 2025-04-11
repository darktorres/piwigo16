<?php

declare(strict_types=1);

use Piwigo\inc\functions;
use Piwigo\themes\modus\functions_modus;

function theme_activate(
    string $id,
    string $version,
    array &$errors
): void {
    global $conf;

    $default_conf = functions_modus::modus_get_default_config();

    $my_conf = $conf->modus_theme;

    if ($my_conf === []) {
        $my_conf = $default_conf;
    }

    $my_conf = array_merge($default_conf, $my_conf);
    $my_conf = array_intersect_key($my_conf, $default_conf);
    functions::conf_update_param('modus_theme', addslashes(serialize($my_conf)));
}

function theme_delete(): void
{
    global $conf;

    $query = <<<SQL
        DELETE FROM config
        WHERE param = 'modus_theme';
        SQL;
    $conf->sql_backend::pwg_query($query);
}
