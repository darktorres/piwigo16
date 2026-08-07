<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Core;

/**
 * Lives in `Piwigo\Core` (L1Infrastructure) alongside `WsParamType` and
 * `WsParamFlag`; see `WsParamType`'s own docblock for why
 * (`Piwigo\Users\UserService` needs to reach these values too).
 */
final class WsError
{
    public const int INVALID_METHOD = 501;

    public const int MISSING_PARAM = 1002;

    public const int INVALID_PARAM = 1003;
}
