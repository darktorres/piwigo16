<?php

declare(strict_types=1);

namespace Piwigo\Job;

use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for the Symfony Messenger transport (piwigo_messenger_messages).
 * Used by the admin batch-manager queue dashboard to inspect failed jobs and
 * retry/purge them.
 */
final class MessengerRepository extends AbstractRepository
{
    /**
     * Return body+headers for a single failed job (by message id).
     *
     * @return array{body: string, headers: string}|null
     */
    public function findFailedJobById(int $id): ?array
    {
        $row = $this->conn->executeQuery(
            'SELECT body, headers FROM ' . $this->table('messenger_messages')
            . ' WHERE id = ? AND queue_name = ?',
            [$id, 'piwigo_failed'],
            [ParameterType::INTEGER, ParameterType::STRING],
        )->fetchAssociative();
        if ($row === false) {
            return null;
        }
        return [
            'body'    => is_string($row['body'] ?? null) ? $row['body'] : '',
            'headers' => is_string($row['headers'] ?? null) ? $row['headers'] : '',
        ];
    }

    /**
     * Move a single failed job back to the async queue: reset queue_name +
     * available_at + delivered_at.
     */
    public function requeueFailed(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . $this->table('messenger_messages')
            . ' SET queue_name = ?, available_at = NOW(), delivered_at = NULL WHERE id = ?',
            ['piwigo_async', $id],
            [ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    /** Delete all rows in the failed queue. */
    public function purgeFailed(): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . $this->table('messenger_messages') . ' WHERE queue_name = ?',
            ['piwigo_failed'],
            [ParameterType::STRING],
        );
    }

    /**
     * Return up to 50 failed jobs (id, body, created_at, available_at)
     * ordered newest-first.
     *
     * @return list<array<string, mixed>>
     */
    public function findFailedJobs(int $limit = 50): array
    {
        return $this->conn->executeQuery(
            'SELECT id, body, created_at, available_at FROM ' . $this->table('messenger_messages')
            . ' WHERE queue_name = ? ORDER BY id DESC LIMIT ' . $limit,
            ['piwigo_failed'],
            [ParameterType::STRING],
        )->fetchAllAssociative();
    }

    /**
     * Return queue_name → pending-job-count for all queues (delivered_at NULL).
     *
     * @return array<string, int>
     */
    public function countPendingByQueueName(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT queue_name, COUNT(*) AS cnt FROM ' . $this->table('messenger_messages')
            . ' WHERE delivered_at IS NULL GROUP BY queue_name'
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key       = is_string($row['queue_name']) ? $row['queue_name'] : '';
            $out[$key] = is_numeric($row['cnt']) ? (int) $row['cnt'] : 0;
        }
        return $out;
    }
}
