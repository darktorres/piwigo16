<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class SelectedTagsRenderer
{
    public function __construct(
        private UrlService $urlService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function render(): void
    {
        $template = TemplateRegistry::current();
        $pageTags = SectionContextRegistry::current()->tags;

        $selected_related_tags_info = [];

        foreach ($pageTags as $key => $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $other_tags = $pageTags;
            unset($other_tags[$key]);

            $tagName = is_string($tag['name'] ?? null) ? $tag['name'] : '';
            $renderEvent = new RenderTagName($tagName, $tag);
            $this->dispatcher->dispatch($renderEvent);
            $selected_related_tags_info[$key] = [
                'tag_name' => $renderEvent->tagName,
                'item_count' => '',
                'index_url' => $this->urlService->makeIndexUrl(['tags' => [$tag]]),
                'remove_url' => $this->urlService->makeIndexUrl(['tags' => $other_tags]),
            ];
        }

        $template->assign(['SELECT_RELATED_TAGS' => $selected_related_tags_info]);
        $template->assignVarFromTemplate('SELECTED_TAGS_TEMPLATE', 'include/selected_tags.inc.latte');
    }
}
