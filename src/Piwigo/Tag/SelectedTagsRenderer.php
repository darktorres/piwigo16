<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;

final class SelectedTagsRenderer
{
    public function render(): void
    {
        $template = TemplateRegistry::current();
        $pageRaw = $GLOBALS['page'] ?? [];
        $pageTagsRaw = is_array($pageRaw) ? ($pageRaw['tags'] ?? []) : [];
        $pageTags = is_array($pageTagsRaw) ? $pageTagsRaw : [];

        $selected_related_tags_info = [];

        foreach ($pageTags as $key => $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $other_tags = $pageTags;
            unset($other_tags[$key]);

            $tagName = is_string($tag['name'] ?? null) ? $tag['name'] : '';
            $selected_related_tags_info[$key] = [
                'tag_name' => EventDispatcher::dispatch('render_tag_name', $tagName, $tag),
                'item_count' => '',
                'index_url' => UrlService::get()->makeIndexUrl(['tags' => [$tag]]),
                'remove_url' => UrlService::get()->makeIndexUrl(['tags' => $other_tags]),
            ];
        }

        $template->assign(['SELECT_RELATED_TAGS' => $selected_related_tags_info]);
        $template->setFilename('selected_tags', 'include/selected_tags.inc.latte');
        $template->assignVarFromHandle('SELECTED_TAGS_TEMPLATE', 'selected_tags');
    }
}
