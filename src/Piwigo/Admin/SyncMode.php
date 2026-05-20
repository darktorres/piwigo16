<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Synchronisation kind for the admin "synchronise" form — file
 * metadata only, or full directory rescan.
 */
enum SyncMode: string
{
    case Files = 'files';
    case Dirs  = 'dirs';
}
