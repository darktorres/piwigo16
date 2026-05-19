<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Category\CategoryRepository;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Filesystem;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageRepository;
use Piwigo\Tag\TagRepository;
use Piwigo\Users\UserRepository;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Psr\Cache\CacheItemPoolInterface;

/**
 * `pwg.getInfos` — gallery-wide counts (photos, albums, tags, users,
 * comments) plus cache_size. Read-only.
 */
final readonly class GetInfosHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CommentRepository $commentRepository,
        private ImageRepository $imageRepository,
        private TagRepository $tagRepository,
        private UserRepository $userRepository,
        private CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    public function __invoke(array $params, PwgServer $server): array
    {
        $infos = [];
        $infos['version']            = AppInfo::VERSION;
        $imgRepo  = $this->imageRepository;
        $catRepo  = $this->categoryRepository;
        $tagRepo  = $this->tagRepository;
        $userRepo = $this->userRepository;
        $comRepo  = $this->commentRepository;
        $infos['nb_elements']           = $imgRepo->countAll();
        $infos['nb_categories']         = $catRepo->countAll();
        $infos['nb_virtual']            = $catRepo->countVirtual();
        $infos['nb_physical']           = $catRepo->countPhysical();
        $infos['nb_image_category']     = $catRepo->countImageCategoryLinks();
        $infos['nb_tags']               = $tagRepo->countAll();
        $infos['nb_image_tag']          = $tagRepo->countImageTags();
        $infos['nb_users']              = $userRepo->countAll(Tables::users());
        $infos['nb_groups']             = $userRepo->countGroups();
        $infos['nb_comments']           = $comRepo->countAll();
        if ($infos['nb_elements'] > 0) {
            $infos['first_date'] = $imgRepo->findEarliestDate();
        }
        if ($infos['nb_comments'] > 0) {
            $infos['nb_unvalidated_comments'] = $comRepo->countUnvalidated();
        }
        $infos['cache_size'] = $this->cacheSizeWithTtl();
        $output = [];
        foreach ($infos as $name => $value) {
            $output[] = ['name' => $name, 'value' => $value];
        }
        return ['infos' => new PwgNamedArray($output, 'item')];
    }

    private function cacheSizeWithTtl(): int
    {
        $cacheKey = md5('ws_cache_size' . AppInfo::VERSION);
        $item     = $this->pool->getItem($cacheKey);
        if ($item->isHit() && is_int($item->get())) {
            return (int) $item->get();
        }
        $bytes = Filesystem::directorySizeBytes(Config::dataLocation() . 'cache') ?? 0;
        $item->set($bytes);
        $item->expiresAfter(300);
        $this->pool->save($item);
        return $bytes;
    }
}
