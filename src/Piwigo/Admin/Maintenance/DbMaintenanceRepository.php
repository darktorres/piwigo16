<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Db\Tables;

/**
 * Persistence layer for admin/maintenance_actions.php's own raw SQL
 * (identically duplicated in admin/maintenance_env.php -- both files
 * implement the exact same 16-action dispatch switch; see
 * MaintenanceActionDispatcher's own docblock for why that duplication is
 * consolidated there, not just here).
 */
final class DbMaintenanceRepository
{
    public function __construct(
        private readonly Connection $conn,
    ) {}

    public function purgeHistoryDetail(): void
    {
        $this->conn->createQueryBuilder()
            ->delete(Tables::history())
            ->executeStatement();
    }

    public function purgeHistorySummary(): void
    {
        $this->conn->createQueryBuilder()
            ->delete(Tables::historySummary())
            ->executeStatement();
    }

    public function purgeUnusedFeeds(): void
    {
        $this->conn->createQueryBuilder()
            ->delete(Tables::userFeed())
            ->where('last_check IS NULL')
            ->executeStatement();
    }

    public function purgeSearchHistory(): void
    {
        $this->conn->createQueryBuilder()
            ->delete(Tables::search())
            ->executeStatement();
    }

    public function countLoungeItems(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::lounge())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Sessions belonging to a since-deleted user id -- should never happen
     * in practice, purged defensively. $idColumn is $conf['user_fields']['id']
     * (external-auth column mapping, same convention as every other admin
     * page reading the users table).
     */
    public function purgeSessionsForDeletedUsers(string $idColumn): void
    {
        $sessions = $this->conn->createQueryBuilder()
            ->select('id', 'data')
            ->from(Tables::sessions())
            ->executeQuery()
            ->fetchAllAssociative();

        $allUserIds = $this->conn->createQueryBuilder()
            ->select($idColumn . ' AS id')
            ->from(Tables::users())
            ->executeQuery()
            ->fetchFirstColumn();
        $allUserIdStrings = [];
        foreach ($allUserIds as $userId) {
            if (is_scalar($userId)) {
                $allUserIdStrings[] = (string) $userId;
            }
        }

        $sessionsToDelete = [];
        foreach ($sessions as $session) {
            $data = $session['data'] ?? null;
            if (! is_string($data)) {
                continue;
            }
            if ((bool) preg_match('/pwg_uid\|i:(\d+);/', $data, $matches)
                and ! in_array($matches[1], $allUserIdStrings, true)) {
                $sessionId = $session['id'] ?? null;
                if (is_string($sessionId)) {
                    $sessionsToDelete[] = $sessionId;
                }
            }
        }

        if ($sessionsToDelete === []) {
            return;
        }

        $this->conn->createQueryBuilder()
            ->delete(Tables::sessions())
            ->where('id IN (:ids)')
            ->setParameter('ids', $sessionsToDelete, ArrayParameterType::STRING)
            ->executeStatement();
    }
}
