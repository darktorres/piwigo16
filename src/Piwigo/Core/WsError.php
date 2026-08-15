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
 * genuinely references `WsError::INVALID_PARAM` directly for its own
 * WS-shaped error arrays, and L2aCoreDomain may not depend on
 * L4Integration. That coupling is the same untyped-boundary issue Stage 1
 * step 2's obstacle 2 tracks; this stays put until that's resolved.
 */
final class WsError
{
    public const int INVALID_METHOD = 501;

    public const int MISSING_PARAM = 1002;

    public const int INVALID_PARAM = 1003;
}
