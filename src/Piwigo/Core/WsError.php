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
 * Stays in `Piwigo\Core` (L1Infrastructure) rather than moving to
 * `Piwigo\Ws` alongside its former neighbors `WsParamType`/`WsParamFlag`
 * (P25 Stage 1 step 5) -- not because anything still needs it to, but
 * because nothing has needed it to move there yet.
 * `Piwigo\Users\UserService::checkAndSaveUserInfos()` (L2aCoreDomain) used
 * to reference `WsError::InvalidParam` directly for its own WS-shaped
 * error arrays, which would have put an L2aCoreDomain class depending on
 * L4Integration -- standalone item A (the P25 plan) rewrote that method
 * around `UserInfoUpdateResult`/`UserInfoUpdateFailureReason` instead, so
 * the coupling this docblock used to describe no longer exists. The move
 * itself just hasn't been revisited since.
 */
enum WsError: int
{
    case InvalidMethod = 501;

    case MissingParam = 1002;

    case InvalidParam = 1003;
}
