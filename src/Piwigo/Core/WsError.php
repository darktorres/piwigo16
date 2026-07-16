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
 * P23 batch 8e: relocated from include/ws_core.inc.php's WS_ERR_*
 * define()s. Lives at L1Infrastructure alongside WsParamType/WsParamFlag;
 * see WsParamType's own docblock for why (Piwigo\Users\UserService needs
 * to reach these too).
 */
final class WsError
{
    public const int INVALID_METHOD = 501;

    public const int MISSING_PARAM = 1002;

    public const int INVALID_PARAM = 1003;
}
