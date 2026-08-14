<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * 30s TTL -- short enough that a permission change (revoking a category,
 * editing a group) becomes visible well within one user session, long
 * enough to avoid recomputing PermissionService::getForbiddenCategories()'s
 * multi-query calculation on every single request for the same user.
 * Namespace/TTL are supplied by config/container.php's own factory()
 * entry, not this class -- see AbstractNamedCachePool.
 */
final class PermissionsCachePool extends AbstractNamedCachePool {}
