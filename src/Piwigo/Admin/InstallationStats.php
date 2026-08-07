<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Category\CategoryService;
use Piwigo\Group\GroupService;
use Piwigo\History\HistoryService;
use Piwigo\Image\ImageService;
use Piwigo\Rate\RateService;
use Piwigo\Tag\TagService;
use Piwigo\Users\UserService;

/**
 * Ported from admin/include/functions.php's get_pwg_general_statitics()/
 * get_installation_date(). Lives under Admin\, not a domain namespace --
 * its query set cuts across images/categories/tags/users/groups/rates/
 * history, no single domain owns it, same "administrative machinery"
 * shape as PiwigoInfosSender/PluginLoader in this same namespace.
 */
final class InstallationStats
{
    public function __construct(
        private RateService $rateService,
        private HistoryService $historyService,
        private ImageService $imageService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private UserService $userService,
        private GroupService $groupService,
    ) {}

    /**
     * @return array{nb_photos: int, nb_categories: int, nb_tags: int,
     *   nb_image_tag: int, nb_users: int, nb_admins: int, nb_groups: int,
     *   nb_rates: int, nb_views: int, disk_usage: int, nb_formats: int,
     *   formats_disk_usage: int}
     */
    public function getGeneralStatistics(): array
    {
        $nb_photos = $this->imageService->getTotalImageCount();
        $nb_categories = $this->categoryService->countAllCategories();
        $nb_tags = $this->tagService->countAll();
        $nb_image_tag = $this->tagService->countAllImageTagLinks();
        $nb_users = $this->userService->getTotalUserCount();
        $nb_admins = count($this->userService->getAdminIds());
        $nb_groups = $this->groupService->countAll();
        $nb_rates = $this->rateService->countAll();
        $nb_views = $this->historyService->getTotalPageViews();
        $images_disk_usage = $this->imageService->getTotalFilesize();
        $format_stats = $this->imageService->getFormatCountAndSize();
        $nb_formats = $format_stats['count'];
        $formats_disk_usage = $format_stats['sum'];

        return [
            'nb_photos' => $nb_photos,
            'nb_categories' => $nb_categories,
            'nb_tags' => $nb_tags,
            'nb_image_tag' => $nb_image_tag,
            'nb_users' => $nb_users,
            'nb_admins' => $nb_admins,
            'nb_groups' => $nb_groups,
            'nb_rates' => $nb_rates,
            'nb_views' => $nb_views,
            'disk_usage' => $images_disk_usage + $formats_disk_usage,
            'nb_formats' => $nb_formats,
            'formats_disk_usage' => $formats_disk_usage,
        ];
    }

    /**
     * registration_date/min_registration_date/date_available are all
     * DATETIME columns, so every real $candidate assignment below is
     * string|null (this driver's fetch convention for a DATETIME column,
     * same as e.g. Category\Projection\Category::$lastmodified) --
     * narrowed to a real ?string at each assignment rather than trusting
     * that blindly.
     */
    public function getInstallationDate(): ?string
    {
        $candidate = null;

        // Piwigo first beta versions were created in septembre 2001, so it's not possible
        // to have an installation prior to this "origin of times"
        $piwigo_origins = '2001-09-01 00:00:00';

        $candidate = $this->userService->getRegistrationDateById(2);

        if (in_array($candidate, [null, '0', ''], true) or strtotime($candidate) < strtotime($piwigo_origins)) {
            $candidate = $this->userService->getMinRegistrationDateAfter($piwigo_origins);
        }

        if (in_array($candidate, [null, '0', ''], true) or strtotime($candidate) < strtotime($piwigo_origins)) {
            // let's find another candidate
            $candidate = $this->imageService->getEarliestDateAvailable();
        }

        return $candidate;
    }
}
