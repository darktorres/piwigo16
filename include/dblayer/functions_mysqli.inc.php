<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('DB_ENGINE', 'MySQL');
define('REQUIRED_MYSQL_VERSION', '5.0.0');

define('DB_REGEX_OPERATOR', Dml::REGEX_OPERATOR);
define('DB_RANDOM_FUNCTION', Dml::RANDOM_FUNCTION);
define('MASS_UPDATES_SKIP_EMPTY', Dml::SKIP_EMPTY);

function get_dbal_connection(): Connection
{
    return DbConnection::get();
}

/**
 * @param array{primary: array<string>, update: array<string>} $dbfields
 * @param array<array<mixed>> $datas
 */
function mass_updates(string $tablename, array $dbfields, array $datas, int $flags = 0): void
{
    Dml::massUpdates($tablename, $dbfields, $datas, $flags);
}

/**
 * @param array<string,mixed> $datas
 * @param array<string,mixed> $where
 */
function single_update(string $tablename, array $datas, array $where, int $flags = 0): void
{
    Dml::singleUpdate($tablename, $datas, $where, $flags);
}

/**
 * @param string[] $dbfields
 * @param array<array<mixed>> $datas
 * @param array<mixed> $options
 */
function mass_inserts(string $table_name, array $dbfields, array $datas, array $options = []): void
{
    Dml::massInserts($table_name, $dbfields, $datas, $options);
}

/**
 * @param array<string,mixed> $data
 * @param array<mixed> $options
 */
function single_insert(string $table_name, array $data, array $options = []): void
{
    Dml::singleInsert($table_name, $data, $options);
}

function protect_column_name(string $column_name): string
{
    return Dml::protectColumnName($column_name);
}
