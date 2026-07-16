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
 * P23 batch 8e: relocated from include/ws_core.inc.php's WS_TYPE_*
 * define()s. Lives at L1Infrastructure (Piwigo\Core), not Piwigo\Ws
 * (L4Integration), because Piwigo\Users\UserService::checkAndSaveUserInfos()
 * (L2aCoreDomain) already references these values directly for its
 * WS-shaped error arrays -- L2aCoreDomain may not depend on L4Integration,
 * but every layer may depend on L1Infrastructure.
 */
final class WsParamType
{
    public const int BOOL = 0x01;

    public const int INT = 0x02;

    public const int FLOAT = 0x04;

    public const int POSITIVE = 0x10;

    public const int NOTNULL = 0x20;

    public const int ID = self::INT | self::POSITIVE | self::NOTNULL;
}
