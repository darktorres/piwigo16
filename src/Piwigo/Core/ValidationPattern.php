<?php

declare(strict_types=1);

namespace Piwigo\Core;

final class ValidationPattern
{
    public const string ID    = '/^\d+$/';
    public const string ORDER = '/^(rand(om)?|[a-z_]+(\s+(asc|desc))?)(\s*,\s*(rand(om)?|[a-z_]+(\s+(asc|desc))?))*$/i';
}
