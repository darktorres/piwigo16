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
use Piwigo\Ws\WsParamException;

/** `pwg.tags.delete` — remove one or more tags by ID. */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private TagAdminService $tagAdminService,
        private TagRepository $tagRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): DeleteResult|PwgError
    {
        try {
            $input = DeleteParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($this->tagRepository->countByIds($input->tagIds) !== count($input->tagIds)) {
            return new PwgError(WsError::InvalidParam->value, 'All tags does not exist.');
        }
        if (count($input->tagIds) > 0) {
            $this->tagAdminService->deleteTags($input->tagIds);
        }
        return new DeleteResult(deletedIds: $input->tagIds);
    }
}
