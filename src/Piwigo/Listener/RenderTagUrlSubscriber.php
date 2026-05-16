<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Core\StringUtil;
use Piwigo\Event\Tag\RenderTagUrl;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restores the str2url URL-slug transform for the render_tag_url event.
 *
 * Legacy v16 registered this via EventDispatcher::addListener('render_tag_url',
 * 'str2url') in CommonBootstrap. Our procedural-shell cleanup at be344673b
 * deleted the global str2url() delegate without rewiring this listener,
 * leaving the event silently no-op. This subscriber restores the documented
 * behavior by calling StringUtil::str2url() directly.
 */
final class RenderTagUrlSubscriber implements EventSubscriberInterface
{
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [RenderTagUrl::class => 'onRenderTagUrl'];
    }

    public function onRenderTagUrl(RenderTagUrl $event): void
    {
        $event->tagName = StringUtil::str2url($event->tagName);
    }
}
