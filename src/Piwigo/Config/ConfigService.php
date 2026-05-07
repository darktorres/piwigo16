<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Db\Tables;

final readonly class ConfigService
{
    public function __construct(
        private Connection $conn,
    ) {
    }

    public function loadConfFromDb(?string $condition = '', bool $dieOnConditionWithNoResult = true): void
    {
        $sql  = 'SELECT param, value FROM ' . Tables::config() .
            (!empty($condition) ? ' WHERE ' . $condition : '');

        if (ServiceLocator::has(Connection::class)) {
            $conn = ServiceLocator::get(Connection::class);
        } else {
            $conn = DbConnection::build();
        }

        $rows = $conn->executeQuery($sql)->fetchAllAssociative();

        if (count($rows) === 0 && !empty($condition) && $dieOnConditionWithNoResult) {
            fatal_error('No configuration data');
        }

        foreach ($rows as $row) {
            $val = $row['value'] ?? '';
            if ($val === 'true') {
                $val = true;
            } elseif ($val === 'false') {
                $val = false;
            }
            Config::override(is_scalar($row['param']) ? (string) $row['param'] : '', $val);
        }

        EventDispatcher::notify('load_conf', $condition);
    }

    public function pwgIsDbconfWriteable(): bool
    {
        [$param, $value] = ['pwg_is_dbconf_writeable_' . generate_key(12), date('c') . ' ' . generate_key(20)];
        conf_update_param($param, $value);
        $dbvalue = $this->conn->executeQuery(
            'SELECT value FROM ' . Tables::config() . ' WHERE param = ?',
            [$param]
        )->fetchOne();
        if ($dbvalue !== $value) {
            return false;
        }
        conf_delete_param($param);
        return true;
    }

    /** @param callable-string|null $parser */
    public function confUpdateParam(string $param, mixed $value, bool $updateGlobal = false, ?string $parser = null): void
    {
        if ($parser !== null) {
            $raw     = call_user_func($parser, $value);
            $dbValue = is_scalar($raw) ? (string) $raw : '';
        } elseif (is_array($value) || is_object($value)) {
            $dbValue = serialize($value);
        } else {
            $dbValue = BoolUtil::toString(is_bool($value) ? $value : (is_scalar($value) ? (string) $value : ''));
        }

        if (ServiceLocator::has(Connection::class)) {
            ServiceLocator::get(Connection::class)->executeStatement(
                'INSERT INTO ' . Tables::config() . ' (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                [$param, $dbValue, $dbValue]
            );
        } else {
            DbConnection::build()->executeStatement(
                'INSERT INTO ' . Tables::config() . ' (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                [$param, $dbValue, $dbValue]
            );
        }

        if ($updateGlobal) {
            Config::override($param, $value);
        }
    }

    /** @param string|string[] $params */
    public function confDeleteParam(string|array $params): void
    {
        if (!is_array($params)) {
            $params = [$params];
        }
        if (empty($params)) {
            return;
        }

        if (ServiceLocator::has(Connection::class)) {
            $conn = ServiceLocator::get(Connection::class);
        } else {
            $conn = DbConnection::build();
        }
        $qb = $conn->createQueryBuilder()->delete(Tables::config());
        $qb->where($qb->expr()->in('param', ':params'))
           ->setParameter('params', $params, ArrayParameterType::STRING);
        $qb->executeStatement();

        foreach ($params as $p) {
            Config::delete($p);
        }
    }

    public function confGetParam(string $param, mixed $defaultValue = null): mixed
    {
        return Config::raw($param) ?? $defaultValue;
    }
}
