<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Comments;

use Override;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/comments/actions/validate` --
 * `pwg.userComments.validate`'s real replacement, admin + CSRF. Bulk by
 * design.
 */
final readonly class CommentValidateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CommentService $commentService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $input = CommentIdsInput::fromArray(JsonBody::decode($request));
        if ($input->commentIds === []) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'No comment id given.');
        }

        $this->commentService->validateComment(array_map(CommentId::from(...), $input->commentIds));

        return ResponseFactory::json([
            'validated' => true,
            'ids' => $input->commentIds,
        ]);
    }
}
