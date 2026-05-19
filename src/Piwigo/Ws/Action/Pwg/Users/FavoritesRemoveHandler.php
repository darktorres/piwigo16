<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Image\ImageRepository;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserFavoriteRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.users.favorites.remove` — unfavorite an image. */
final readonly class FavoritesRemoveHandler implements WsAction
{
    public function __construct(
        private ImageRepository $imageRepository,
        private PermissionService $permissionService,
        private UserFavoriteRepository $userFavoriteRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|true
    {
        if ($this->permissionService->isAGuest()) {
            return new PwgError(403, 'User must be logged in.');
        }
        $userId     = CurrentUser::get()->id;
        $remImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        if (!$this->imageRepository->existsById($remImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        $this->userFavoriteRepository->delete($userId, $remImageId);
        return true;
    }
}
