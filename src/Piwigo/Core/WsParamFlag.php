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
 * P23 batch 8e: relocated from include/ws_core.inc.php's WS_PARAM_*
 * define()s -- the 'flags' half of PwgServer::addMethod()'s per-param
 * options (WsParamType is the separate 'type' half). Lives at
 * L1Infrastructure alongside WsParamType/WsError; see WsParamType's own
 * docblock for why (Piwigo\Users\UserService needs to reach these too).
 * FORCE_ARRAY's value deliberately includes ACCEPT_ARRAY's bit (0x030000
 * = 0x010000 | 0x020000) -- PwgServer::hasFlag() checks are bitwise, so
 * this preserves "forcing array implies accepting array" exactly as the
 * original int literals did.
 */
final class WsParamFlag
{
    public const int ACCEPT_ARRAY = 0x010000;

    public const int FORCE_ARRAY = 0x030000;

    public const int OPTIONAL = 0x040000;
}
