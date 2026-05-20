<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Config\Config;
use Piwigo\Image\OrderByService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserFavoriteRepository;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsHelper;

/** `pwg.users.favorites.getList` — paginated list of the current user's favorites. */
final readonly class FavoritesGetListHandler implements WsAction
{
    public function __construct(
        private OrderByService $orderByService,
        private PermissionService $permissionService,
        private UserFavoriteRepository $userFavoriteRepository,
        private UserService $userService,
        private WsHelper $wsHelper,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): false|array
    {
        if ($this->permissionService->isAGuest()) {
            return false;
        }
        $userId = CurrentUser::get()->id;
        $this->userService->checkUserFavorites();
        $orderBy = $this->wsHelper->imageSqlOrder($params, 'i.');
        $orderBy = empty($orderBy) ? $this->orderByService->buildOrderByClause(Config::orderBy()) : 'ORDER BY ' . $orderBy;
        $perm = $this->permissionService->getSqlConditionFandF(['visible_images' => 'id'], 'AND');
        $images = [];
        foreach ($this->userFavoriteRepository->findImagesWithDetails($userId, $perm->where, $perm->params, $perm->types, $orderBy) as $img) {
            $image = [
                'id'             => $img->id->value,
                'width'          => $img->width ?? 0,
                'height'         => $img->height ?? 0,
                'hit'            => $img->hit,
                'file'           => $img->file->value,
                'name'           => $img->name,
                'comment'        => $img->comment,
                'date_creation'  => $img->dateCreation?->value,
                'date_available' => $img->dateAvailable?->value,
            ];
            $images[] = array_merge($image, $this->wsHelper->getUrls($img->toRow()));
        }
        $favInput   = FavoritesGetListParams::fromArray($params);
        $favPerPage = $favInput->perPage;
        $favPage    = $favInput->page;
        $count      = count($images);
        $images     = array_slice($images, $favPerPage * $favPage, $favPerPage);
        return ['paging' => new PwgNamedStruct(['page' => $favPage, 'per_page' => $favPerPage, 'count' => $count]), 'images' => new PwgNamedArray($images, 'image', $this->wsHelper->getImageXmlAttributes())];
    }
}
