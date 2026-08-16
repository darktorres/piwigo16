<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\Users\CurrentUser;

/**
 * Extracted from CommentService::getNbAvailableComments() -- this
 * counter only ever needs CurrentUser/AccessLevelChecker/PermissionService,
 * never CommentService's other collaborators (Lang/CommentRepository/
 * EphemeralKeyService/MailerInterface/HtmlRenderingInterface/
 * UrlServiceInterface/EventDispatcher/PageState). Depending on this
 * class instead avoids forcing MenubarRenderer::render() -- its one real
 * caller, a method with no constructor of its own -- to gather and pass
 * through those unused params, purely to satisfy CommentService's
 * constructor. Same interface-segregation reasoning as
 * AccessLevelChecker's own extraction from AccessControl.
 */
final readonly class AvailableCommentsCounter
{
    public function __construct(
        private CurrentUser $currentUser,
        private AccessLevelChecker $accessLevelChecker,
    ) {}

    public function count(PermissionService $permissionService, EntityManagerInterface $entityManager): int
    {
        $currentUser = $this->currentUser->get();

        if (! isset($currentUser->rawAttributes['nb_available_comments'])) {
            // countAvailableWithConditions() is real DQL -- condition
            // fragments reference DQL property paths
            // (com.validated/ic.category/ic.image), not raw column names,
            // same convention already established throughout the codebase
            // (e.g. Tag\TagRepository's own PermissionCriteria consumers).
            // ic.category/ic.image are bare owning-side association paths
            // (ImageCategoryEntity), not the old scalar categoryId/imageId
            // columns -- resolve straight to the join column in a WHERE
            // fragment, same as everywhere else in the association-
            // modeling item.
            $where = [];
            if (! $this->accessLevelChecker->isAdmin()) {
                $where[] = new SqlCondition('com.validated = true');
            }
            $permissionCriteria = $permissionService->getPermissionCriteria();
            $where[] = $permissionCriteria->forbiddenCategoriesCondition('ic.category');
            $where[] = $permissionCriteria->imageAccessCondition('ic.image');

            $nbAvailableComments = $entityManager->getRepository(CommentEntity::class)->countAvailableWithConditions($where);
            $currentUser = $currentUser->withRawAttribute('nb_available_comments', $nbAvailableComments);
            $this->currentUser->set($currentUser);
        }
        $nb_available_comments = $currentUser->rawAttributes['nb_available_comments'];
        return is_numeric($nb_available_comments) ? (int) $nb_available_comments : 0;
    }
}
