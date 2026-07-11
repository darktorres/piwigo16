<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed replacement for the 6 ACCESS_* `define()` constants. Plain typed
 * class constants, not a backed enum -- matches the reference's real,
 * stable, final choice: these values compare/store as raw ints throughout
 * legacy code (e.g. `$level >= ACCESS_ADMINISTRATOR`), which is more
 * natural without `->value` unwrapping at every comparison site.
 */
final class AccessLevel
{
    public const int Free = 0;

    public const int Guest = 1;

    public const int Classic = 2;

    public const int Administrator = 3;

    public const int Webmaster = 4;

    public const int Closed = 5;
}
