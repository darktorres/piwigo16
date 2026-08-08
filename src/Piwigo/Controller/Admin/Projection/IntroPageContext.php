<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\IntroSubController::handle()}.
 * `$email`, `$subscribeBaseUrl`, and `$oldNewslettersUrl` are genuinely
 * optional -- the original code only ever assigns those 3 template keys
 * together, under their own runtime condition (newsletter promo
 * eligibility), omitted here (not present as a null value) to match
 * that exact original behavior.
 */
final readonly class IntroPageContext implements TemplatePageContext
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

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'NB_PHOTOS' => $this->nbPhotos,
            'NB_ALBUMS' => $this->nbAlbums,
            'NB_TAGS' => $this->nbTags,
            'NB_IMAGE_TAG' => $this->nbImageTag,
            'NB_USERS' => $this->nbUsers,
            'NB_GROUPS' => $this->nbGroups,
            'NB_RATES' => $this->nbRates,
            'NB_VIEWS' => $this->nbViews,
            'NB_PLUGINS' => $this->nbPlugins,
            'STORAGE_USED' => $this->storageUsed,
            'U_QUICK_SYNC' => $this->uQuickSync,
            'CHECK_FOR_UPDATES' => $this->checkForUpdates,
            'NB_COMMENTS' => $this->nbComments,
            'ACTIVITY_WEEK_NUMBER' => $this->activityWeekNumber,
            'ACTIVITY_LAST_WEEKS' => $this->activityLastWeeks,
            'ACTIVITY_CHART_DATA' => $this->activityChartData,
            'ACTIVITY_CHART_NUMBER_SIZES' => $this->activityChartNumberSizes,
            'DAY_LABELS' => $this->dayLabels,
            'STORAGE_TOTAL' => $this->storageTotal,
            'STORAGE_CHART_DATA' => $this->storageChartData,
        ];

        if ($this->email !== null) {
            $result['EMAIL'] = $this->email;
        }

        if ($this->subscribeBaseUrl !== null) {
            $result['SUBSCRIBE_BASE_URL'] = $this->subscribeBaseUrl;
        }

        if ($this->oldNewslettersUrl !== null) {
            $result['OLD_NEWSLETTERS_URL'] = $this->oldNewslettersUrl;
        }

        return $result;
    }
}
