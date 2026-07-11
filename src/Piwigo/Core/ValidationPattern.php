<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed replacement for the 2 PATTERN_* `define()` constants.
 */
final class ValidationPattern
{
    public const string ID = '/^\d+$/';

    public const string ORDER = '/^(rand(om)?|[a-z_]+(\s+(asc|desc))?)(\s*,\s*(rand(om)?|[a-z_]+(\s+(asc|desc))?))*$/i';
}
