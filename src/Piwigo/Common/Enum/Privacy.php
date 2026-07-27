<?php

declare(strict_types=1);

namespace Piwigo\Common\Enum;

/**
 * Visibility of a category — matches the `categories.status` ENUM column
 * (`'public'` | `'private'`).
 *
 * Public categories are visible to anyone with gallery access; private
 * categories are visible only to users explicitly granted access via
 * `user_access` / `group_access`.
 */
enum Privacy: string
{
    case Public = 'public';
    case Private = 'private';
}
