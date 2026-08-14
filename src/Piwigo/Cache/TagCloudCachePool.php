<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * 300s TTL -- same reasoning as CategoryTreeCachePool. Real consumer:
 * Piwigo\Tag\TagService::getAvailableTags(), which uses this pool instead
 * of PersistentFileCache/CurrentPersistentCache. Namespace/TTL are
 * supplied by config/container.php's own factory() entry, not this class
 * -- see AbstractNamedCachePool.
 */
final class TagCloudCachePool extends AbstractNamedCachePool {}
