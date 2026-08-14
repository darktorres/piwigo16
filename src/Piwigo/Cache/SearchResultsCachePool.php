<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * 30s TTL -- same reasoning as PermissionsCachePool. Used by
 * Search\SearchFilterRenderer's several filter-row/count caches and
 * Search\SearchService::getQuickSearchResults(): a uniform 30s TTL, not a
 * longer "search content changes slowly" value, since invalidation here
 * is really about permission changes, not content staleness.
 * Namespace/TTL are supplied by config/container.php's own factory()
 * entry, not this class -- see AbstractNamedCachePool.
 */
final class SearchResultsCachePool extends AbstractNamedCachePool {}
