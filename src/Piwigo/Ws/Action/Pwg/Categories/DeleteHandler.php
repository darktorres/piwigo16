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
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $photoDeletionMode = is_string($params['photo_deletion_mode'] ?? null) ? $params['photo_deletion_mode'] : '';
        $modes             = ['no_delete', 'delete_orphans', 'force_delete'];
        if (!in_array($photoDeletionMode, $modes)) {
            return new PwgError(500, '[ws_categories_delete] invalid parameter photo_deletion_mode "' . $photoDeletionMode . '", possible values are {' . implode(', ', $modes) . '}.');
        }
        if (!is_array($params['category_id'])) {
            $splitResult           = preg_split('/[\s,;\|]/', is_string($params['category_id']) ? $params['category_id'] : '', -1, PREG_SPLIT_NO_EMPTY);
            $params['category_id'] = $splitResult !== false ? $splitResult : [];
        }
        $params['category_id'] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['category_id']);
        $categoryIds           = array_filter($params['category_id'], fn (int $v): bool => $v > 0);
        if (count($categoryIds) === 0) {
            return null;
        }
        $rawCategoryIds = $this->categoryRepository->findExistingIdsAmong(array_values($categoryIds));
        if (count($rawCategoryIds) === 0) {
            return null;
        }
        $this->categoryAdminService->deleteCategories($rawCategoryIds, $photoDeletionMode);
        $this->categoryAdminService->updateGlobalRank();
        $this->userAdminService->invalidateUserCache();
        return null;
    }
}
