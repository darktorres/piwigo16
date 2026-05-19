<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.categories.delete` — delete albums; photo_deletion_mode controls orphan handling. */
final readonly class DeleteHandler implements WsAction
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
            $input = DeleteParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(500, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($input->categoryIds === []) {
            return null;
        }
        $rawCategoryIds = $this->categoryRepository->findExistingIdsAmong($input->categoryIds);
        if (count($rawCategoryIds) === 0) {
            return null;
        }
        $this->categoryAdminService->deleteCategories($rawCategoryIds, $input->photoDeletionMode);
        $this->categoryAdminService->updateGlobalRank();
        $this->userAdminService->invalidateUserCache();
        return null;
    }
}
