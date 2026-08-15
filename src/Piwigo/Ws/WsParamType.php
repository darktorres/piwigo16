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
 * P25 Stage 1 step 5: moved here from `Piwigo\Core` -- despite the
 * `Piwigo\Users\UserService::checkAndSaveUserInfos()`-needs-it rationale
 * its old docblock gave (shared with `WsParamFlag`/`WsError`), that
 * method only ever references `WsError` directly; every real
 * `WsParamType`/`WsParamFlag` reference outside a docblock comment
 * already lived in `Piwigo\Ws\*`. `WsError` itself stays in
 * `Piwigo\Core` for now -- `checkAndSaveUserInfos()`'s real dependency
 * on it is the same untyped-boundary issue Stage 1 step 2's obstacle 2
 * tracks.
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
