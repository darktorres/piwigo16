<?php

define('PHPWG_ROOT_PATH', './');
require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
\Piwigo\Core\Kernel::boot();

$user = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
    ->fetchAssociative('SELECT id, username, password FROM ' . USERS_TABLE . ' WHERE id = 1') ?: [];

echo "Admin user:\n";
echo 'ID: ' . $user['id'] . "\n";
echo 'Username: ' . $user['username'] . "\n";
echo 'Password hash: ' . $user['password'] . "\n";
echo "\nPassword verification test:\n";

$is_valid = password_verify('1234', $user['password']);
echo "Does '1234' match? " . ($is_valid ? 'YES' : 'NO') . "\n";

$is_valid2 = password_verify('p4ssword!', $user['password']);
echo "Does 'p4ssword!' match? " . ($is_valid2 ? 'YES' : 'NO') . "\n";
