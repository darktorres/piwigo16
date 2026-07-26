<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: `Piwigo\Activity\ActivityService` is L2bExtendedDomain, but
 * its own free-function delegate `pwg_activity()` (include/functions.inc.php)
 * has real L2aCoreDomain callers (`Users\UserService`, `Group\GroupService`,
 * `Auth\AuthService`) that deptrac's ruleset forbids from depending upward
 * on L2b directly. Lives in `Piwigo\Core` (L1Infrastructure, same direction
 * as `MailerInterface`) so those 3 classes can depend downward on this
 * instead of the concrete class. `ActivityService implements` it; bound in
 * `config/container.php`.
 */
interface ActivityLoggerInterface
{
    /**
     * $details is a genuinely heterogeneous per-action log payload (config
     * diffs, batch-edit field lists, install metadata, permission grants,
     * ...) -- 40+ real call sites across Admin/Ws/Controller/domain
     * services each build their own one-off shape, matching PSR-3
     * LoggerInterface's own `$context` parameter, not a single reusable
     * domain concept.
     *
     * @param int|string|array<int, int|string> $objectId
     * @param array<string, mixed> $details
     */
    public function record(string $object, int|string|array $objectId, string $action, array $details = []): void;
}
