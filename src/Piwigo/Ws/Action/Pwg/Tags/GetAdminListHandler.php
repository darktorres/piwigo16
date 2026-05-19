<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Tag\TagService;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsHelper;

/** `pwg.tags.getAdminList` — full tag list (admin only). */
final readonly class GetAdminListHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
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
        return ['tags' => new PwgNamedArray($this->tagService->getAllTags(), 'tag', $this->wsHelper->getTagXmlAttributes())];
    }
}
