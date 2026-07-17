<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Piwigo\Db\MysqliDb;

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
 * permissions live on the fast path -- implemented directly against the
 * legacy mysqli layer the i.php fast bootstrap has already connected
 * (deliberately NOT PermissionService, whose live recomputation and DI
 * graph are exactly what this fast path must avoid).
 */
final class ImageVisibilityChecker
{
    public function __construct(
        private readonly string $prefixeTable,
    ) {}

    public function isVisibleToUser(int $imageId, int $userId): bool
    {
        $query = '
SELECT forbidden_categories
  FROM ' . $this->prefixeTable . 'user_cache
  WHERE user_id = ' . $userId . '
;';
        $cacheRow = MysqliDb::fetchAssoc(MysqliDb::query($query));

        // No user_cache row at all for this identity -- fail closed. A
        // missing row means permissions were never computed for this user,
        // not that nothing is forbidden (see PermissionService::
        // getForbiddenCategories()'s own "at least contains 0" contract,
        // which this cache column always reflects once computed).
        if (! is_array($cacheRow)) {
            return false;
        }

        $forbidden = $cacheRow['forbidden_categories'] ?? null;
        $forbidden = is_string($forbidden) ? trim($forbidden) : '';

        if ($forbidden === '' || $forbidden === '0') {
            return true; // nothing forbidden for this user -- fast accept
        }

        $query = '
SELECT COUNT(*) AS nb
  FROM ' . $this->prefixeTable . 'image_category
  WHERE image_id = ' . $imageId . '
    AND category_id NOT IN (' . $forbidden . ')
;';
        $countRow = MysqliDb::fetchAssoc(MysqliDb::query($query));
        $nb = is_array($countRow) && is_numeric($countRow['nb'] ?? null) ? (int) $countRow['nb'] : 0;

        return $nb !== 0;
    }
}
