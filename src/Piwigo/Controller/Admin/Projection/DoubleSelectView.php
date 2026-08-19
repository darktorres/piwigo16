<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `double_select.latte`'s own typed view -- a two-column category
 * picker shared by four real callers: {@see
 * \Piwigo\Controller\Admin\NotificationByMailSubController::handle()}'s
 * own "subscribe" tab, and (not yet converted)
 * `Admin\CatOptionsPageRenderer`, `Admin\UserPermPageRenderer`,
 * `Admin\GroupPermPageRenderer`.
 */
#[Template('double_select.latte')]
final readonly class DoubleSelectView implements View
{
    /**
     * @param array<string, string> $categoryOptionTrue
     * @param list<string> $categoryOptionTrueSelected
     * @param array<string, string> $categoryOptionFalse
     * @param list<string> $categoryOptionFalseSelected
     */
    public function __construct(
        public string $lCatOptionsTrue,
        public string $lCatOptionsFalse,
        public array $categoryOptionTrue,
        public array $categoryOptionTrueSelected,
        public array $categoryOptionFalse,
        public array $categoryOptionFalseSelected,
    ) {}
}
