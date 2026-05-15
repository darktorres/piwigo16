<?php

declare(strict_types=1);

namespace Piwigo\Activity;

/**
 * Identifies the kind of thing an {@see ActivityEvent} touches. The string
 * value is what gets persisted in `activity.object` (a MySQL ENUM column),
 * so renaming a case is a schema migration.
 */
enum ActivityObject: string
{
    case Album  = 'album';
    case Group  = 'group';
    case Photo  = 'photo';
    case System = 'system';
    case Tag    = 'tag';
    case User   = 'user';
}
