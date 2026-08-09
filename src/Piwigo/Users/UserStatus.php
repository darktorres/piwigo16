<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Backed enum for the origin `user_infos.status` column
 * (`enum('webmaster','admin','normal','generic','guest')`) -- the string
 * case values are the exact DB-stored values, so `UserStatus::from()`
 * round-trips a raw row read directly. Exists because
 * `User`/`CurrentUser` need a typed status property.
 */
enum UserStatus: string
{
    case Webmaster = 'webmaster';
    case Admin = 'admin';
    case Normal = 'normal';
    case Generic = 'generic';
    case Guest = 'guest';
}
