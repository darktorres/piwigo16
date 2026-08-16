<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

/**
 * Moved here from `Piwigo\Core` (P25 Stage 1 step 5, 2026-08-16), alongside
 * its former neighbors `WsParamType`/`WsParamFlag`. It used to stay in
 * `Piwigo\Core` (L1Infrastructure) because `Piwigo\Users\
 * UserService::checkAndSaveUserInfos()` (L2aCoreDomain) referenced
 * `WsError::InvalidParam` directly for its own WS-shaped error arrays,
 * which would have put an L2aCoreDomain class depending on L4Integration.
 * Standalone item A (the P25 plan) rewrote that method around
 * `UserInfoUpdateResult`/`UserInfoUpdateFailureReason` instead, removing
 * the coupling -- this move just hadn't been revisited until now.
 */
enum WsError: int
{
    case InvalidMethod = 501;

    case MissingParam = 1002;

    case InvalidParam = 1003;
}
