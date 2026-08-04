<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

/**
 * Backed enum for the origin `plugins.state` column
 * (`enum('inactive','active')`) -- the string case values are the exact
 * DB-stored values. A real, closed, core-defined 2-value set (no
 * plugin-widening mechanism the way `history.section` has), safe for the
 * same `enumType` treatment as {@see \Piwigo\Category\CategoryStatus}/
 * {@see \Piwigo\Users\UserStatus}.
 */
enum PluginState: string
{
    case Inactive = 'inactive';
    case Active = 'active';
}
