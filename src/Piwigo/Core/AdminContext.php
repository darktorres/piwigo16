<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * "This request is dispatched through admin.php/admin/popuphelp.php"
 * marker -- typed replacement for the raw IN_ADMIN constant
 * (`defined('IN_ADMIN')`/bare `IN_ADMIN` reads). SEC-60 forbids define()
 * inside src/Piwigo, so the constant stays confined to the 2 entry-shell
 * files that set it; everything else reads this instead.
 *
 * Container-shared, immutable value: the value is fixed once, at
 * container-build time (`Piwigo\Core\Container`'s own build() method,
 * threaded from `public/admin.php`/`public/admin/popuphelp.php`, the 2
 * entry-shell files that know they're really being dispatched through the
 * admin area), never mutated afterward during a request -- no "current
 * instance" concept needed at all. `Page\PageHeaderRenderer`/`Bootstrap\
 * RedirectService` (both deliberately have no constructor at all,
 * early-crash-fallback shape) resolve this via their own private lazy
 * helpers instead.
 */
final readonly class AdminContext
{
    public function __construct(
        private bool $active = false,
    ) {}

    public function isActive(): bool
    {
        return $this->active;
    }
}
