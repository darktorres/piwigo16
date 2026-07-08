<?php

declare(strict_types=1);

// Readiness: can we reach the configured DB. Extend once P11 adds Redis to
// the dependency graph. Deliberately doesn't go through pwg_db_connect()
// (include/dblayer/functions_mysqli.inc.php) — that wrapper assumes the
// full request bootstrap ($page['count_queries'], $conf['show_queries'],
// my_error() output), which doesn't belong in a probe response. Reuses only
// the same config-resolution path common.inc.php uses, so this reflects
// whatever DB the real app would connect to (local/config/config.inc.php
// for classic installs, PIWIGO_DB_* env vars for container/CI runs).

define('PHPWG_ROOT_PATH', './');

$conf = [];
require PHPWG_ROOT_PATH . 'include/config_default.inc.php';
@include PHPWG_ROOT_PATH . 'local/config/config.inc.php';

require PHPWG_ROOT_PATH . 'include/env.inc.php';
pwg_load_env_file(PHPWG_ROOT_PATH);
$prefixeTable = '';
pwg_apply_env_to_conf($conf, $prefixeTable);

header('Content-Type: text/plain');

$host = $conf['db_host'] ?? '';
$port = null;
$socket = null;
if (str_starts_with((string) $host, '/')) {
    $socket = $host;
    $host = null;
} else {
    $parts = explode(':', (string) $host, 2);
    $host = $parts[0];
    $port = isset($parts[1]) ? (int) $parts[1] : null;
}

$ready = false;

try {
    $mysqli = new mysqli($host, $conf['db_user'] ?? '', $conf['db_password'] ?? '', '', $port, $socket);
    $ready = $mysqli->select_db($conf['db_base'] ?? '');
} catch (\mysqli_sql_exception) {
    $ready = false;
}

if (! $ready) {
    http_response_code(503);
    echo 'DOWN';
    exit;
}

http_response_code(200);
echo 'OK';
