<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Event\Picture\RenderElementContent;
use Piwigo\Picture\PictureContentRenderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Provides the default picture-page body when no plugin/theme has already
 * supplied content. Delegates to PictureContentRenderer::defaultContent,
 * which assigns derivative variants to the template and parses the
 * picture_content.latte fragment.
 *
 * Restores legacy registration:
 *   EventDispatcher::addListener('render_element_content',
 *       PictureContentRenderer::defaultContent(...));
 * which was wired inside PictureController::__invoke() — moved here to
 * boot-time registration so every render_element_content dispatch is
 * uniformly handled.
 */
final readonly class RenderElementContentSubscriber implements EventSubscriberInterface
{
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [RenderElementContent::class => 'onRenderElementContent'];
    }

    public function onRenderElementContent(RenderElementContent $event): void
    {
        $event->content = PictureContentRenderer::defaultContent($event->content, $event->currentPicture);
    }
}
