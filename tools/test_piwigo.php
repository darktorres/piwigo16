<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('PHPWG_ROOT_PATH', '../');

// Add a Clone piwigo from github//

// Usage : php test_piwigo.php --url "localhost/piwigo" --db_user "root" --db_password "password" --db_name "database" --file "picture.png"

$option = getopt('', ['url:', 'db_user:', 'db_password:', 'db_name:', 'file:', 'drop_db:']);

// Check mandatory option and test to perfom.
// For example : php test_piwigo.php --user "user" --password "123" --db_name "db123" --install "true"

$mandatory_fields = [
    'url',
    'db_user',
    'db_password',
];

foreach ($mandatory_fields as $field) {
    if (! isset($option[$field]) || ! is_string($option[$field])) {
        die('Missing --' . $field);
    }
}

$option['url'] = 'http://' . $option['url'];

// Creat cookie file

$cookies = dirname(__DIR__) . '/cookies.txt';

// Connection to mySQL

// mysqli's default report mode (MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT since
// PHP 8.1) throws on connection failure, so a plain constructor call here
// either succeeds or never reaches the next line.
$mysqli = new mysqli('localhost', $option['db_user'], $option['db_password']);

// Check if database name is set otherwise we use a random name.
// Then we create the database.

$option['db_name'] = create_database($option, $mysqli);

install_piwigo($option);
$pwg_token = test_log_user($option, $cookies);
create_album($option, $cookies);
add_picture($option, $cookies, $pwg_token, $mysqli);

// +-----------------------+
// | Create a new database |
// +-----------------------+

/**
 * @param array<string, mixed> $option
 */
function create_database(array $option, \mysqli $mysqli): string
{
    $db_name = $option['db_name'] ?? null;
    if (! is_string($db_name) || $db_name === '') {
        $db_name = uniqid();
    }

    $query = '
    SHOW DATABASES
  ;';

    $res = $mysqli->query($query);
    if (! $res instanceof mysqli_result) {
        throw new Exception('create_database(): SHOW DATABASES query failed');
    }

    while ((bool) ($row = $res->fetch_row())) {
        if ($row[0] == $db_name) {
            die('Database name already exist');
        }
    }

    $query = '
    CREATE DATABASE ' . $db_name . '
  ;';

    $mysqli->query($query);

    return $db_name;
}

// +--------------------------+
// | Script Installing Piwigo |
// +--------------------------+

/**
 * @param array<string, mixed> $option
 */
function install_piwigo(array $option): void
{
    $url = $option['url'];
    if (! is_string($url)) {
        throw new Exception('install_piwigo(): option url must be a string');
    }

    $data = [
        'install' => 1,
        'dbhost' => 'localhost',
        'dbuser' => $option['db_user'],
        'dbpasswd' => $option['db_password'],
        'dbname' => $option['db_name'],
        'prefix' => 'piwigo_',
        'admin_name' => 'admin',
        'admin_pass1' => 'pwg123',
        'admin_pass2' => 'pwg123',
        'admin_mail' => 'test@gmail.com',

    ];

    $ch = curl_init();

    $curLopt = [
        CURLOPT_URL => $url . '/install.php',
        CURLOPT_COOKIESESSION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
    ];

    curl_setopt_array($ch, $curLopt);

    $content = curl_exec($ch);
    $err = curl_errno($ch);
    $errmsg = curl_error($ch);
    $response = curl_getinfo($ch);

    sleep(2);

    require_once PHPWG_ROOT_PATH . '/local/config/database.inc.php';

    // Every define('PHPWG_INSTALLED', ...) call in this codebase always
    // writes true, so a bare reference here would either be always-true or,
    // if the generated database.inc.php lacks the define entirely, a fatal
    // undefined-constant error — defined() is the real presence check, the
    // same one used everywhere else PHPWG_INSTALLED is consulted.
    if (defined('PHPWG_INSTALLED')) {
        echo "Installation OK!\n";
    } else {
        echo "Installation KO!\n";
    }
}

// +----------------------+
// | Script Login an User |
// +----------------------+

/**
 * @param array<string, mixed> $option
 */
function test_log_user(array $option, string $cookies): mixed
{
    // the only real caller passes dirname(__DIR__) . '/cookies.txt', always non-empty
    assert($cookies !== '');

    $url = $option['url'];
    if (! is_string($url)) {
        throw new Exception('test_log_user(): option url must be a string');
    }

    // Log an user - Admin here
    $data = [
        'method' => 'pwg.session.login',
        'password' => 'pwg123',
        'username' => 'admin',
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url . '/ws.php?format=json');
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookies);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookies);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $content = curl_exec($ch);
    $err = curl_errno($ch);
    $errmsg = curl_error($ch);
    $response = curl_getinfo($ch);

    // Gets information about the current session
    $data = [
        'method' => 'pwg.session.getStatus',
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url . '/ws.php?format=json');
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookies);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookies);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $content = curl_exec($ch);
    $err = curl_errno($ch);
    $errmsg = curl_error($ch);
    $response = curl_getinfo($ch);

    if (! is_string($content)) {
        throw new Exception('test_log_user(): curl_exec() failed');
    }
    $result = json_decode($content, true);
    if (! is_array($result)) {
        throw new Exception('test_log_user(): invalid JSON response');
    }
    if (($result['stat'] ?? null) === 'ok') {
        echo "Login OK!\n";
    } else {
        echo "Login KO!\n";
    }
    $sessionResult = $result['result'] ?? null;
    return is_array($sessionResult) ? ($sessionResult['pwg_token'] ?? null) : null;
}

// +-----------------------+
// | Script Creating Album |
// +-----------------------+

/**
 * @param array<string, mixed> $option
 */
function create_album(array $option, string $cookies): void
{
    // the only real caller passes dirname(__DIR__) . '/cookies.txt', always non-empty
    assert($cookies !== '');

    $url = $option['url'];
    if (! is_string($url)) {
        throw new Exception('create_album(): option url must be a string');
    }

    $data = [
        'method' => 'pwg.categories.add',
        'name' => 'AlbumExample',
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url . '/ws.php?format=json');
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookies);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookies);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $content = curl_exec($ch);
    $err = curl_errno($ch);
    $errmsg = curl_error($ch);
    $response = curl_getinfo($ch);

    if (! is_string($content)) {
        throw new Exception('create_album(): curl_exec() failed');
    }
    $result = json_decode($content, true);
    if (! is_array($result)) {
        throw new Exception('create_album(): invalid JSON response');
    }
    if (($result['stat'] ?? null) === 'ok') {
        echo "Album creation OK!\n";
    } else {
        echo "Album creation KO!\n";
    }
}

// +-------------------------+
// |  Script adding picture  |
// +-------------------------+

/**
 * @param array<string, mixed> $option
 */
function add_picture(array $option, string $cookies, mixed $pwg_token, \mysqli $mysqli): void
{
    $url = $option['url'];
    if (! is_string($url)) {
        throw new Exception('add_picture(): option url must be a string');
    }

    exec('perl piwigo_upload.pl --url=' . $url . ' --user=admin --password=pwg123 --file=temp.png --album_id=1');

    $db_name = $option['db_name'];
    if (! is_string($db_name)) {
        throw new Exception('add_picture(): option db_name must be a string');
    }
    $mysqli->select_db($db_name);

    $query = '
    SELECT count(*)
    FROM piwigo_images
  ;';

    $res = $mysqli->query($query);
    if (! $res instanceof mysqli_result) {
        throw new Exception('add_picture(): SELECT count(*) query failed');
    }
    $row = $res->fetch_row();
    if (is_array($row) && ($row[0] ?? 0) > 0) {
        echo "Add a Picture OK!\n";
    } else {
        echo "Add a picture KO!\n";
    }
    /*$content = readfile($option['file']);
     * $content_lenght = strlen($content);
     * $nb_chunks = ceil($content_lenght / 500);
     * $chunk_pos = 0;
     * $chunk_id = 0;
     * while ($chunk_pos < $content_lenght)
     * {
     * $chunk = substr($content, $chunk_pos, 500);
     * }
     * $chunk_path = '/tmp/'.md5($option['file']).'.chunk';
     * $chunk_pos += 500;*/

    /*if (function_exists('curl_file_create'))
     * {
     * $cFile = curl_file_create($option['file']);
     * }
     * else
     * {
     * $cFile = '@'.realpath($option['file']);
     * }
     * $data = array(
     * 'method'    =>  'pwg.images.upload',
     * //'name'      =>  $option['file'],
     * //'chunk'     =>  $chunk_id,
     * //'chunks'    =>  $nb_chunks,
     * 'category'    =>  1,
     * 'file_contents' =>  $cFile,
     * 'pwg_token'   =>  $pwg_token,
     * );
     * $ch = curl_init();
     * $curLopt = array(
     * CURLOPT_URL       => $option['url'].'/ws.php?format=json',
     * //CURLOPT_HTTPHEADER    => $headers,
     * CURLOPT_COOKIEJAR   => $cookies,
     * CURLOPT_COOKIEFILE    => $cookies,
     * CURLOPT_RETURNTRANSFER  => true,
     * CURLOPT_FOLLOWLOCATION  => true,
     * CURLOPT_POST      => 1,
     * CURLOPT_POSTFIELDS    => $data,
     * );
     * curl_setopt_array($ch, $curLopt);
     * $content = curl_exec($ch);
     * $err = curl_errno($ch);
     * $errmsg = curl_error($ch);
     * $response = curl_getinfo($ch);
     * curl_close($ch);
     * $result = json_decode($content);
     * print_r($result);
     * print_r($response);*/
}

if (isset($option['drop_db']) && $option['drop_db'] == true) {
    $query = '
    DROP DATABASE ' . $option['db_name'] . '
  ;';

    $mysqli->query($query);
}

$mysqli->close();
