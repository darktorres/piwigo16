<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed replacement for the 3 ACTIVITY_SYSTEM_* `define()` constants.
 */
final class ActivitySystem
{
    public const int Core = 1;

    public const int Plugin = 2;

    public const int Theme = 3;
}
