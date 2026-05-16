<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Config\Config;
use Piwigo\Event\Mail\NbmRenderGlobalCustomizeMailContent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Default renderer for the global "customize mail content" block in the
 * NBM (Notification by Mail) admin form. HTML-safe escapes plain-text
 * content for HTML emails; leaves pre-formatted HTML alone.
 *
 * Replaces the inline MiscController::renderGlobalCustomizeMailContent
 * method that legacy registered via addListener at controller-invoke time.
 * The logic is a pure transform with no controller-state dependency, so
 * moving it into a subscriber removes the registration-time coupling.
 */
final readonly class NbmRenderGlobalCustomizeMailContentSubscriber implements EventSubscriberInterface
{
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [NbmRenderGlobalCustomizeMailContent::class => 'onRenderGlobalCustomizeMailContent'];
    }

    public function onRenderGlobalCustomizeMailContent(NbmRenderGlobalCustomizeMailContent $event): void
    {
        $content = $event->customizeMailContent;
        if (Config::nbmSendHtmlMail() && !str_starts_with($content, '<')) {
            $event->customizeMailContent = nl2br(htmlspecialchars($content));
        }
    }
}
