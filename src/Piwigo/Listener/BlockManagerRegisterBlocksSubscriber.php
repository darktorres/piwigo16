<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Event\BlockManager\BlockManagerRegisterBlocks;
use Piwigo\Html\HtmlService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Populates the menubar with Piwigo's default blocks (categories, tags,
 * recent comments, …) the first time a BlockManager loads.
 *
 * Replaces the inline closure registered in legacy CommonBootstrap at
 * NEUTRAL_PRIORITY-1, so it runs *before* plugin-provided blocks land
 * (matching legacy ordering).
 */
final readonly class BlockManagerRegisterBlocksSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HtmlService $htmlService,
    ) {
    }

    /**
     * Negative priority preserves the legacy ordering: core blocks register
     * before plugin/theme blocks so plugins can reposition or remove them.
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [BlockManagerRegisterBlocks::class => ['onRegisterBlocks', -1]];
    }

    public function onRegisterBlocks(BlockManagerRegisterBlocks $event): void
    {
        $this->htmlService->registerDefaultMenubarBlocks($event->menu);
    }
}
