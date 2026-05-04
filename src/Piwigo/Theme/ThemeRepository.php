<?php

declare(strict_types=1);

namespace Piwigo\Theme;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for the theme domain. */
final class ThemeRepository extends AbstractRepository
{
    /** Insert a newly activated theme record. */
    public function activate(string $id, string $version, string $name): void
    {
        $this->conn->insert($this->table('themes'), [
            'id'      => $id,
            'version' => $version,
            'name'    => $name,
        ]);
    }

    /**
     * Return the id of any active theme other than $excludeId, or null if none.
     * Used when deactivating the current default theme to pick a replacement.
     */
    public function findAnyOtherThemeId(string $excludeId): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('themes'))
            ->where('id != :excludeId')
            ->setParameter('excludeId', $excludeId)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /** Delete the theme record by id. */
    public function deactivate(string $id): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('themes'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Return user_ids whose theme matches $theme.
     *
     * @return int[]
     */
    public function findUsersByTheme(string $theme): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from($this->table('user_infos'))
            ->where('theme = :theme')
            ->setParameter('theme', $theme)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * Set the theme for the given user ids.
     *
     * @param int[] $userIds
     */
    public function setThemeForUsers(array $userIds, string $theme): void
    {
        if ($userIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('user_infos'))
            ->set('theme', ':theme')
            ->setParameter('theme', $theme);
        $qb->where($qb->expr()->in('user_id', ':userIds'))
           ->setParameter('userIds', $userIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return all active theme rows, optionally filtered by id.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $id = ''): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('themes'));
        if (!empty($id)) {
            $qb->where('id = :id')->setParameter('id', $id);
        }
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return a map of theme id → theme name for all active themes.
     *
     * @return array<string, string>
     */
    public function findIdNameMap(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name')
            ->from($this->table('themes'))
            ->executeQuery()
            ->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['id']] = (string) $row['name'];
        }
        return $result;
    }
}
