<?php

declare(strict_types=1);

namespace Piwigo\Cache\Event;

/**
 * Typed marker event for the legacy `invalidate_user_cache` notification.
 * The legacy hook carried a `bool $full` flag (`TRUNCATE` vs. an
 * `UPDATE ... need_update` partial invalidation); `PermissionCacheInvalidator::
 * invalidate()` has no such distinction anymore -- it always does a full
 * PSR-6 pool clear -- so this carries no payload rather than a `$full`
 * that would always be `true` and never actually vary.
 */
final readonly class InvalidateUserCache {}
