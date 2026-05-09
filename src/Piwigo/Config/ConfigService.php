<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Plugins\EventDispatcher;

final readonly class ConfigService
{
    public function __construct(
        private Connection $conn,
    ) {
    }

    /** @pre-boot Safe to call before Kernel::boot() — no DI container required. */
    public static function loadConfFromDb(?string $condition = '', bool $dieOnConditionWithNoResult = true): void
    {
        $sql  = 'SELECT param, value FROM ' . Tables::config() .
            (($condition !== null && $condition !== '') ? ' WHERE ' . $condition : '');

        if (ServiceLocator::has(Connection::class)) {
            $conn = ServiceLocator::get(Connection::class);
        } else {
            $conn = DbConnection::build();
        }

        $rows = $conn->executeQuery($sql)->fetchAllAssociative();

        if (count($rows) === 0 && ($condition !== null && $condition !== '') && $dieOnConditionWithNoResult) {
            HtmlService::fatalError('No configuration data');
        }

        foreach ($rows as $row) {
            $val = $row['value'] ?? '';
            if ($val === 'true') {
                $val = true;
            } elseif ($val === 'false') {
                $val = false;
            }
            /** @var array<mixed>|bool|float|int|string|null $val */
            Config::override(is_string($row['param'] ?? null) ? $row['param'] : '', $val);
        }

        EventDispatcher::notify('load_conf', $condition);
    }

    public function pwgIsDbconfWriteable(): bool
    {
        [$param, $value] = ['pwg_is_dbconf_writeable_' . StringUtil::generateKey(12), date('c') . ' ' . StringUtil::generateKey(20)];
        ServiceLocator::get(ConfigService::class)->confUpdateParam($param, $value);
        $dbvalue = $this->conn->executeQuery(
            'SELECT value FROM ' . Tables::config() . ' WHERE param = ?',
            [$param]
        )->fetchOne();
        if ($dbvalue !== $value) {
            return false;
        }
        ServiceLocator::get(ConfigService::class)->confDeleteParam($param);
        return true;
    }

    /**
     * @param callable-string|null $parser
     */
    public function confUpdateParam(string $param, mixed $value, bool $updateGlobal = false, ?string $parser = null): void
    {
        if ($parser !== null) {
            $raw     = call_user_func($parser, $value);
            $dbValue = is_scalar($raw) ? (string) $raw : '';
        } elseif (is_array($value)) {
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

    /**
     * @param array<mixed>|bool|int|null|string $defaultValue
     *
     * @psalm-param 90|604800|array<mixed>|bool|null|string $defaultValue
     */
    public function confGetParam(string $param, array|string|int|bool|null $defaultValue = null): mixed
    {
        return Config::raw($param) ?? $defaultValue;
    }
}
