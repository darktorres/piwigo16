<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Comments;

use Piwigo\Comment\CommentService;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.comments.delete` — admin bulk-delete user comments. */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private CommentService $commentService,
        private CsrfService $csrfService,
    ) {
    }

    /** @param array<mixed> $params */
    public function __invoke(array $params, PwgServer $server): PwgError|string
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }
        $rawIds     = is_array($params['comment_id']) ? $params['comment_id'] : [];
        $strIds     = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $rawIds);
        $commentIds = array_map(fn (string $v): int => (int) $v, array_unique($strIds));
        $this->commentService->deleteUserComment($commentIds);
        return 'Comment successfully deleted';
    }
}
