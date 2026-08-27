<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `check_integrity.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\IntroSubController::handle()} from {@see
 * \Piwigo\Admin\Integrity\CheckIntegrity::display()}'s own {@see
 * CheckIntegrityResult} -- only when that call returns non-null (at
 * least one real anomaly to show). `$c13yDoCheck` is genuinely
 * optional -- only populated when at least one anomaly has a real,
 * callable correction function -- while `$c13yList` is always a real,
 * non-empty list whenever this View is constructed at all, matching
 * `check_integrity.latte`'s own `{if isset($c13yList)}` guard exactly
 * (`get_object_vars()` always sets the key, so `isset()` only ever
 * depends on the value itself).
 */
#[Template('check_integrity.latte')]
final readonly class CheckIntegrityView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<array<string, mixed>> $c13yList
     * @param list<string>|null $c13yDoCheck
     */
    public function __construct(
        public bool $showSubmitAutomaticCorrection,
        public bool $showSubmitIgnore,
        public array $c13yList,
        public ?array $c13yDoCheck,
    ) {}

    /**
     * `check_integrity.latte`'s own unconditional `{do combineScript(...)}`
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('check_integrity', 'themes/admin/default/js/check_integrity.ts', loadMode: LoadMode::Footer),
        ];
    }

    /**
     * `check_integrity.latte`'s own `{if isset($c13yDoCheck)}
     * {do exposeData('c13y_do_check_ids', $c13yDoCheck)}{/if}`
     * (docs/PLAN.md's P42-B) -- the key itself must be genuinely absent
     * from the JSON payload when `$c13yDoCheck` is null, not present
     * with a null value, matching the original `isset()` guard exactly.
     */
    #[Override]
    public function exposedPageData(): array
    {
        if ($this->c13yDoCheck === null) {
            return [];
        }

        return [
            'c13y_do_check_ids' => $this->c13yDoCheck,
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
