<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Ported from admin/include/functions.php's get_pwg_general_statitics()/
 * get_installation_date() (P23 batch 8d). Lives under Admin\, not a
 * domain namespace -- its query set cuts across images/categories/tags/
 * users/groups/rates/history, no single domain owns it, same
 * "administrative machinery" shape as PiwigoInfosSender/PluginLoader in
 * this same namespace.
 */
final class InstallationStats
{
    /**
     * @return array{nb_photos: int, nb_categories: int, nb_tags: int,
     *   nb_image_tag: int, nb_users: int, nb_admins: int, nb_groups: int,
     *   nb_rates: int, nb_views: int, disk_usage: int, nb_formats: int,
     *   formats_disk_usage: int}
     */
    public static function getGeneralStatistics(): array
    {
        $nb_photos = \Piwigo\Bootstrap\CoreDomainAccessor::imageService()->getTotalImageCount();
        $nb_categories = \Piwigo\Bootstrap\CoreDomainAccessor::categoryService()->countAllCategories();
        $nb_tags = \Piwigo\Bootstrap\CoreDomainAccessor::tagService()->countAll();
        $nb_image_tag = \Piwigo\Bootstrap\CoreDomainAccessor::tagService()->countAllImageTagLinks();
        $nb_users = \Piwigo\Bootstrap\CoreDomainAccessor::userService()->getTotalUserCount();
        $nb_admins = count(\Piwigo\Bootstrap\CoreDomainAccessor::userService()->getAdminIds());
        $nb_groups = \Piwigo\Bootstrap\CoreDomainAccessor::groupService()->countAll();
        $nb_rates = \Piwigo\Bootstrap\ExtendedDomainAccessor::rateService()->countAll();
        $nb_views = \Piwigo\Bootstrap\ExtendedDomainAccessor::historyService()->getTotalPageViews();
        $images_disk_usage = \Piwigo\Bootstrap\CoreDomainAccessor::imageService()->getTotalFilesize();
        $format_stats = \Piwigo\Bootstrap\CoreDomainAccessor::imageService()->getFormatCountAndSize();
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
    public static function getInstallationDate(): ?string
    {
        $candidate = null;

        // Piwigo first beta versions were created in septembre 2001, so it's not possible
        // to have an installation prior to this "origin of times"
        $piwigo_origins = '2001-09-01 00:00:00';

        $candidate = \Piwigo\Bootstrap\CoreDomainAccessor::userService()->getRegistrationDateById(2);

        if (in_array($candidate, [null, false, 0, '0', '', []], true) or strtotime($candidate) < strtotime($piwigo_origins)) {
            $candidate = \Piwigo\Bootstrap\CoreDomainAccessor::userService()->getMinRegistrationDateAfter($piwigo_origins);
        }

        if (in_array($candidate, [null, false, 0, '0', '', []], true) or strtotime($candidate) < strtotime($piwigo_origins)) {
            // let's find another candidate
            $candidate = \Piwigo\Bootstrap\CoreDomainAccessor::imageService()->getEarliestDateAvailable();
        }

        return $candidate;
    }
}
