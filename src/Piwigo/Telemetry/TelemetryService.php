<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Piwigo\Config\ConfigRepository;
use Piwigo\Core\AppInfo;
use Piwigo\Db\Tables;

/**
 * Builds the anonymous "phone home" telemetry payload: version strings and
 * aggregate counts only, never site URLs, admin emails, IPs, or the
 * contents of any row. Genuinely greenfield -- no procedural predecessor
 * exists in this codebase. Sending the payload anywhere is out of scope
 * here; this only assembles it (same "build small and real, don't
 * over-engineer a greenfield delta" discipline as P18's AuditService).
 *
 * Goes straight to ConfigRepository rather than ConfigService/CurrentConfig::,
 * since resolveInstallId()'s get-or-create must read/write the real DB
 * row every time, not the request-scoped CurrentConfig:: snapshot.
 */
final readonly class TelemetryService
{
    private const string INSTALL_ID_PARAM = 'telemetry_install_id';

    public function __construct(
        private Connection $conn,
        private ConfigRepository $configRepo,
    ) {}

    public function buildPayload(): TelemetryPayload
    {
        return new TelemetryPayload(
            $this->resolveInstallId(),
            new \DateTimeImmutable(),
            $this->environmentInfo(),
            $this->databaseInfo(),
            $this->galleryStats(),
            $this->extensionStats(),
        );
    }

    /**
     * Random and persisted on first use -- never derived from anything
     * identifying (site URL, admin email, IP).
     */
    public function resolveInstallId(): string
    {
        $entry = $this->configRepo->find(self::INSTALL_ID_PARAM);
        if ($entry !== null && $entry->value !== null && $entry->value !== '') {
            return $entry->value;
        }

        $installId = bin2hex(random_bytes(16));
        $this->configRepo->upsert(
            self::INSTALL_ID_PARAM,
            $installId,
            'Anonymous, randomly generated install identifier used only for telemetry aggregation.'
        );

        return $installId;
    }

    private function environmentInfo(): EnvironmentInfo
    {
        return new EnvironmentInfo(AppInfo::VERSION, PHP_VERSION, PHP_OS_FAMILY);
    }

    private function databaseInfo(): DatabaseInfo
    {
        $version = $this->conn->executeQuery('SELECT VERSION()')
            ->fetchOne();

        return new DatabaseInfo($this->detectDriverLabel(), is_string($version) ? $version : '');
    }

    private function galleryStats(): GalleryStats
    {
        return new GalleryStats(
            $this->count(Tables::images()),
            $this->count(Tables::categories()),
            $this->count(Tables::users()),
            $this->count(Tables::comments()),
        );
    }

    private function extensionStats(): ExtensionStats
    {
        return new ExtensionStats(
            $this->count(Tables::plugins()),
            $this->count(Tables::themes()),
            $this->count(Tables::languages()),
        );
    }

    private function count(string $table): int
    {
        $count = $this->conn->executeQuery('SELECT COUNT(*) FROM ' . $table)->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function detectDriverLabel(): string
    {
        $platform = $this->conn->getDatabasePlatform();

        if ($platform instanceof MariaDBPlatform) {
            return 'mariadb';
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            return 'mysql';
        }
        if ($platform instanceof PostgreSQLPlatform) {
            return 'pgsql';
        }

        return 'unknown';
    }
}
