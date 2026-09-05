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
 * `PUT /api/v1/session/favorites/{imageId}` --
 * `pwg.users.favorites.add`'s real replacement. `PUT`, not `POST`: an
 * idempotent "this photo is a favorite" state, matching
 * `UserService::addFavorite()`'s own `ignoreDuplicate: true` behavior.
 */
final readonly class FavoriteAddController implements ControllerInterface
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
        $imageId = ImageId::from(is_string($rawId) ? (int) $rawId : 0);

        if (! $this->imageService->existsById($imageId)) {
            return ResponseFactory::problem('Not Found', 404, 'image_id not found.');
        }

        $this->userService->addFavorite($this->currentUser->get()->id, $imageId, ignoreDuplicate: true);

        return ResponseFactory::noContent();
    }
}
