<?php

declare(strict_types=1);

namespace Piwigo\Language;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for the language domain. */
final class LanguageRepository extends AbstractRepository
{
    /** Insert a newly activated language record. */
    public function activate(string $id, string $version, string $name): void
    {
        $this->conn->insert($this->table('languages'), [
            'id'      => $id,
            'version' => $version,
            'name'    => $name,
        ]);
    }

    /** Delete the language record by id. */
    public function deactivate(string $id): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('languages'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Reassign users whose language matches $fromId to $toId.
     * Called when a language is deleted.
     */
    public function reassignUsers(string $fromId, string $toId): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('user_infos'))
            ->set('language', ':toId')
            ->where('language = :fromId')
            ->setParameter('toId', $toId)
            ->setParameter('fromId', $fromId)
            ->executeStatement();
    }

    /**
     * Set language to $languageId for the given user ids (default + guest users).
     *
     * @param int[] $userIds
     */
    public function setDefaultForSystemUsers(string $languageId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('user_infos'))
            ->set('language', ':lang')
            ->setParameter('lang', $languageId);
        $qb->where($qb->expr()->in('user_id', ':userIds'))
           ->setParameter('userIds', $userIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return all active languages ordered by name.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllOrdered(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('id', 'name')
            ->from($this->table('languages'))
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return a map of language id → name for all active languages.
     *
     * @return array<string, string>
     */
    public function findIdNameMap(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name')
            ->from($this->table('languages'))
            ->executeQuery()
            ->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[is_scalar($row['id']) ? (string) $row['id'] : ''] = is_scalar($row['name']) ? (string) $row['name'] : '';
        }
        return $result;
    }
}
