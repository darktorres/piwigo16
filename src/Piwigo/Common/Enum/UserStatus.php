<?php

declare(strict_types=1);

namespace Piwigo\Common\Enum;

/**
 * Piwigo user status — the role-like marker stored in `users.status`.
 *
 * Five cases, matching the historical string set. Distinct from
 * `AccessLevel` (an integer ranking used by the permission layer);
 * `UserStatus` is the persisted attribute, `AccessLevel` is the derived
 * privilege bucket.
 *
 * @see \Piwigo\Core\AccessLevel
 */
enum UserStatus: string
{
    case Webmaster = 'webmaster';
    case Admin     = 'admin';
    case Normal    = 'normal';
    case Generic   = 'generic';
    case Guest     = 'guest';
}
