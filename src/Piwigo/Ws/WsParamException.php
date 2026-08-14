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
 * Thrown by a `WsParams::fromArray()` factory when the raw request
 * payload doesn't satisfy the method's contract. `Server::invoke()`
 * catches this centrally and converts it to a 403 `WsErrorResponse`,
 * matching the response code every hand-written guard in the god-classes
 * already used for this failure shape.
 */
final class WsParamException extends \RuntimeException {}
