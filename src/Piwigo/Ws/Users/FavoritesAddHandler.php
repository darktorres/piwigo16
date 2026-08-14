<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.favorites.add` -- adds the indicated image to the current user's favorite images.
 */
final readonly class FavoritesAddHandler implements WsAction
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
    public function __invoke(array $params, Server $server): WsErrorResponse|true
    {
        $input = FavoritesAddParams::fromArray($params);

        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(403, 'User must be logged in.');
        }

        // does the image really exist?
        if (! $this->imageService->existsById(ImageId::from($input->imageId))) {
            return new WsErrorResponse(404, 'image_id not found');
        }

        $this->userService->addFavorite($this->currentUser->get()->id, $input->imageId, ignoreDuplicate: true);

        return true;
    }
}
