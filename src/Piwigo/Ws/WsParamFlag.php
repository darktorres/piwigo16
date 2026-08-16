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
 * The 'flags' half of a `ParamDefinition`'s per-param options
 * (`WsParamType` is the separate 'type' half). P25 Stage 1 step 5: moved
 * here from `Piwigo\Core` alongside `WsParamType` -- see that class's
 * own docblock for why (`WsError` is the one of the three that's
 * genuinely still blocked from moving).
 *
 * `FORCE_ARRAY`'s value deliberately includes `ACCEPT_ARRAY`'s bit
 * (0x030000 = 0x010000 | 0x020000): `Server::hasFlag()` checks are
 * bitwise, so this ensures "forcing array" implies "accepting array".
 */
final class WsParamFlag
{
    public const int ACCEPT_ARRAY = 0x010000;

    public const int FORCE_ARRAY = 0x030000;

    public const int OPTIONAL = 0x040000;
}
