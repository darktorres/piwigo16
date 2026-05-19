<?php

declare(strict_types=1);

namespace Piwigo\Config;

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
            // ConfigRepository json-decodes values; they arrive as native PHP
            // bool/int/float/string/array/null with no further parsing needed.
            /** @var array<mixed>|bool|float|int|string|null $val */
            $val = $row['value'];
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
     * Values flow through ConfigRepository which JSON-encodes them into the
     * `piwigo_config.value json` column. Arrays are accepted again now that
     * the column holds structured JSON (callers no longer need to
     * json_encode upstream); the SerializeAllowedRule policy still rules
     * out PHP serialize().
     *
     * @param array<mixed>|bool|float|int|string|null $value
     */
    public function confUpdateParam(string $param, array|string|int|float|bool|null $value, bool $updateGlobal = false): void
    {
        $this->repo->upsertParamValue($param, $value);

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

    /**
     * Toggle: render the mobile-app banner on public gallery pages.
     * Persisted via the Configuration → General admin form.
     */
    public function showMobileAppBannerInGallery(): bool
    {
        $value = Config::all()['show_mobile_app_banner_in_gallery'] ?? null;
        return self::boolish($value, false);
    }

    /**
     * Toggle: render the mobile-app banner on admin pages.
     * Persisted via the Configuration → General admin form.
     */
    public function showMobileAppBannerInAdmin(): bool
    {
        $value = Config::all()['show_mobile_app_banner_in_admin'] ?? null;
        return self::boolish($value, true);
    }

    /**
     * Toggle: use the bundled `standard_pages` theme for identification,
     * register, password and profile screens regardless of the active
     * theme. Persisted via the admin extensions theme form.
     */
    public function useStandardPages(): bool
    {
        $value = Config::all()['use_standard_pages'] ?? null;
        return self::boolish($value, false);
    }

    /**
     * Selected logo asset id for the standard_pages theme (one of the
     * keys exposed by the admin extensions theme form). Default
     * `piwigo_logo`.
     */
    public function standardPagesSelectedLogo(): string
    {
        $value = Config::all()['standard_pages_selected_logo'] ?? null;
        return is_scalar($value) ? (string) $value : 'piwigo_logo';
    }

    /**
     * Selected color skin for the standard_pages theme. Default
     * `default`.
     */
    public function standardPagesSelectedSkin(): string
    {
        $value = Config::all()['standard_pages_selected_skin'] ?? null;
        return is_scalar($value) ? (string) $value : 'default';
    }

    /**
     * Filesystem path of the uploaded custom logo when
     * `standard_pages_selected_logo === 'custom_logo'`. Empty when no
     * custom logo is configured.
     */
    public function standardPagesSelectedLogoPath(): string
    {
        $value = Config::all()['standard_pages_selected_logo_path'] ?? null;
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Toggle: enable secondary-format ("format") uploads (alternative
     * encodings of the same image, e.g. RAW + JPEG). Persisted via the
     * Configuration → Default admin form.
     */
    public function enableFormats(): bool
    {
        $value = Config::all()['enable_formats'] ?? null;
        return self::boolish($value, false);
    }

    /**
     * Imagick output extension when rendering the JPG preview of a
     * PDF original. Default `jpg`.
     */
    public function pdfRepresentativeExt(): string
    {
        $value = Config::all()['pdf_representative_ext'] ?? null;
        return is_scalar($value) ? (string) $value : 'jpg';
    }

    /**
     * JPEG quality level used by the PDF-to-JPG preview converter.
     * Default 90.
     */
    public function pdfJpgQuality(): int
    {
        $value = Config::all()['pdf_jpg_quality'] ?? null;
        return is_numeric($value) ? (int) $value : 90;
    }

    /**
     * Cached count of orphan images (images with no album). Seeded by
     * ImageAdminService::countOrphans on first access. 0 if unset.
     */
    public function countOrphans(): int
    {
        $value = Config::all()['count_orphans'] ?? null;
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Endpoint URL the install pings with telemetry (origin hash +
     * extension activity). Default {@see AppInfo::PROJECT_URL}.
     */
    public function sendPiwigoInfosUpdateUrl(string $default): string
    {
        $value = Config::all()['send_piwigo_infos_update_url'] ?? null;
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Telemetry interval in seconds — how often
     * TelemetryService::sendPiwigoInfos may fire. Default 604800
     * (one week).
     */
    public function sendPiwigoInfosPeriodSeconds(): int
    {
        $value = Config::all()['send_piwigo_infos_period'] ?? null;
        return is_numeric($value) ? (int) $value : 604800;
    }

    /**
     * JSON blob holding the current admin menubar layout (per-section
     * ordering). Empty string when no override has been persisted.
     */
    public function menubarLayoutJson(): string
    {
        $value = Config::all()['blk_menubar'] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * Common bool-coercion for plugin-persisted toggles, which may be
     * stored as bool, '1' / '0' / 'true' / 'false', or absent.
     */
    private static function boolish(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return $default;
        }
        if (is_scalar($value)) {
            return in_array((string) $value, ['1', 'true', 'on'], true);
        }
        return $default;
    }
}
