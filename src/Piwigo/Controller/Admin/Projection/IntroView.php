<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `intro.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\IntroSubController::handle()}. `$email`/
 * `$subscribeBaseUrl`/`$oldNewslettersUrl` are only ever set together,
 * under the newsletter-promo eligibility condition -- `intro.latte`'s
 * own body only gates on `isset($subscribeBaseUrl)`. `check_integrity.
 * latte`'s own output may be appended onto this view's own rendered
 * `ADMIN_CONTENT` afterward -- see this file's own trailing comment.
 */
#[Template('intro.latte')]
final readonly class IntroView implements View
{
    /**
     * @param list<string> $activityWeekNumber
     * @param array<array-key, mixed> $activityLastWeeks
     * @param array<array-key, mixed> $activityChartData
     * @param list<string> $dayLabels
     * @param array<string, array<string, array<string, mixed>>> $storageChartData
     */
    public function __construct(
        public ?string $email,
        public ?string $subscribeBaseUrl,
        public ?string $oldNewslettersUrl,
        public int $nbPhotos,
        public int $nbAlbums,
        public int $nbTags,
        public int $nbImageTag,
        public int $nbUsers,
        public int $nbGroups,
        public int $nbRates,
        public string $nbViews,
        public int $nbPlugins,
        public string $storageUsed,
        public string $uQuickSync,
        public bool $checkForUpdates,
        public int $nbComments,
        public array $activityWeekNumber,
        public array $activityLastWeeks,
        public array $activityChartData,
        public int $activityChartNumberSizes,
        public array $dayLabels,
        public float $storageTotal,
        public array $storageChartData,
    ) {}
}
