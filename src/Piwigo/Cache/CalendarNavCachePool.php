<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * 30s TTL -- same reasoning as PermissionsCachePool. Used by
 * Calendar\CalendarRenderer's calendar navigation-bar cache. Namespace/TTL
 * are supplied by config/container.php's own factory() entry, not this
 * class -- see AbstractNamedCachePool.
 */
final class CalendarNavCachePool extends AbstractNamedCachePool {}
