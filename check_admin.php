<?php

define('PHPWG_ROOT_PATH', './');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
\Piwigo\Core\Kernel::boot();

$query = 'SELECT id, username, password FROM ' . USERS_TABLE . ' WHERE id = 1';
$result = pwg_query($query);
$user = pwg_db_fetch_assoc($result);

echo "Admin user:\n";
echo 'ID: ' . $user['id'] . "\n";
echo 'Username: ' . $user['username'] . "\n";
echo 'Password hash: ' . $user['password'] . "\n";
echo "\nPassword verification test:\n";

$password_func = \Piwigo\Config\Config::passwordVerify();
$is_valid = $password_func('1234', $user['password']);
echo "Does '1234' match? " . ($is_valid ? 'YES' : 'NO') . "\n";

$is_valid2 = $password_func('p4ssword!', $user['password']);
echo "Does 'p4ssword!' match? " . ($is_valid2 ? 'YES' : 'NO') . "\n";
