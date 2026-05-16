<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Event\Template\RenderCategoryLiteralDescription;
use Piwigo\Html\HtmlService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Delegates to HtmlService::renderCategoryLiteralDescription for the
 * plain-text category description rendering (used in feeds, page titles,
 * etc.).
 *
 * Restores legacy chain: addListener('render_category_literal_description',
 * 'render_category_literal_description') -> global delegate ->
 * HtmlService::renderCategoryLiteralDescription(). The global delegate
 * was deleted in be344673b without rewiring.
 */
final readonly class RenderCategoryLiteralDescriptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HtmlService $htmlService,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [RenderCategoryLiteralDescription::class => 'onRenderCategoryLiteralDescription'];
    }

    public function onRenderCategoryLiteralDescription(RenderCategoryLiteralDescription $event): void
    {
        $event->description = $this->htmlService->renderCategoryLiteralDescription($event->description);
    }
}
