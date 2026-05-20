<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

/**
 * Lifecycle state of a plugin. The first two cases (`Active`/`Inactive`)
 * are persisted in the `plugins.state` DB enum column; the latter two
 * are synthesised by the admin UX when a plugin is present on disk but
 * not yet inserted into the table (`Uninstalled`), or when it's about
 * to be installed for the first time (`New`).
 *
 * Same pattern as Section's `ListView` case — the enum is the union
 * of persisted + synthesised values.
 */
enum PluginState: string
{
    case Active      = 'active';
    case Inactive    = 'inactive';
    case Uninstalled = 'uninstalled';
    case New         = 'new';
}
