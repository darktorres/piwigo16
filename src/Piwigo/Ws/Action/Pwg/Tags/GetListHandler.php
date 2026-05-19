<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Html\HtmlService;
use Piwigo\Tag\TagService;
use Piwigo\Url\UrlService;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsHelper;

/** `pwg.tags.getList` — public-facing tag list, sorted by name or count. */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private HtmlService $htmlService,
        private TagService $tagService,
        private UrlService $urlService,
        private WsHelper $wsHelper,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): array
    {
        /** @var array<int, array<string, mixed>> $tags */
        $tags = $this->tagService->getAvailableTags();
        if ($params['sort_by_counter']) {
            usort($tags, fn (array $a, array $b): int => (is_numeric($b['counter'] ?? null) ? (int) $b['counter'] : 0) - (is_numeric($a['counter'] ?? null) ? (int) $a['counter'] : 0));
        } else {
            usort($tags, $this->htmlService->tagAlphaCompare(...));
        }
        for ($i = 0; $i < count($tags); $i++) {
            $tagIdRaw            = $tags[$i]['id'] ?? null;
            $tags[$i]['id']      = is_numeric($tagIdRaw) ? (int) $tagIdRaw : 0;
            $tagCounterRaw       = $tags[$i]['counter'] ?? null;
            $tags[$i]['counter'] = is_numeric($tagCounterRaw) ? (int) $tagCounterRaw : 0;
            $tags[$i]['url']     = $this->urlService->makeIndexUrl(['section' => 'tags', 'tags' => [$tags[$i]]]);
        }
        return ['tags' => new PwgNamedArray($tags, 'tag', $this->wsHelper->getTagXmlAttributes())];
    }
}
