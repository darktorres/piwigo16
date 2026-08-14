<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * 30s TTL -- same reasoning as PermissionsCachePool. Holds
 * Section\SectionPopulator's per-user visible image-id list for a
 * section. Namespace/TTL are supplied by config/container.php's own
 * factory() entry, not this class -- see AbstractNamedCachePool.
 */
final class SectionImageIdsCachePool extends AbstractNamedCachePool {}
