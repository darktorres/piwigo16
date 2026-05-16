<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Event\Template\RenderCommentAuthor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sanitizes the rendered comment-author display name by stripping HTML tags.
 *
 * Restores legacy v16 EventDispatcher::addListener('render_comment_author',
 * 'strip_tags'). strip_tags is a PHP builtin so this didn't survive our
 * functions.inc.php removal as a "missing function" — it was always
 * functional in the legacy chain — but the typed migration moves it onto
 * the subscriber path for consistency with the other Render* listeners.
 */
final readonly class RenderCommentAuthorSubscriber implements EventSubscriberInterface
{
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [RenderCommentAuthor::class => 'onRenderCommentAuthor'];
    }

    public function onRenderCommentAuthor(RenderCommentAuthor $event): void
    {
        $event->commentAuthor = strip_tags($event->commentAuthor);
    }
}
