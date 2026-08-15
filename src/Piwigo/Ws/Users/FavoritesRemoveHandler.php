<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.favorites.remove` -- removes the indicated image from the current user's favorite images.
 */
final readonly class FavoritesRemoveHandler implements WsAction
{
    public function __construct(
        private AccessControl $accessControl,
        private ImageService $imageService,
        private UserService $userService,
        private CurrentUser $currentUser,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|true
    {
        $input = FavoritesRemoveParams::fromArray($params);

        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(403, 'User must be logged in.');
        }

        // does the image really exist?
        if (! $this->imageService->existsById(ImageId::from($input->imageId))) {
            return new WsErrorResponse(404, 'image_id not found');
        }

        $this->userService->removeFavorite($this->currentUser->get()->id, $input->imageId);

        return true;
    }
}
