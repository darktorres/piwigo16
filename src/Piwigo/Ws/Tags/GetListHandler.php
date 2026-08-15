<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Override;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Tag\TagService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\XmlAttributeLists;

/**
 * `pwg.tags.getList` -- retrieves a list of available tags.
 */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private XmlAttributeLists $xmlAttributeLists,
    ) {}

    /**
     * @param array<mixed> $params
     * @return array{tags: NamedArray}
     */
    #[Override]
    public function __invoke(array $params, Server $server): array
    {
        $input = GetListParams::fromArray($params);

        $tags = $this->tagService->getAvailableTags();
        if ($input->sortByCounter) {
            usort($tags, static fn (array $a, array $b): int => $b['counter'] <=> $a['counter']);
        } else {
            usort($tags, $this->htmlRenderer->tagAlphaCompare(...));
        }

        $urlService = $this->urlService;
        for ($i = 0; $i < count($tags); $i++) {
            $tags[$i]['url'] = $urlService->makeIndexUrl(
                [
                    'section' => 'tags',
                    'tags' => [$tags[$i]],
                ]
            );
        }

        return [
            'tags' => new NamedArray(
                $tags,
                'tag',
                $this->xmlAttributeLists->stdGetTagXmlAttributes()
            ),
        ];
    }
}
