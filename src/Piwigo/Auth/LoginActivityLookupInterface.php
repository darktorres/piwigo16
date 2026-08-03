<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Seam {@see AuthRepository}'s own constructor type-hints against --
 * `activity` is owned by {@see \Piwigo\Activity\ActivityRepository}
 * (`Activity`, `L2bExtendedDomain`), and `Auth` (`L2aCoreDomain`) can't
 * depend on it directly (`deptrac.yaml` only allows downward
 * dependencies). Implemented by `ActivityRepository` itself, wired at the
 * composition root (config/container.php), same
 * `Mail\MailRecipientRepositoryInterface`-style seam already established
 * in this codebase.
 */
interface LoginActivityLookupInterface
{
    /**
     * How many `action = 'login'` rows the activity table has for
     * $userId.
     */
    public function countLoginActivity(int $userId): int;
}
