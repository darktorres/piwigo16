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
use Piwigo\Tag\TagService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\XmlAttributeLists;

/**
 * `pwg.tags.getAdminList` -- admin only. Returns the list of tags as you
 * can see them in administration. Permissions are not taken into
 * account.
 */
final readonly class GetAdminListHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
        private HtmlRenderingInterface $htmlRenderer,
        private XmlAttributeLists $xmlAttributeLists,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array{tags: NamedArray}
     */
    #[Override]
    public function __invoke(array $params, Server $server): array
    {
        return [
            'tags' => new NamedArray(
                $this->tagService->getAllTags($this->htmlRenderer),
                'tag',
                $this->xmlAttributeLists->stdGetTagXmlAttributes()
            ),
        ];
    }
}
