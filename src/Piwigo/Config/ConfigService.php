<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Piwigo\Core\BoolUtil;
use Piwigo\Core\Kernel;
use Piwigo\Core\StringUtil;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Lifecycle\LoadConf;
use Piwigo\Html\HtmlService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class ConfigService
{
    public function __construct(
        private ConfigRepository $repo,
    ) {
    }

    /** @pre-boot Safe to call before Kernel::boot() — no DI container required. */
    public static function loadConfFromDb(?string $condition = '', bool $dieOnConditionWithNoResult = true): void
    {
        $repo = Kernel::isBooted()
            ? Kernel::service(ConfigRepository::class)
            : new ConfigRepository(DbConnection::build(), Config::dbPrefix());

        $rows = $repo->findAllRows($condition);

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
            Config::override($row['param'], $val);
        }

        if (Kernel::isBooted()) {
            Kernel::service(EventDispatcherInterface::class)->dispatch(new LoadConf($condition ?? ''));
        }
    }

    public function pwgIsDbconfWriteable(): bool
    {
        [$param, $value] = ['pwg_is_dbconf_writeable_' . StringUtil::generateKey(12), date('c') . ' ' . StringUtil::generateKey(20)];
        $this->confUpdateParam($param, $value);
        if ($this->repo->findValueByParam($param) !== $value) {
            return false;
        }
        $this->confDeleteParam($param);
        return true;
    }

    /**
     * Persist a single key/value to the conf table.
     *
     * The value is stored as a string — booleans go through BoolUtil
     * (which emits 'true'/'false' matching the on-read decode in
     * loadConfFromDb), other scalars stringify naturally. Arrays
     * are no longer accepted: every B1-B10 caller that used to pass
     * one now JSON-encodes upstream (the encoded string lands here as
     * a regular string). See SerializeAllowedRule for the rationale.
     */
    public function confUpdateParam(string $param, string|int|float|bool|null $value, bool $updateGlobal = false): void
    {
        $dbValue = is_bool($value) ? BoolUtil::toString($value) : (string) ($value ?? '');
        $this->repo->upsertParamValue($param, $dbValue);

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
        if ($params === []) {
            return;
        }
        $this->repo->deleteParams(array_values($params));

        foreach ($params as $p) {
            Config::delete($p);
        }
    }

    /**
     * Generic dynamic-key reader for plugin-persisted conf rows that
     * legitimately lack a SCHEMA entry (per-plugin keys, the existing
     * mobile-app-banner toggles, etc.). For SCHEMA-backed keys, prefer
     * the typed Config::xxx() accessors.
     *
     * @param array<mixed>|bool|int|null|string $defaultValue
     *
     * @psalm-param 90|604800|array<mixed>|bool|null|string $defaultValue
     */
    public function confGetParam(string $param, array|string|int|bool|null $defaultValue = null): mixed
    {
        return Config::all()[$param] ?? $defaultValue;
    }
}
