<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * 300s TTL -- per-user per-album counts, cheaper to hold slightly stale
 * than to recompute the `COUNT(*) ... GROUP BY category_id` rollup on
 * every category listing. Not to be confused with
 * Piwigo\Category\CategoryTreeCache -- that's the domain-level consumer
 * of this pool, not this pool itself. Namespace/TTL are supplied by
 * config/container.php's own factory() entry, not this class -- see
 * AbstractNamedCachePool.
 */
final class CategoryTreeCachePool extends AbstractNamedCachePool {}
