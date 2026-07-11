<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Backed enum for the origin `user_infos.status` column
 * (`enum('webmaster','admin','normal','generic','guest')`) -- the string
 * case values are the exact DB-stored values, so `UserStatus::from()`
 * round-trips a raw row read directly. First class in the new `Users`
 * namespace (already pre-declared in deptrac.yaml's L2aCoreDomain since
 * P6); real domain migration is P17-23, this enum only exists now because
 * `User`/`CurrentUser` (P16) need a typed status property to compile.
 */
enum UserStatus: string
{
    case Webmaster = 'webmaster';
    case Admin = 'admin';
    case Normal = 'normal';
    case Generic = 'generic';
    case Guest = 'guest';
}
