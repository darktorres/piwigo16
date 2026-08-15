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
 * Stays in `Piwigo\Core` (L1Infrastructure), unlike its former neighbors
 * `WsParamType`/`WsParamFlag` (moved to `Piwigo\Ws` in P25 Stage 1 step 5)
 * -- `Piwigo\Users\UserService::checkAndSaveUserInfos()` (L2aCoreDomain)
 * genuinely references `WsError::InvalidParam` directly for its own
 * WS-shaped error arrays, and L2aCoreDomain may not depend on
 * L4Integration. That coupling is the same untyped-boundary issue Stage 1
 * step 2's obstacle 2 tracks; this stays put until that's resolved.
 */
enum WsError: int
{
    case InvalidMethod = 501;

    case MissingParam = 1002;

    case InvalidParam = 1003;
}
