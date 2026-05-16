<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Config\Config;
use Piwigo\Event\Picture\GetElementUrl;
use Piwigo\Html\HtmlService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rewrites element URLs through HtmlService::getElementUrlProtectionHandler
 * when the gallery has original-URL protection enabled.
 *
 * Replaces the legacy v16 misfire at CommonBootstrap.php:313 that registered
 * a listener for the literal string 'UrlService::get()->getElementUrl' —
 * which no dispatch site ever emits. The intent was clearly to filter
 * the `get_element_url` event; this subscriber implements that intent
 * correctly. The global protection-handler delegate was deleted in
 * be344673b without rewiring; this subscriber calls HtmlService directly.
 */
final readonly class GetElementUrlSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HtmlService $htmlService,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [GetElementUrl::class => 'onGetElementUrl'];
    }

    public function onGetElementUrl(GetElementUrl $event): void
    {
        if (empty(Config::originalUrlProtection())) {
            return;
        }
        $event->url = $this->htmlService->getElementUrlProtectionHandler($event->url, $event->elementInfo);
    }
}
