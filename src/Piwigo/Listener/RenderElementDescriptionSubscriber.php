<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Html\HtmlService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies HtmlService::pwgNl2br to element descriptions.
 *
 * Restores legacy v16 PictureController-time registration:
 *   EventDispatcher::addListener('render_element_description', 'pwg_nl2br');
 * The legacy site registered this unconditionally during PictureController
 * boot. The subscriber moves it to boot-time registration so every
 * dispatch site (HtmlService, CategoriesEndpoints, ...) sees consistent
 * behavior, not just picture pages.
 */
final readonly class RenderElementDescriptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HtmlService $htmlService,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [RenderElementDescription::class => 'onRenderElementDescription'];
    }

    public function onRenderElementDescription(RenderElementDescription $event): void
    {
        $event->elementDescription = $this->htmlService->pwgNl2br($event->elementDescription);
    }
}
