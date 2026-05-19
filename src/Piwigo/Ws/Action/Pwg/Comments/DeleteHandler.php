<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Comments;

use Piwigo\Comment\CommentService;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.comments.delete` — admin bulk-delete user comments. */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private CommentService $commentService,
        private CsrfService $csrfService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|string
    {
        try {
            $input = DeleteParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }
        $this->commentService->deleteUserComment($input->commentIds);
        return 'Comment successfully deleted';
    }
}
