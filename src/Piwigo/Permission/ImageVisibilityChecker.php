<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Db\Tables;

/**
 * [SEC-33] Decides, from precomputed permission data only, whether an
 * image is visible to a given user -- false means the image belongs
 * exclusively to categories the user cannot see (or that no permission
 * data exists for the user at all, which fails closed).
 *
 * P23 batch 8f (i.php): relocated from i.php's
 * check_derivative_permission() free function (minus the HTTP 403
 * emission, which stays with the caller), unchanged logic. Mirrors the
 * bootMinimal()-era design ADR-0007/0008 already settled: 1 query for the
 * already-computed user_cache.forbidden_categories, never recomputing
 * permissions live on the fast path -- deliberately NOT PermissionService,
 * whose live recomputation is exactly what this fast path must avoid.
 *
 * Was legacy-mysqli-only until Legacy Coupling Retirement: DI+DBAL
 * migration retargeted it onto DBAL (Connection is directly
 * constructible with no DI container, matching every other
 * AbstractRepository-based class in this codebase) -- the original
 * MysqliDb-only design predated the FrankenPHP/workers conversion plan;
 * under a persistent worker process the connection is already warm, so
 * there's no longer a per-request container-cost reason to special-case
 * this file's own DB access.
 */
final readonly class ImageVisibilityChecker
{
    public function __construct(
        private Connection $conn,
    ) {}

    public function isVisibleToUser(int $imageId, int $userId): bool
    {
        $forbiddenRaw = $this->conn->createQueryBuilder()
            ->select('forbidden_categories')
            ->from(Tables::userCache())
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        // No user_cache row at all for this identity -- fail closed. A
        // missing row means permissions were never computed for this user,
        // not that nothing is forbidden (see PermissionService::
        // getForbiddenCategories()'s own "at least contains 0" contract,
        // which this cache column always reflects once computed).
        if ($forbiddenRaw === false) {
            return false;
        }

        $forbidden = is_string($forbiddenRaw) ? trim($forbiddenRaw) : '';

        if ($forbidden === '' || $forbidden === '0') {
            return true; // nothing forbidden for this user -- fast accept
        }

        $forbiddenIds = array_map(intval(...), explode(',', $forbidden));

        $nb = $this->conn->createQueryBuilder()
            ->select('COUNT(*) AS nb')
            ->from(Tables::imageCategory())
            ->where('image_id = :imageId')
            ->andWhere('category_id NOT IN (:forbidden)')
            ->setParameter('imageId', $imageId)
            ->setParameter('forbidden', $forbiddenIds, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($nb) && (int) $nb !== 0;
    }
}
