<?php

declare(strict_types=1);

// Readiness: can we reach the configured DB. Extend once P11 adds Redis to
// the dependency graph. Deliberately doesn't go through
// \Piwigo\Db\DbConnection::build() — that helper assumes the full request
// bootstrap (the DI container, PageState's query-count/show_queries
// logging), which doesn't belong in a probe response.
// \Piwigo\Db\DbCredentials::current() (env-only, Config generic-accessor
// removal) is exactly the same credential source DbConnection::build()
// itself resolves to, so this reflects whatever DB the real app would
// connect to.

require __DIR__ . '/../vendor/autoload.php';

$paths = \Piwigo\Core\Paths::fromRoot(dirname(__DIR__));

\Piwigo\Core\Env::loadEnvFile($paths->root);

header('Content-Type: text/plain');

$credentials = \Piwigo\Db\DbCredentials::current();
$host = $credentials->host;
$socket = null;
if (str_starts_with($host, '/')) {
    $socket = $host;
    $host = null;
}

$ready = false;

try {
    $mysqli = new mysqli($host, $credentials->user, $credentials->password, '', $credentials->port, $socket);
    $ready = $mysqli->select_db($credentials->database);
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
