<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Category\CategoryEntity;
use Piwigo\Comment\CommentEntity;
use Piwigo\Config\ConfigRepository;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ThemeEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Lang\LanguageEntity;
use Piwigo\PluginConfig\PluginEntity;
use Piwigo\Users\UserEntity;

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
 *
 * Item 15 audit: galleryStats()/extensionStats()'s 7 counts converted to
 * DQL via {@see TelemetryCountedEntity}'s enumerated dispatch --
 * `databaseInfo()`'s `SELECT VERSION()` stays DBAL (no DQL equivalent for
 * a driver-version probe). Holds `EntityManagerInterface` directly rather
 * than a raw `Connection` -- `detectDriverLabel()`/`databaseInfo()` reach
 * the connection via `$this->em->getConnection()` when they need it.
 */
final readonly class TelemetryService
{
    private const string INSTALL_ID_PARAM = 'telemetry_install_id';

    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepo,
    ) {}

    public function buildPayload(): TelemetryPayload
    {
        return new TelemetryPayload(
            $this->resolveInstallId(),
            new DateTimeImmutable(),
            $this->environmentInfo(),
            $this->databaseInfo(),
            $this->galleryStats(),
            $this->extensionStats(),
        );
    }

    /**
     * Random and persisted on first use -- never derived from anything
     * identifying (site URL, admin email, IP).
     *
     * Goes straight to ConfigRepository::upsert() (see class docblock),
     * so -- like Menu\MenubarRenderer's own direct upsert() call --
     * this must json_encode()/json_decode() the value itself: `value` is
     * a real JSON column (ConfigEntry's own docblock), and upsert()
     * itself is a plain passthrough that assumes an already-encoded
     * string, matching ConfigService::confUpdateParam()'s own
     * encode()-then-upsert() convention.
     */
    public function resolveInstallId(): string
    {
        $entry = $this->configRepo->find(self::INSTALL_ID_PARAM);
        if ($entry !== null && $entry->value !== null && $entry->value !== '') {
            $decoded = json_decode($entry->value, true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        $installId = bin2hex(random_bytes(16));
        $encodedInstallId = json_encode($installId);
        assert($encodedInstallId !== false);

        $this->configRepo->upsert(
            self::INSTALL_ID_PARAM,
            $encodedInstallId,
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
        $version = $this->em->getConnection()
            ->executeQuery(<<<SQL
            SELECT VERSION()
            SQL)
            ->fetchOne();

        return new DatabaseInfo($this->detectDriverLabel(), is_string($version) ? $version : '');
    }

    private function galleryStats(): GalleryStats
    {
        return new GalleryStats(
            $this->count(TelemetryCountedEntity::Images),
            $this->count(TelemetryCountedEntity::Categories),
            $this->count(TelemetryCountedEntity::Users),
            $this->count(TelemetryCountedEntity::Comments),
        );
    }

    private function extensionStats(): ExtensionStats
    {
        return new ExtensionStats(
            $this->count(TelemetryCountedEntity::Plugins),
            $this->count(TelemetryCountedEntity::Themes),
            $this->count(TelemetryCountedEntity::Languages),
        );
    }

    private function count(TelemetryCountedEntity $entity): int
    {
        [$entityClass, $alias] = match ($entity) {
            TelemetryCountedEntity::Images => [ImageEntity::class, 'i'],
            TelemetryCountedEntity::Categories => [CategoryEntity::class, 'c'],
            TelemetryCountedEntity::Users => [UserEntity::class, 'u'],
            TelemetryCountedEntity::Comments => [CommentEntity::class, 'cm'],
            TelemetryCountedEntity::Plugins => [PluginEntity::class, 'p'],
            TelemetryCountedEntity::Themes => [ThemeEntity::class, 't'],
            TelemetryCountedEntity::Languages => [LanguageEntity::class, 'l'],
        };

        $count = $this->em->createQueryBuilder()
            ->select("COUNT({$alias}.id)")
            ->from($entityClass, $alias)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function detectDriverLabel(): string
    {
        $platform = $this->em->getConnection()
            ->getDatabasePlatform();

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
