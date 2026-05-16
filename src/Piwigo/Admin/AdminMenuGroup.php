<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Top-level admin sidebar grouping for an [[AdminPage]].
 *
 * The case set follows the legacy admin sidebar layout so existing
 * templates can switch over without remapping. Plugins register their
 * own pages under one of these — there is no public "plugin-defined
 * group" mechanism because the sidebar template only has slots for the
 * predefined cases.
 */
enum AdminMenuGroup: string
{
    case Albums        = 'albums';
    case Photos        = 'photos';
    case Users         = 'users';
    case Configuration = 'configuration';
    case Plugins       = 'plugins';
    case Themes        = 'themes';
    case Tools         = 'tools';
    case Misc          = 'misc';
}
