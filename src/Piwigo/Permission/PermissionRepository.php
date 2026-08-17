<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Category\CategoryEntity;
use Piwigo\Category\UserAccessEntity;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\BatchWriter;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use UnexpectedValueException;

/**
 * Persistence layer for the permission domain's forbidden-categories
 * computation, plus the `user_access` write methods below. Owns no table
 * itself -- `categories`/`group_access` are read-only cross-domain
 * touches (each owned elsewhere), and `user_access` is a shared join
 * table with no single owner: CategoryRepository writes permission grants
 * tied to a category's own lifecycle, this class writes/deletes them from
 * the permission-management side -- holds EntityManagerInterface directly
 * rather than being resolved via getRepository(), same shape as
 * Auth\AuthRepository.
 *
 * Every method here uses real DQL except `massInsertUserAccess()`
 * (`INSERT IGNORE` via `BatchWriter`, which `persist()`+`flush()` has no
 * equivalent for).
 * `findGrantedGroupIdsByCategory()` uses `getArrayResult()` rather than
 * `getSingleColumnResult()` specifically because `getArrayResult()`
 * applies each column's real custom Doctrine Type (unlike
 * `getSingleColumnResult()`'s own `HYDRATE_SCALAR_COLUMN`, which skips
 * custom-Type conversion entirely), so those rows hydrate as real
 * `CategoryId`/`GroupId`/`UserId` VO instances, not raw ints.
 */
final readonly class PermissionRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * @return list<int>
     */
    public function findPrivateCategoryIds(): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->where('c.status = :status')
            ->setParameter('status', 'private')
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * @return list<int>
     */
    public function findLockedCategoryIds(): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->where('c.visible = :visible')
            ->setParameter('visible', false)
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * @return list<int>
     *
     * $userId wraps via UserId::from() -- this method's only real caller,
     * PermissionService::getForbiddenCategories(), is always reached with
     * an already-authenticated real user's own id (via its 2 real
     * cache-wrapper callers, EffectiveForbiddenCategoriesCache/
     * ForbiddenCategoriesCache), unlike deleteUserAccess() below.
     */
    public function findDirectlyAuthorizedCategoryIds(int $userId): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('ua.catId')
            ->from(UserAccessEntity::class, 'ua')
            ->where('ua.userId = :userId')
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * Deletes direct user-category access rows.
     *
     * @param list<int> $catIds
     *
     * UserId::tryFrom(), not from() -- this method's only real caller
     * (PermissionService::removeUserAccess(), from
     * Admin\UserPermPageRenderer) reaches it with a URL param validated
     * only by is_numeric() (not a positive-int guarantee); a malformed
     * value can never match a real user_access row either way, so it's a
     * silent no-op instead of throwing, matching this method's own
     * existing "no-op for no catIds" contract just above.
     */
    public function deleteUserAccess(int $userId, array $catIds): void
    {
        if ($catIds === []) {
            return;
        }

        $userIdVo = UserId::tryFrom($userId);
        if (! $userIdVo instanceof UserId) {
            return;
        }

        $this->em->createQueryBuilder()
            ->delete(UserAccessEntity::class, 'ua')
            ->where('ua.userId = :userId')
            ->andWhere('ua.catId IN (:catIds)')
            ->setParameter('userId', $userIdVo)
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $this->em->clear();
    }

    /**
     * Which of $catIds are private categories -- addPermissionOnCategory()'s
     * own "only private categories need explicit user_access rows" filter.
     *
     * @param  list<int>  $catIds
     * @return list<int>
     */
    public function findPrivateCategoryIdsAmong(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }

        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->where('c.id IN (:catIds)')
            ->andWhere('c.status = :status')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->setParameter('status', 'private')
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * $ignore matters for the same reason as Category\CategoryRepository::
     * massInsertGroupAccess()'s own docblock -- stays raw DBAL
     * (BatchWriter), since persist()+flush() has no INSERT IGNORE
     * equivalent. Defaults to true. Controller\Admin\SiteUpdateSubController
     * passes false because its own `$insert_granted_users` is already
     * deduplicated via array_unique() beforehand, so INSERT IGNORE's
     * collision-swallowing there would only ever mask a genuine bug, not a
     * real duplicate.
     *
     * @param  list<array{user_id: int, cat_id: int}>  $inserts
     */
    public function massInsertUserAccess(array $inserts, bool $ignore = true): void
    {
        if ($inserts === []) {
            return;
        }

        new BatchWriter($this->em->getConnection())
            ->massInsert('user_access', ['user_id', 'cat_id'], $inserts, [
                'ignore' => $ignore,
            ]);
        $this->em->clear();
    }

    /**
     * Which group ids are directly granted access to each of $catIds --
     * SiteUpdateSubController's own "copy the parent's permissions onto
     * newly-synchronized child categories" step.
     *
     * @param  list<int>  $catIds
     * @return array<int, list<int>> keyed by cat_id
     */
    public function findGrantedGroupIdsByCategory(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('ga.catId', 'ga.groupId')
            ->from(GroupAccessEntity::class, 'ga')
            ->where('ga.catId IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! $row['catId'] instanceof CategoryId || ! $row['groupId'] instanceof GroupId) {
                continue;
            }
            $grouped[$row['catId']->value][] = $row['groupId']->value;
        }

        return $grouped;
    }

    /**
     * Which user ids are directly granted access to each of $catIds -- same
     * purpose as findGrantedGroupIdsByCategory() above, for individual users
     * rather than groups.
     *
     * @param  list<int>  $catIds
     * @return array<int, list<int>> keyed by cat_id
     */
    public function findGrantedUserIdsByCategory(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('ua.catId', 'ua.userId')
            ->from(UserAccessEntity::class, 'ua')
            ->where('ua.catId IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! $row['catId'] instanceof CategoryId || ! $row['userId'] instanceof UserId) {
                continue;
            }
            $grouped[$row['catId']->value][] = $row['userId']->value;
        }

        return $grouped;
    }

    /**
     * Image ids outside $structuralForbidden with an access level above
     * $level -- EffectiveForbiddenCategoriesCache's own "which specific
     * images does the level restriction additionally forbid" step.
     *
     * $structuralForbidden (a comma-separated id list,
     * PermissionService::getForbiddenCategories()'s own return shape) and
     * $level are both bound query parameters, not string-interpolated.
     *
     * @return list<int>
     */
    public function findImageIdsOutsideForbiddenCategories(string $structuralForbidden, int|string $level): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('DISTINCT i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin('i.imageCategories', 'ic')
            ->where('ic.category NOT IN (:forbidden)')
            ->andWhere('i.level > :level')
            ->setParameter('forbidden', self::csvToIntList($structuralForbidden), ArrayParameterType::INTEGER)
            ->setParameter('level', is_numeric($level) ? (int) $level : 0, ParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * Count of distinct accessible images given $structuralForbidden plus
     * the already-computed $imageAccessType/$imageAccessList restriction --
     * EffectiveForbiddenCategoriesCache's own `nbTotalImages`.
     *
     * $structuralForbidden/$imageAccessList are bound query parameters.
     * $imageAccessType (the SQL inclusion operator itself, 'IN'/'NOT IN')
     * can't be a bound parameter -- SQL has no placeholder syntax for an
     * operator position -- so it's validated against the fixed 2-value
     * domain instead.
     */
    public function countAccessibleImages(string $structuralForbidden, string $imageAccessType, string $imageAccessList): string
    {
        if (! in_array($imageAccessType, ['IN', 'NOT IN'], true)) {
            throw new UnexpectedValueException('Unexpected image_access_type: ' . $imageAccessType);
        }

        $total = $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(ic.image)) AS total')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.category NOT IN (:forbidden)')
            ->andWhere('ic.image ' . $imageAccessType . ' (:accessList)')
            ->setParameter('forbidden', self::csvToIntList($structuralForbidden), ArrayParameterType::INTEGER)
            ->setParameter('accessList', self::csvToIntList($imageAccessList), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleScalarResult();

        return is_scalar($total) ? (string) $total : '0';
    }

    /**
     * @return list<int>
     */
    private static function csvToIntList(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        return array_map(intval(...), explode(',', $csv));
    }

    /**
     * Whether $imageId belongs to any category NOT in $forbiddenCategoryIds
     * -- ImageVisibilityChecker's [SEC-33] fast-path check. Deliberately
     * just this one cheap query against already-computed forbidden-category
     * ids, never a PermissionService call: the image-derivative fast-path
     * decision (docs/REFERENCE.md's "Key design decisions") forbids live
     * permission recomputation on this path (see ImageVisibilityChecker's
     * own docblock).
     *
     * @param list<int> $forbiddenCategoryIds
     */
    public function isImageOutsideForbiddenCategories(ImageId $imageId, array $forbiddenCategoryIds): bool
    {
        $nb = $this->em->createQueryBuilder()
            ->select('COUNT(IDENTITY(ic.image)) AS nb')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.image = :imageId')
            ->andWhere('ic.category NOT IN (:forbidden)')
            ->setParameter('imageId', $imageId->value)
            ->setParameter('forbidden', $forbiddenCategoryIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($nb) && (int) $nb !== 0;
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private static function toIntList(array $values): array
    {
        return array_values(array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            $values
        ));
    }
}
