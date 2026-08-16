<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Core\AppInfo;
use Piwigo\Group\GroupService;
use Piwigo\Image\ImageService;
use Piwigo\Tag\TagService;
use Piwigo\Users\UserService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\WsAction;

/**
 * `pwg.getInfos` -- admin only. Returns general informations about the installation.
 */
final readonly class GetInfosHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private UserService $userService,
        private GroupService $groupService,
        private CommentService $commentService,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array{infos: NamedArray}
     */
    #[Override]
    public function __invoke(array $params): array
    {
        $infos = [];
        $infos['version'] = AppInfo::VERSION;

        $infos['nb_elements'] = $this->imageService->getTotalImageCount();
        $infos['nb_categories'] = $this->categoryService->countAllCategories();
        $infos['nb_virtual'] = $this->categoryService->countByDirNull(true);
        $infos['nb_physical'] = $this->categoryService->countByDirNull(false);
        $infos['nb_image_category'] = $this->imageService->getImageCategoryLinkCount();
        $infos['nb_tags'] = $this->tagService->countAll();
        $infos['nb_image_tag'] = $this->tagService->countAllImageTagLinks();
        $infos['nb_users'] = $this->userService->getTotalUserCount();
        $infos['nb_groups'] = $this->groupService->countAll();
        $infos['nb_comments'] = $this->commentService->countAll();

        // first element
        if ($infos['nb_elements'] > 0) {
            $infos['first_date'] = $this->imageService->getMinDateAvailable();
        }

        // unvalidated comments
        if ($infos['nb_comments'] > 0) {
            $infos['nb_unvalidated_comments'] = $this->commentService->countUnvalidated();
        }

        // Cache size: not computed here on purpose. A real answer means
        // shelling out to `du` (see GetCacheSizeHandler, the real
        // pwg.getCacheSize API method) -- too expensive to pay on every
        // pwg.getInfos call, and exec() isn't guaranteed to be enabled.
        // null (not a fake number) matches GetCacheSizeHandler's own
        // sentinel for "couldn't determine size"; callers that need the
        // real value should call pwg.getCacheSize directly.
        $infos['cache_size'] = null;

        $output = [];
        foreach ($infos as $name => $value) {
            $output[] = [
                'name' => $name,
                'value' => $value,
            ];
        }
        return [
            'infos' => new NamedArray($output, 'item'),
        ];
    }
}
