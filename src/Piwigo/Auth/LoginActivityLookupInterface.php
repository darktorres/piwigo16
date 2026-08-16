<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Seam {@see AuthRepository}'s own constructor type-hints against --
 * `activity` is owned by {@see \Piwigo\Activity\ActivityRepository}.
 * `Auth` originally couldn't depend on it directly back when `Activity`
 * was `L2bExtendedDomain` (`deptrac.yaml` only allows downward
 * dependencies, and `Auth` is `L2aCoreDomain`). `0.3` later moved
 * `Activity` into `L2aCoreDomain` alongside `Auth`, so this layer
 * constraint no longer applies, but the interface-seam decoupling itself
 * is still the intended shape here, not just a workaround. Implemented by
 * `ActivityRepository` itself, wired at the composition root
 * (config/container.php), same `Mail\MailRecipientRepositoryInterface`-style
 * seam already established in this codebase.
 */
interface LoginActivityLookupInterface
{
    /**
     * How many `action = 'login'` rows the activity table has for
     * $userId.
     */
    public function countLoginActivity(int $userId): int;
}
