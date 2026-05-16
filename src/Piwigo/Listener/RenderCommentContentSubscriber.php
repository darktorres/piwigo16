<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Html\HtmlService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Delegates to HtmlService::renderCommentContent for the comment-body
 * rendering (markup normalisation, link auto-detection, etc.).
 *
 * Restores legacy chain: addListener('render_comment_content',
 * 'render_comment_content') -> global render_comment_content() ->
 * HtmlService::renderCommentContent(). Our be344673b deleted the global
 * delegate without rewiring; this subscriber calls HtmlService directly.
 */
final readonly class RenderCommentContentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HtmlService $htmlService,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [RenderCommentContent::class => 'onRenderCommentContent'];
    }

    public function onRenderCommentContent(RenderCommentContent $event): void
    {
        $rendered = $this->htmlService->renderCommentContent($event->commentContent);
        $event->commentContent = $rendered ?? $event->commentContent;
    }
}
