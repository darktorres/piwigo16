<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Config\Config;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Html\HtmlService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies HtmlService::pwgNl2br to category descriptions unless the gallery
 * is configured to allow raw HTML in descriptions.
 *
 * Restores legacy v16 conditional registration:
 *   if (!Config::allowHtmlDescriptions()) {
 *       EventDispatcher::addListener('render_category_description', 'pwg_nl2br');
 *   }
 * The condition moves from registration time to dispatch time because
 * Symfony subscribers register once at boot.
 */
final readonly class RenderCategoryDescriptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HtmlService $htmlService,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [RenderCategoryDescription::class => 'onRenderCategoryDescription'];
    }

    public function onRenderCategoryDescription(RenderCategoryDescription $event): void
    {
        if (Config::allowHtmlDescriptions()) {
            return;
        }
        $event->categoryDescription = $this->htmlService->pwgNl2br($event->categoryDescription);
    }
}
