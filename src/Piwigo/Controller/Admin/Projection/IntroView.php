<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Activity\Projection\ActivityDay;
use Piwigo\Admin\Projection\ColorboxView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
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
final readonly class IntroView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<string> $activityWeekNumber
     * @param array<int, array<int, ActivityDay>> $activityLastWeeks one
     *   entry per rendered day cell, keyed [week index][ISO weekday]
     * @param array<int, array<int, int>> $activityChartData the same
     *   keying, holding each cell's circle size rather than its activity --
     *   `intro.latte` iterates this one and reads $activityLastWeeks at
     *   its keys, so the two shapes have to agree
     * @param list<string> $dayLabels
     * @param array<string, array{total: array{filesize: float, nb_files?: int},
     *   details?: array<string, array{filesize: float, nb_files: int}>}> $storageChartData keyed by storage
     *   type ('Photos'/'Videos'/'Other'/'Formats'/'Cache'). The
     *   'Cache' entry is the odd one: it carries a filesize and
     *   nothing else, so both 'nb_files' and 'details' are
     *   genuinely optional here. Key names are the wire contract
     *   with intro.ts's own StorageDetails (build/ambient-globals.d.ts),
     *   which reads them through pwg_getPageData('storage_chart_data')
     *   -- they are snake_case for that reason and must stay so.
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
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            ...new ColorboxView()
                ->pageAssets(),
            // Real per-page bundle entry (docs/PLAN.md's P48) -- folds
            // intro.ts/intro_tooltips.ts's code in via real imports
            // instead of the 2 separate script tags this used to
            // register. No longer depends on `jquery.cluetip`: this
            // page never rendered any `.cluetip`-classed markup, so
            // that registration (and intro.ts's own matching call) was
            // genuinely dead and removed outright rather than ported.
            AssetContribution::script('intro_page', 'themes/admin/default/js/pages/intro.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/intro.css', id: 'intro'),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'storage_total' => $this->storageTotal,
            'storage_chart_data' => $this->storageChartData,
            'check_for_updates' => $this->checkForUpdates,
            'subscribe_base_url' => $this->subscribeBaseUrl,
            'email' => $this->email,
            'old_newsletters_url' => $this->oldNewslettersUrl,
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        $strings = [
            'A new version of Piwigo is available.',
            'Some upgrades are available for extensions.',
            '%s GB used',
            '%s MB used',
            '%sGB',
            '%sMB',
            '%d files',
            ...array_keys($this->storageChartData),
        ];

        if ($this->subscribeBaseUrl !== null) {
            $strings[] = 'Subscribe to our newsletter and stay updated!';
            $strings[] = 'Sign up to the newsletter';
            $strings[] = 'See previous newsletters';
            $strings[] = 'Understood, do not show again';
        }

        return $strings;
    }
}
