<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.setCategory` — associate / dissociate / move bulk images. */
final readonly class SetCategoryHandler implements WsAction
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private CsrfService $csrfService,
        private UserAdminService $userAdminService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $scCategoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $scImageIds   = is_array($params['image_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
        if (!$this->categoryRepository->existsById($scCategoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        $scAction = is_string($params['action'] ?? null) ? $params['action'] : '';
        if ($scAction === 'associate') {
            $this->categoryAdminService->associateImagesToCategories($scImageIds, [$scCategoryId]);
        } elseif ($scAction === 'dissociate') {
            $this->categoryAdminService->dissociateImagesFromCategory($scImageIds, (string) $scCategoryId);
        } elseif ($scAction === 'move') {
            $this->categoryAdminService->moveImagesToCategories($scImageIds, [$scCategoryId]);
        }
        $this->userAdminService->invalidateUserCache();
        return null;
    }
}
