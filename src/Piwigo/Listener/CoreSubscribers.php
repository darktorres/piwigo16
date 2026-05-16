<?php

declare(strict_types=1);

namespace Piwigo\Listener;

/**
 * Roster of in-tree event subscribers wired into the typed dispatcher at
 * container build. Plugins (B7+) register their own subscribers via
 * PluginRegistry::activate(); both core and plugin paths funnel through
 * Symfony's EventDispatcher::addSubscriber().
 *
 * Adding a new core subscriber: implement EventSubscriberInterface in
 * src/Piwigo/Listener/, list its FQCN here, and let PHP-DI autowire it.
 */
final class CoreSubscribers
{
    /** @var list<class-string> */
    public const array ALL = [
        TryLogUserSubscriber::class,
        WsInvokeAllowedSubscriber::class,
        RenderElementContentSubscriber::class,
        RenderElementDescriptionSubscriber::class,
        RenderCommentContentSubscriber::class,
        RenderCommentAuthorSubscriber::class,
        RenderCategoryDescriptionSubscriber::class,
        RenderCategoryLiteralDescriptionSubscriber::class,
        RenderTagUrlSubscriber::class,
        GetSrcImageUrlSubscriber::class,
        GetElementUrlSubscriber::class,
        BlockManagerRegisterBlocksSubscriber::class,
        TabsheetBeforeSelectSubscriber::class,
        NbmRenderGlobalCustomizeMailContentSubscriber::class,
    ];
}
