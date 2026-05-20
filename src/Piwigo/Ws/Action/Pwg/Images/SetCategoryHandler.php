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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = SetCategoryParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $scCategoryId = $input->categoryId;
        $scImageIds   = $input->imageIds;
        if (!$this->categoryRepository->existsById($scCategoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        if ($input->action === 'associate') {
            $this->categoryAdminService->associateImagesToCategories($scImageIds, [$scCategoryId]);
        } elseif ($input->action === 'dissociate') {
            $this->categoryAdminService->dissociateImagesFromCategory($scImageIds, (string) $scCategoryId);
        } elseif ($input->action === 'move') {
            $this->categoryAdminService->moveImagesToCategories($scImageIds, [$scCategoryId]);
        }
        $this->userAdminService->invalidateUserCache();
        return null;
    }
}
