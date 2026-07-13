<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the plugins/themes/languages tables. Row shape
 * differs by type (confirmed via install/piwigo_structure-mysql.sql):
 * plugins has an extra `state` enum('inactive','active') column that
 * themes/languages don't -- a plugin row's mere existence means
 * "installed" (active or not), while a theme/language row's existence
 * alone means "active" (deactivating one deletes its row outright, there
 * is no persisted "installed but inactive" state for those two types).
 * That real state-machine asymmetry lives in ExtensionLifecycle, which
 * calls the plain CRUD methods here -- this repository itself stays a
 * thin, type-parameterized wrapper with no lifecycle logic of its own.
 */
final class ExtensionRepository extends AbstractRepository
{
    /**
     * @return array<string, array<string, string|null>> keyed by id
     */
    public function findAll(ExtensionType $type): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($type->table())
            ->executeQuery()
            ->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }
            /** @var array<string, string|null> $row */
            $byId[$id] = $row;
        }

        return $byId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(ExtensionType $type, string $id): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($type->table())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Plugins only: id/version/state. Themes/languages: id/version/name
     * (no state column exists on those two tables).
     */
    public function insertPlugin(string $id, string $version, string $state = 'inactive'): void
    {
        $this->conn->createQueryBuilder()
            ->insert(ExtensionType::Plugin->table())
            ->values([
                'id' => ':id',
                'version' => ':version',
                'state' => ':state',
            ])
            ->setParameter('id', $id)
            ->setParameter('version', $version)
            ->setParameter('state', $state)
            ->executeStatement();
    }

    public function insertNamed(ExtensionType $type, string $id, string $version, string $name): void
    {
        $this->conn->createQueryBuilder()
            ->insert($type->table())
            ->values([
                'id' => ':id',
                'version' => ':version',
                'name' => ':name',
            ])
            ->setParameter('id', $id)
            ->setParameter('version', $version)
            ->setParameter('name', $name)
            ->executeStatement();
    }

    public function updatePluginState(string $id, string $state): void
    {
        $this->conn->createQueryBuilder()
            ->update(ExtensionType::Plugin->table())
            ->set('state', ':state')
            ->where('id = :id')
            ->setParameter('state', $state)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    public function updateVersion(ExtensionType $type, string $id, string $version): void
    {
        $this->conn->createQueryBuilder()
            ->update($type->table())
            ->set('version', ':version')
            ->where('id = :id')
            ->setParameter('version', $version)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    public function delete(ExtensionType $type, string $id): void
    {
        $this->conn->createQueryBuilder()
            ->delete($type->table())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Themes-only: how many theme rows currently exist (perform_action()'s
     * "you can't deactivate the last theme" guard).
     */
    public function count(ExtensionType $type): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($type->table())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Themes-only: a real theme id other than $excludeId, used to pick a
     * replacement default theme when deactivating the current one
     * (perform_action()'s "find a random theme to replace" step).
     */
    public function findAnyThemeIdExcluding(string $excludeId): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(ExtensionType::Theme->table())
            ->where('id != :id')
            ->setParameter('id', $excludeId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    public function findUserIdsByTheme(string $theme): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from(Tables::userInfos())
            ->where('theme = :theme')
            ->setParameter('theme', $theme)
            ->executeQuery()
            ->fetchFirstColumn();

        $stringIds = [];
        foreach ($ids as $id) {
            if (is_scalar($id)) {
                $stringIds[] = (string) $id;
            }
        }

        return $stringIds;
    }

    /**
     * @param list<string> $userIds
     */
    public function setThemeForUsers(string $theme, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $this->conn->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('theme', ':theme')
            ->where('user_id IN (:ids)')
            ->setParameter('theme', $theme)
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * languages.class.php::perform_action()'s "delete" case: reassigns
     * every user currently on the deleted language to $newLanguage.
     */
    public function reassignUsersFromLanguage(string $oldLanguage, string $newLanguage): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('language', ':new')
            ->where('language = :old')
            ->setParameter('new', $newLanguage)
            ->setParameter('old', $oldLanguage)
            ->executeStatement();
    }

    /**
     * languages.class.php::perform_action()'s "set_default" case.
     */
    public function setLanguageForUserIds(string $language, int $defaultUserId, int $guestId): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('language', ':language')
            ->where('user_id IN (:ids)')
            ->setParameter('language', $language)
            ->setParameter('ids', [$defaultUserId, $guestId], ArrayParameterType::INTEGER)
            ->executeStatement();
    }
}
