<?php

declare(strict_types=1);

namespace Piwigo\GeoIp;

use DateTimeImmutable;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Paths;
use Piwigo\Http\HttpClientService;

/**
 * Downloads DB-IP's free "City Lite" database (CC-BY-4.0 -- the
 * attribution requirement is met by history.latte's/rating_user.latte's
 * own credit line, not by anything here) and installs it at
 * GeoIpLookupService::databasePath(). Meant to be run monthly via cron
 * (MaintenanceGeoIpUpdateCommand) -- DB-IP republishes the file once a
 * month, and this class always asks for the current month's build first.
 *
 * Same download-to-temp-file shape as
 * Admin\Extensions\PemCatalog::extractArchive() (`tempnam()` + `fopen()`
 * + HttpClientService::fetchToFile()), gzip-decompressed and moved into
 * place with rename() so a reader never observes a partially-written
 * database file.
 */
final readonly class GeoIpDatabaseUpdater
{
    private const string DEFAULT_DOWNLOAD_URL = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';

    /**
     * $downloadUrlTemplate is a testability seam, not an admin-facing
     * setting -- unlike PemCatalog's PIWIGO_ALT_*_PEM_URL (a real,
     * documented alternate-mirror feature), nothing configures this in
     * production; it just gives tests a `sprintf()` target pointed at a
     * local server instead of db-ip.com.
     */
    public function __construct(
        private Paths $paths,
        private CurrentConfig $currentConfig,
        private CurrentLogger $currentLogger,
        private string $downloadUrlTemplate = self::DEFAULT_DOWNLOAD_URL,
    ) {}

    /**
     * Tries the current month's release first, falling back to last
     * month's if DB-IP hasn't published this month's build yet (real gap
     * observed near the start of a month) -- true on success.
     */
    public function update(): bool
    {
        $now = new DateTimeImmutable();

        return $this->downloadMonth($now->format('Y-m'))
            || $this->downloadMonth($now->modify('-1 month')->format('Y-m'));
    }

    private function downloadMonth(string $yearMonth): bool
    {
        $logger = $this->currentLogger->get();

        $gzPath = tempnam(sys_get_temp_dir(), 'geoip');
        if ($gzPath === false) {
            $logger->error('GeoIpDatabaseUpdater: could not create a temp file');

            return false;
        }

        $handle = @fopen($gzPath, 'wb');
        if ($handle === false) {
            @unlink($gzPath);

            return false;
        }

        $url = sprintf($this->downloadUrlTemplate, $yearMonth);
        $downloaded = HttpClientService::fetchToFile($handle, $url, $this->currentConfig);
        fclose($handle);

        if (! $downloaded || filesize($gzPath) === 0) {
            @unlink($gzPath);

            return false;
        }

        $installed = $this->installFromGzip($gzPath);
        @unlink($gzPath);

        if (! $installed) {
            $logger->error("GeoIpDatabaseUpdater: downloaded {$url} but could not decompress/install it");
        }

        return $installed;
    }

    private function installFromGzip(string $gzPath): bool
    {
        $compressed = file_get_contents($gzPath);
        if ($compressed === false) {
            return false;
        }

        $uncompressed = @gzdecode($compressed);
        if ($uncompressed === false || $uncompressed === '') {
            return false;
        }

        $destination = GeoIpLookupService::databasePathFor($this->paths);
        $destinationDir = dirname($destination);
        if (! is_dir($destinationDir) && ! @mkdir($destinationDir, 0775, true) && ! is_dir($destinationDir)) {
            return false;
        }

        $tmpDestination = $destination . '.tmp';
        if (file_put_contents($tmpDestination, $uncompressed) === false) {
            return false;
        }

        return rename($tmpDestination, $destination);
    }
}
