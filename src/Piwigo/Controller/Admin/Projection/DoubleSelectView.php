<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `double_select.latte`'s own typed view -- a two-column category
 * picker shared by four real callers: {@see
 * \Piwigo\Controller\Admin\NotificationByMailSubController::handle()}'s
 * own "subscribe" tab, `Admin\CatOptionsPageRenderer`,
 * `Admin\UserPermPageRenderer`, `Admin\GroupPermPageRenderer`.
 */
#[Template('double_select.latte')]
final readonly class DoubleSelectView implements View, HasPageAssets
{
    /**
     * `$categoryOption{True,False}` and their `*Selected` counterparts
     * match {@see \Piwigo\Category\Projection\CategorySelectOptions}'s own
     * `$options`/`$selected` shape exactly -- 2 of the 4 real callers
     * source these straight from `CategoryService::displaySelectByX()`,
     * whose category-id-keyed options can genuinely carry `int` keys
     * (category ids), not just `string` ones.
     *
     * @param array<int|string, string> $categoryOptionTrue
     * @param array<int, mixed> $categoryOptionTrueSelected
     * @param array<int|string, string> $categoryOptionFalse
     * @param array<int, mixed> $categoryOptionFalseSelected
     */
    public function __construct(
        public string $lCatOptionsTrue,
        public string $lCatOptionsFalse,
        public array $categoryOptionTrue,
        public array $categoryOptionTrueSelected,
        public array $categoryOptionFalse,
        public array $categoryOptionFalseSelected,
    ) {}

    /**
     * `double_select.latte`'s own unconditional `{do combineCss(...)}`
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/admin/default/css/pages/double_select.css', id: 'double_select'),
        ];
    }
}
