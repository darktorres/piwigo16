<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Users;

/**
 * Why UserService::checkAndSaveUserInfos() rejected a
 * UserInfoUpdateInput -- a domain-shaped classification, not a transport
 * status code. Callers translate this to whatever their own transport
 * needs (e.g. the WS layer maps InvalidInput/Forbidden onto its own
 * WsError::InvalidParam/403).
 */
enum UserInfoUpdateFailureReason
{
    case InvalidInput;
    case Forbidden;
}
