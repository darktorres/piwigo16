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
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.users.favorites.getList` -- returns the favorite images of the current user.
 */
final readonly class FavoritesGetListHandler implements WsAction
{
    public function __construct(
        private AccessControl $accessControl,
        private UserService $userService,
        private CurrentUser $currentUser,
        private PermissionService $permissionService,
        private UrlServiceInterface $urlService,
        private CurrentConfig $currentConfig,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     * @return false|array{paging: NamedStruct, images: NamedArray}
     */
    public function __invoke(array $params, Server $server): false|array
    {
        if ($this->accessControl->isAGuest()) {
            return false;
        }

        $input = FavoritesGetListParams::fromArray($params);

        $this->userService->checkUserFavorites();

        // MethodDefinition's own registration for this method includes
        // 'order', so Server::invoke()'s generic validation guarantees
        // this exact shape before __invoke() ever runs -- see
        // Categories\GetImagesHandler's own identical comment.
        /** @var array{order: string|null, ...} $orderParams */
        $orderParams = $params;

        $order_by = $this->wsHelper->stdImageSqlOrder($orderParams, 'i.');
        $order_by = $order_by === '' ? $this->currentConfig->orderBy : 'ORDER BY ' . $order_by;

        $permission_condition = $this->permissionService->getPermissionCriteria();

        $images = [];
        foreach ($this->userService->getVisibleFavoriteImages($this->currentUser->get()->id, $permission_condition, $order_by) as $row) {
            $image = [];

            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                }
            }

            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k] ?? null;
            }

            $images[] = array_merge($image, $this->wsHelper->stdGetUrls($row, $this->urlService));
        }

        $count = count($images);
        $images = array_slice($images, $input->perPage * $input->page, $input->perPage);

        return [
            'paging' => new NamedStruct(
                [
                    'page' => $input->page,
                    'per_page' => $input->perPage,
                    'count' => $count,
                ]
            ),
            'images' => new NamedArray(
                $images,
                'image',
                $this->wsHelper->stdGetImageXmlAttributes()
            ),
        ];
    }
}
