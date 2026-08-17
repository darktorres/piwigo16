<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Seam {@see AuthRepository}'s own constructor type-hints against --
 * `activity` is owned by {@see \Piwigo\Activity\ActivityRepository}. This
 * interface-seam decoupling is the intended shape here, not a
 * layer-constraint workaround: `Auth` and `Activity` are both
 * `L2aCoreDomain`. Implemented by `ActivityRepository` itself, wired at
 * the composition root (config/container.php), same `Mail\
 * MailRecipientRepositoryInterface`-style seam already established in
 * this codebase.
 */
interface LoginActivityLookupInterface
{
    /**
     * How many `action = 'login'` rows the activity table has for
     * $userId.
     */
    public function countLoginActivity(int $userId): int;
}
