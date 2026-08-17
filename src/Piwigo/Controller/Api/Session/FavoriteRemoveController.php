<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/v1/session/favorites/{imageId}` --
 * `pwg.users.favorites.remove`'s real replacement.
 */
final readonly class FavoriteRemoveController implements ControllerInterface
{
    public function __construct(
        private AccessControl $accessControl,
        private ImageService $imageService,
        private UserService $userService,
        private CurrentUser $currentUser,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->accessControl->isAGuest()) {
            return ResponseFactory::problem('Forbidden', 403, 'User must be logged in.');
        }

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['imageId'] ?? null) : null;
        $imageId = is_string($rawId) ? (int) $rawId : 0;

        if (! $this->imageService->existsById(ImageId::from($imageId))) {
            return ResponseFactory::problem('Not Found', 404, 'image_id not found.');
        }

        $this->userService->removeFavorite($this->currentUser->get()->id, $imageId);

        return ResponseFactory::noContent();
    }
}
