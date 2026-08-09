<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use LogicException;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\Users\CurrentUser;

/**
 * Extracted from CommentService::getNbAvailableComments() -- this
 * counter only ever needs CurrentUser/CurrentConfig/AccessLevelChecker,
 * never CommentService's other 8 collaborators (Lang/CommentRepository/
 * EphemeralKeyService/MailerInterface/HtmlRenderingInterface/
 * UrlServiceInterface/EventDispatcher/PageState). Depending on this
 * class instead avoids forcing MenubarRenderer::render() -- its one real
 * caller, a method with no constructor of its own -- to gather and pass
 * through 8 params it has no other use for, purely to satisfy
 * CommentService's constructor. Same interface-segregation reasoning as
 * AccessLevelChecker's own extraction from AccessControl.
 */
final class AvailableCommentsCounter
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly CurrentConfig $currentConfig,
        private readonly AccessLevelChecker $accessLevelChecker,
    ) {}

    public function count(): int
    {
        $currentUser = $this->currentUser->get();

        if (! isset($currentUser->rawAttributes['nb_available_comments'])) {
            // countAvailableWithConditions() is real DQL -- condition
            // fragments reference DQL property paths
            // (com.validated/ic.categoryId/ic.imageId), not raw column
            // names, same convention already established throughout the
            // codebase (e.g. Tag\TagRepository's own PermissionCriteria
            // consumers).
            $where = [];
            if (! $this->accessLevelChecker->isAdmin()) {
                $where[] = new SqlCondition('com.validated = true');
            }
            $permissionCriteria = new PermissionService(new PermissionRepository(EntityManagerFactory::build(DbConnection::build())), EntityManagerFactory::build(DbConnection::build())->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build(DbConnection::build()), $this->currentConfig), $this->currentUser, $this->filterState(), $this->accessLevelChecker)
                ->getPermissionCriteria();
            $where[] = $permissionCriteria->forbiddenCategoriesCondition('ic.categoryId');
            $where[] = $permissionCriteria->imageAccessCondition('ic.imageId');

            $nbAvailableComments = EntityManagerFactory::build(DbConnection::build())->getRepository(CommentEntity::class)->countAvailableWithConditions($where);
            $currentUser = $currentUser->withRawAttribute('nb_available_comments', $nbAvailableComments);
            $this->currentUser->set($currentUser);
        }
        $nb_available_comments = $currentUser->rawAttributes['nb_available_comments'];
        return is_numeric($nb_available_comments) ? (int) $nb_available_comments : 0;
    }

    /**
     * Container resolve, not a constructor property -- PermissionService's
     * own required FilterState collaborator, needed only inside count()'s
     * own PermissionService construction above. Falls back to a fresh,
     * uninitialised instance when Kernel::boot() hasn't run, matching
     * FilterState's own former isInitializedStatic() shim's identical
     * pre-boot fallback.
     */
    private function filterState(): FilterState
    {
        if (Kernel::isBooted()) {
            $filterState = Kernel::container()->get(FilterState::class);
            if (! $filterState instanceof FilterState) {
                throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
            }

            return $filterState;
        }

        return new FilterState();
    }
}
