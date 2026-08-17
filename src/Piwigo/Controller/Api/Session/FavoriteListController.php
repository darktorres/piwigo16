<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Ws\ImageUrlBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/session/favorites` -- `pwg.users.favorites.getList`'s
 * real replacement.
 */
final readonly class FavoriteListController implements ControllerInterface
{
    public function __construct(
        private AccessControl $accessControl,
        private UserService $userService,
        private CurrentUser $currentUser,
        private PermissionService $permissionService,
        private UrlServiceInterface $urlService,
        private ImageUrlBuilder $imageUrlBuilder,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->accessControl->isAGuest()) {
            return ResponseFactory::problem('Unauthorized', 401, 'Access denied.');
        }

        $query = $request->getQueryParams();
        $perPage = isset($query['perPage']) && is_numeric($query['perPage']) ? (int) $query['perPage'] : 100;
        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 0;
        $order = is_string($query['order'] ?? null) ? $query['order'] : '';

        $this->userService->checkUserFavorites();

        $orderBy = PhotoSortOrder::fromWsOrderParam($order);

        $images = [];
        foreach ($this->userService->getVisibleFavoriteImages(
            $this->currentUser->get()
                ->id,
            $this->permissionService->getPermissionCriteria(),
            $orderBy->isEmpty() ? null : $orderBy
        ) as $row) {
            $images[] = array_merge(
                [
                    'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                    'width' => $row['width'] ?? null,
                    'height' => $row['height'] ?? null,
                    'hit' => $row['hit'] ?? null,
                    'file' => $row['file'] ?? null,
                    'name' => $row['name'] ?? null,
                    'comment' => $row['comment'] ?? null,
                    'dateCreation' => $row['date_creation'] ?? null,
                    'dateAvailable' => $row['date_available'] ?? null,
                ],
                $this->imageUrlBuilder->stdGetUrls($row, $this->urlService)
            );
        }

        $count = count($images);
        $images = array_slice($images, $perPage * $page, $perPage);

        return ResponseFactory::json([
            'images' => $images,
            'page' => $page,
            'perPage' => $perPage,
            'totalCount' => $count,
        ]);
    }
}
