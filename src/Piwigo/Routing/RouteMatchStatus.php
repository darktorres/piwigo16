<?php

declare(strict_types=1);

namespace Piwigo\Routing;

enum RouteMatchStatus
{
    case Found;
    case NotFound;
    case MethodNotAllowed;
}
