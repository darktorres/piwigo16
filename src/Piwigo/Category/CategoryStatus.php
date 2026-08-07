<?php

declare(strict_types=1);

namespace Piwigo\Category;

/**
 * Backed enum for the `categories.status` column
 * (`enum('public','private')`) -- the string case values are the exact
 * DB-stored values, so `CategoryStatus::from()` round-trips a raw row
 * read directly. Same pattern as {@see \Piwigo\Users\UserStatus}.
 * `Piwigo\Db\DbInfo::getEnums()`'s callers can read the valid-value list
 * from PHP directly instead of introspecting the database.
 */
enum CategoryStatus: string
{
    case Public = 'public';
    case Private = 'private';
}
