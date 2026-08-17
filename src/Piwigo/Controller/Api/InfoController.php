<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Core\AppInfo;
use Piwigo\Group\GroupService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Piwigo\Tag\TagService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/info` -- `pwg.getInfos`'s real replacement, admin only
 * (same gate as its WS predecessor). A fresh implementation rather than a
 * shared extraction: the WS handler's own `NamedArray` `{name, value}`
 * list is a wire-format artifact of the old XML-first envelope (P27
 * Locked Decision D3 -- "correct JSON types" at the source, not a
 * name/value list a JSON client has to fold back into an object itself).
 */
final readonly class InfoController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private ImageService $imageService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private UserService $userService,
        private GroupService $groupService,
        private CommentService $commentService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $nbElements = $this->imageService->getTotalImageCount();
        $nbComments = $this->commentService->countAll();

        $body = [
            'version' => AppInfo::VERSION,
            'nbElements' => $nbElements,
            'nbCategories' => $this->categoryService->countAllCategories(),
            'nbVirtual' => $this->categoryService->countByDirNull(true),
            'nbPhysical' => $this->categoryService->countByDirNull(false),
            'nbImageCategory' => $this->imageService->getImageCategoryLinkCount(),
            'nbTags' => $this->tagService->countAll(),
            'nbImageTag' => $this->tagService->countAllImageTagLinks(),
            'nbUsers' => $this->userService->getTotalUserCount(),
            'nbGroups' => $this->groupService->countAll(),
            'nbComments' => $nbComments,
            'firstDate' => $nbElements > 0 ? $this->imageService->getMinDateAvailable() : null,
            'nbUnvalidatedComments' => $nbComments > 0 ? $this->commentService->countUnvalidated() : null,
            // Real cache size needs a `du` shell-out (GET /api/v1/cache-size,
            // not this endpoint -- same reasoning as GetInfosHandler's own
            // identical omission, too expensive to pay on every call here).
            'cacheSize' => null,
        ];

        return ResponseFactory::json($body);
    }
}
