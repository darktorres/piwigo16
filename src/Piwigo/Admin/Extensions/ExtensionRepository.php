<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Env;
use Piwigo\Users\UserInfoEntity;
use RuntimeException;

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
 *
 * $type->table() is a genuinely dynamic table selector (plugins/themes/
 * languages, chosen at runtime by the caller) -- the CRUD methods below
 * stay plain DBAL for that reason, same "dynamic table name" structural
 * exception as Category\CategoryRepository::deleteRowsWhereColumnIn().
 * `plugins` also has its own dedicated PluginConfig\PluginEntity from a
 * separate, narrower repository (PluginConfig\PluginRepository) -- not
 * reused here, since branching this class's own generic per-type methods
 * to sometimes use an entity and sometimes not would fragment the "thin
 * wrapper" design more than it would help.
 *
 * `user_infos` (touched by the 4 user-reassignment methods below, when a
 * theme/language is deleted) IS entity-mapped ({@see UserInfoEntity},
 * owned by Users\UserRepository) -- those 4 go through it via DQL rather
 * than raw DBAL, for the same identity-map-staleness reason as every
 * other cross-domain write onto an owned table this migration has fixed.
 * Holds EntityManagerInterface directly rather than being resolved via
 * getRepository() (this class owns no entity of its own), same shape as
 * Auth\AuthRepository.
 */
final readonly class ExtensionRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * @return array<string, array<string, string|null>> keyed by id
     */
    public function findAll(ExtensionType $type): array
    {
        $rows = $this->em->getConnection()
            ->createQueryBuilder()
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
     * Same `SELECT *` shape as findAll() above (all 3 tables' columns are
     * string|null -- no int/enum-as-int columns on any of plugins/themes/
     * languages, confirmed via install/piwigo_structure-mysql.sql).
     *
     * @return array<string, string|null>|null
     */
    public function find(ExtensionType $type, string $id): ?array
    {
        $row = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('*')
            ->from($type->table())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        /** @var array<string, string|null>|false $row */
        return $row === false ? null : $row;
    }

    public function insertNamed(ExtensionType $type, string $id, string $version, string $name): void
    {
        $this->em->getConnection()
            ->createQueryBuilder()
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
        $this->em->getConnection()
            ->createQueryBuilder()
            ->update(ExtensionType::Plugin->table())
            ->set('state', ':state')
            ->where('id = :id')
            ->setParameter('state', $state)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    public function updateVersion(ExtensionType $type, string $id, string $version): void
    {
        $this->em->getConnection()
            ->createQueryBuilder()
            ->update($type->table())
            ->set('version', ':version')
            ->where('id = :id')
            ->setParameter('version', $version)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    public function delete(ExtensionType $type, string $id): void
    {
        $this->em->getConnection()
            ->createQueryBuilder()
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
        $value = $this->em->getConnection()
            ->createQueryBuilder()
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
        $value = $this->em->getConnection()
            ->createQueryBuilder()
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
        $ids = $this->em->createQueryBuilder()
            ->select('ui.userId')
            ->from(UserInfoEntity::class, 'ui')
            ->where('ui.theme = :theme')
            ->setParameter('theme', $theme)
            ->getQuery()
            ->getResult();

        return array_map(static function (array $row): string {
            if (! $row['userId'] instanceof UserId) {
                throw new RuntimeException('Expected UserId from DQL scalar hydration of ui.userId');
            }

            return (string) $row['userId']->value;
        }, $ids);
    }

    /**
     * @param list<string> $userIds
     */
    public function setThemeForUsers(string $theme, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $this->em->createQueryBuilder()
            ->update(UserInfoEntity::class, 'ui')
            ->set('ui.theme', ':theme')
            ->set('ui.lastmodified', ':now')
            ->where('ui.userId IN (:ids)')
            ->setParameter('theme', $theme)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->execute();
        $this->em->clear();
    }

    /**
     * languages.class.php::perform_action()'s "delete" case: reassigns
     * every user currently on the deleted language to $newLanguage.
     */
    public function reassignUsersFromLanguage(string $oldLanguage, string $newLanguage): void
    {
        $oldLanguageVo = LangCode::tryFrom($oldLanguage);
        $newLanguageVo = LangCode::tryFrom($newLanguage);
        if (! $oldLanguageVo instanceof LangCode || ! $newLanguageVo instanceof LangCode) {
            return;
        }

        $this->em->createQueryBuilder()
            ->update(UserInfoEntity::class, 'ui')
            ->set('ui.language', ':new')
            ->set('ui.lastmodified', ':now')
            ->where('ui.language = :old')
            ->setParameter('new', $newLanguageVo)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('old', $oldLanguageVo)
            ->getQuery()
            ->execute();
        $this->em->clear();
    }

    /**
     * languages.class.php::perform_action()'s "set_default" case.
     */
    public function setLanguageForUserIds(string $language, int $defaultUserId, int $guestId): void
    {
        $languageVo = LangCode::tryFrom($language);
        if (! $languageVo instanceof LangCode) {
            return;
        }

        $this->em->createQueryBuilder()
            ->update(UserInfoEntity::class, 'ui')
            ->set('ui.language', ':language')
            ->set('ui.lastmodified', ':now')
            ->where('ui.userId IN (:ids)')
            ->setParameter('language', $languageVo)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('ids', [$defaultUserId, $guestId])
            ->getQuery()
            ->execute();
        $this->em->clear();
    }
}
