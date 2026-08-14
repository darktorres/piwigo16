<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * 30s TTL -- same reasoning as PermissionsCachePool. Holds the *effective*
 * permission snapshot (forbidden categories, image access type/list, total
 * image count, and last photo date), distinct from PermissionsCachePool,
 * which only ever holds the narrower structural value. Namespace/TTL are
 * supplied by config/container.php's own factory() entry, not this class
 * -- see AbstractNamedCachePool.
 */
final class EffectivePermissionsCachePool extends AbstractNamedCachePool {}
