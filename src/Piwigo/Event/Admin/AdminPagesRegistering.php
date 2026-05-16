<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

use Piwigo\Admin\AdminPageRegistry;

/**
 * Dispatched once at admin boot, after the dependency-injection
 * container has built [[AdminPageRegistry]] but before
 * [[\Piwigo\Controller\Admin\AdminController]] dispatches the request.
 *
 * Listeners (core `CoreAdminPagesSubscriber` and any plugin subscriber)
 * call `$event->registry->register(new AdminPage(...))` to claim a
 * slug. The registry is the side-effect carrier; the event object
 * stays immutable.
 *
 * No B3-style mutable field — listeners write through the carried
 * registry instead. PSR-14 returns the same event instance after
 * dispatch but callers don't need to read anything off it; the
 * registry already holds the cumulative state.
 */
final readonly class AdminPagesRegistering
{
    public function __construct(
        public AdminPageRegistry $registry,
    ) {
    }
}
