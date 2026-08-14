<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * 30s TTL -- same reasoning as PermissionsCachePool. Used by
 * Notification\NotificationService::getRecentPostDates(). Namespace/TTL
 * are supplied by config/container.php's own factory() entry, not this
 * class -- see AbstractNamedCachePool.
 */
final class NotificationsCachePool extends AbstractNamedCachePool {}
