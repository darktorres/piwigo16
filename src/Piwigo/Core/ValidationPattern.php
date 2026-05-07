<?php

declare(strict_types=1);

namespace Piwigo\Core;

final class ValidationPattern
{
    const ID    = '/^\d+$/';
    const ORDER = '/^(rand(om)?|[a-z_]+(\s+(asc|desc))?)(\s*,\s*(rand(om)?|[a-z_]+(\s+(asc|desc))?))*$/i';
}
