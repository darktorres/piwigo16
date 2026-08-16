<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Override;
use Piwigo\Category\Event\RenderCategoryDescription;
use Piwigo\Category\Event\RenderCategoryLiteralDescription;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\StringHelper;
use Piwigo\Core\SubscriberInterface;
use Piwigo\Html\Event\RenderCommentContent;
use Piwigo\Html\HtmlService;
use Piwigo\Image\Event\GetSrcImageUrl;
use Piwigo\Menu\Event\BlockManagerRegisterBlocks;
use Piwigo\Picture\Event\GetElementUrl;
use Piwigo\Picture\Event\RenderCommentAuthor;
use Piwigo\Tag\Event\RenderTagUrl;

/**
 * Extracted from `Bootstrap\RequestBootstrap::finalize()`'s own default
 * "how does the page render this content" handlers -- all `HtmlService`-
 * backed or thin adapter closures over unrelated pure helpers (today's
 * own comments: those closures exist because one method can't be typed
 * for two different event classes, not because they need `HtmlService`).
 *
 * `subscribedEvents()` is called on an already-constructed, already-
 * injected instance (unlike `PluginConfig\ExtensionInterface`'s own
 * version, which runs on a bare `new $class()` with nothing injected --
 * see that interface's own docblock), so it reads `$this->currentConfig`
 * directly to decide which of `RenderCategoryDescription`/
 * `GetElementUrl`/`GetSrcImageUrl` to subscribe to at all -- real
 * conditional registration, not a no-op-in-the-handler workaround.
 */
final readonly class HtmlRenderingListener implements SubscriberInterface
{
    public function __construct(
        private HtmlService $htmlService,
        private CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function subscribedEvents(): array
    {
        $events = [
            RenderCategoryLiteralDescription::class => $this->onRenderCategoryLiteralDescription(...),
            RenderCommentContent::class => $this->onRenderCommentContent(...),
            RenderCommentAuthor::class => $this->onRenderCommentAuthor(...),
            RenderTagUrl::class => $this->onRenderTagUrl(...),
            BlockManagerRegisterBlocks::class => $this->onBlockManagerRegisterBlocks(...),
        ];

        if (! $this->currentConfig->allowHtmlDescriptions) {
            $events[RenderCategoryDescription::class] = $this->onRenderCategoryDescription(...);
        }

        if ($this->currentConfig->originalUrlProtection !== '') {
            $events[GetElementUrl::class] = $this->onGetElementUrl(...);
            $events[GetSrcImageUrl::class] = $this->onGetSrcImageUrl(...);
        }

        return $events;
    }

    public function onRenderCategoryLiteralDescription(RenderCategoryLiteralDescription $event): RenderCategoryLiteralDescription
    {
        return $this->htmlService->renderCategoryLiteralDescription($event);
    }

    /**
     * pwgNl2br() is a generic string transform reused by
     * RenderElementDescription's own default handler too -- a thin
     * adapter, leaving pwgNl2br() itself untouched, since one method
     * can't be typed for two different event classes.
     */
    public function onRenderCategoryDescription(RenderCategoryDescription $event): RenderCategoryDescription
    {
        $result = $this->htmlService->pwgNl2br($event->categoryDescription);
        $event->categoryDescription = is_string($result) ? $result : null;

        return $event;
    }

    public function onRenderCommentContent(RenderCommentContent $event): RenderCommentContent
    {
        return $this->htmlService->renderCommentContent($event);
    }

    /**
     * 'strip_tags' is PHP's own native function -- can't be retyped, so
     * this is a thin adapter, same reasoning as pwgNl2br() above.
     */
    public function onRenderCommentAuthor(RenderCommentAuthor $event): RenderCommentAuthor
    {
        $event->commentAuthor = strip_tags($event->commentAuthor);

        return $event;
    }

    /**
     * StringHelper::str2url() is called directly from 6+ unrelated
     * production sites -- a thin adapter keeps its own signature
     * untouched, same reasoning as pwgNl2br()/strip_tags() above.
     */
    public function onRenderTagUrl(RenderTagUrl $event): RenderTagUrl
    {
        $event->tagName = StringHelper::str2url($event->tagName);

        return $event;
    }

    public function onBlockManagerRegisterBlocks(BlockManagerRegisterBlocks $event): void
    {
        $this->htmlService->registerDefaultMenubarBlocks($event);
    }

    public function onGetElementUrl(GetElementUrl $event): GetElementUrl
    {
        return $this->htmlService->getElementUrlProtectionHandler($event);
    }

    public function onGetSrcImageUrl(GetSrcImageUrl $event): GetSrcImageUrl
    {
        return $this->htmlService->getSrcImageUrlProtectionHandler($event);
    }
}
