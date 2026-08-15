<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Ws\Event\WsInvokeAllowed;

/**
 * The WS layer's own method-invocation security check, split out of the
 * former WsHelper god-class (P25 Stage 1 step 6). Wired as the
 * WsInvokeAllowed event handler by WsInitializer.
 */
final readonly class WsInvokeAuthorizer
{
    public function __construct(
        private AccessControl $accessControl,
    ) {}

    /**
     * Event handler for method invocation security check. Sets $event->value
     * to a WsErrorResponse if the preconditions are not satisfied for method
     * invocation.
     */
    public function isInvokeAllowed(WsInvokeAllowed $event): WsInvokeAllowed
    {
        if (str_starts_with($event->methodName, 'reflection.')) { // OK for reflection
            return $event;
        }

        if (! $this->accessControl->isAuthorizeStatus(AccessLevel::Guest) and
            ! str_starts_with($event->methodName, 'pwg.session.')) {
            $event->value = new WsErrorResponse(401, 'Access denied');
            return $event;
        }

        return $event;
    }
}
