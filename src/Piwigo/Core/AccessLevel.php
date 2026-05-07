<?php

declare(strict_types=1);

namespace Piwigo\Core;

final class AccessLevel
{
    const Free          = 0;
    const Guest         = 1;
    const Classic       = 2;
    const Administrator = 3;
    const Webmaster     = 4;
    const Closed        = 5;
}
