<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Config\Config;
use Piwigo\Event\Picture\GetSrcImageUrl;
use Piwigo\Html\HtmlService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rewrites src image URLs through HtmlService::getSrcImageUrlProtectionHandler
 * when the gallery has original-URL protection enabled.
 *
 * Restores legacy v16 conditional registration:
 *   if (!empty(Config::originalUrlProtection())) {
 *       EventDispatcher::addListener('get_src_image_url',
 *           'get_src_image_url_protection_handler');
 *   }
 * The condition moves from registration time to dispatch time because
 * Symfony subscribers register once at boot. The global delegate was
 * deleted in be344673b without rewiring.
 */
final readonly class GetSrcImageUrlSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HtmlService $htmlService,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [GetSrcImageUrl::class => 'onGetSrcImageUrl'];
    }

    public function onGetSrcImageUrl(GetSrcImageUrl $event): void
    {
        if (empty(Config::originalUrlProtection())) {
            return;
        }
        $event->url = $this->htmlService->getSrcImageUrlProtectionHandler($event->url, $event->value);
    }
}
