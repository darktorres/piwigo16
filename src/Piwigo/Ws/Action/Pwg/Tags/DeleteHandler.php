<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Tag\TagRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.tags.delete` — remove one or more tags by ID. */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private TagAdminService $tagAdminService,
        private TagRepository $tagRepository,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $tagIdsRaw = is_array($params['tag_id']) ? $params['tag_id'] : [];
        /** @var int[] $tagIdsDel */
        $tagIdsDel = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $tagIdsRaw);
        if ($this->tagRepository->countByIds($tagIdsDel) !== count($tagIdsDel)) {
            return new PwgError(WsError::InvalidParam->value, 'All tags does not exist.');
        }
        if (count($tagIdsDel) > 0) {
            $this->tagAdminService->deleteTags($tagIdsDel);
            return ['id' => $tagIdsDel];
        }
        return ['id' => []];
    }
}
