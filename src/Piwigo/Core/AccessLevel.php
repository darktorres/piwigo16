<?php

declare(strict_types=1);

namespace Piwigo\Core;

final class AccessLevel
{
    public const int Free          = 0;
    public const int Guest         = 1;
    public const int Classic       = 2;
    public const int Administrator = 3;
    public const int Webmaster     = 4;
    public const int Closed        = 5;
}
