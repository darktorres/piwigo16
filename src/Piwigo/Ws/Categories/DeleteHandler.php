<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.categories.delete` -- admin only. Deletes album(s).
 * `photo_deletion_mode` can be "no_delete" (may create orphan photos),
 * "delete_orphans" (default mode, only deletes photos linked to no
 * other album) or "force_delete" (delete all photos, even those linked
 * to other albums).
 */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private UrlServiceInterface $urlService,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): ?WsErrorResponse
    {
        $input = DeleteParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $modes = ['no_delete', 'delete_orphans', 'force_delete'];
        if (! in_array($input->photoDeletionMode, $modes, true)) {
            return new WsErrorResponse(
                500,
                '[ws_categories_delete]'
      . ' invalid parameter photo_deletion_mode "' . $input->photoDeletionMode . '"'
      . ', possible values are {' . implode(', ', $modes) . '}.'
            );
        }

        $category_ids = $input->categoryIds;

        if (count($category_ids) === 0) {
            return null;
        }

        $category_ids = $this->categoryService->getExistingIds($category_ids);

        if (count($category_ids) === 0) {
            return null;
        }

        $categoryService = $this->categoryService;
        $categoryService->deleteCategories(
            $category_ids,
            $this->activityService,
            $this->urlService,
            $this->sessionService,
            $this->eventDispatcher,
            $this->entityManager,
            new PermalinkRepository($this->entityManager),
            $input->photoDeletionMode
        );
        $categoryService->updateGlobalRank();
        PermissionCacheInvalidator::invalidate();

        return null;
    }
}
