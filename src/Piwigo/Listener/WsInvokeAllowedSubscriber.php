<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Event\Ws\WsInvokeAllowed;
use Piwigo\Ws\WsHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Default permission gate for WS method invocation. Calls
 * WsHelper::isInvokeAllowed which returns either `true` (allowed) or a
 * PwgError (blocked); the subscriber writes that back into $event->value.
 *
 * Legacy registered this in PwgServer:
 *   EventDispatcher::addListener('ws_invoke_allowed',
 *       static fn ($res, $methodName, $params) =>
 *           Kernel::service(WsHelper::class)->isInvokeAllowed($res, $methodName, $params),
 *       EventDispatcher::NEUTRAL_PRIORITY);
 */
final readonly class WsInvokeAllowedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private WsHelper $wsHelper,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [WsInvokeAllowed::class => 'onWsInvokeAllowed'];
    }

    public function onWsInvokeAllowed(WsInvokeAllowed $event): void
    {
        $result = $this->wsHelper->isInvokeAllowed($event->value, $event->methodName, $event->params);
        if (is_bool($result) || $result instanceof \Piwigo\Ws\PwgError) {
            $event->value = $result;
        }
    }
}
