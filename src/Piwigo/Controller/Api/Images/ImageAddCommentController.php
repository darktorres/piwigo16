<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/{id}/comments` -- `pwg.images.addComment`'s real
 * replacement. Public, permission-filtered (no `AdminGuard`), same as
 * its WS predecessor. `key` is the ephemeral anti-spam token
 * `GET /api/v1/images/{id}`'s own `commentPost.key` field hands out.
 */
final readonly class ImageAddCommentController implements ControllerInterface
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private PermissionService $permissionService,
        private ImageService $imageService,
        private CommentService $commentService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->currentConfig->activateComments) {
            return ResponseFactory::problem('Forbidden', 403, 'Comments are disabled.');
        }

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $imageId = is_string($rawId) ? (int) $rawId : 0;

        if (! $this->imageService->isImageCommentableWithCondition(ImageId::from($imageId), $this->permissionService->getPermissionCriteria())) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid image id.');
        }

        $input = ImageAddCommentInput::fromArray(JsonBody::decode($request));

        $comm = [
            'author' => trim($input->author),
            'content' => trim($input->content),
            'image_id' => $imageId,
        ];

        $infos = [];
        $commentAction = $this->commentService->insertComment($comm, $input->key, $infos);

        return match ($commentAction) {
            'reject' => ResponseFactory::problem(
                'Forbidden',
                403,
                'Your comment has NOT been registered because it did not pass the validation rules. ' . implode('; ', $infos)
            ),
            'validate', 'moderate' => ResponseFactory::json([
                'id' => $comm['id'] ?? 0,
                'validated' => $commentAction === 'validate',
            ], 201),
            default => ResponseFactory::problem('Internal Server Error', 500, 'Unknown comment action ' . $commentAction . '.'),
        };
    }
}
