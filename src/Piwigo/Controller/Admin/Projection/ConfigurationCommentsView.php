<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_comments.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'comments'` case, merged with the whole-page shared fields every
 * `configuration_*.latte` tab needs -- see {@see ConfigurationMainView}.
 */
#[Template('configuration_comments.latte')]
final readonly class ConfigurationCommentsView implements View, HasPageAssets
{
    /**
     * @param array<string, mixed> $comments
     */
    public function __construct(
        public array $comments,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
    ) {}

    /**
     * `configuration_comments.latte`'s own unconditional
     * `{do combineScript(...)}`x2 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('common', 'themes/admin/default/js/common.ts', loadMode: LoadMode::Footer),
            AssetContribution::script('configuration_comments', 'themes/admin/default/js/configuration_comments.ts', loadMode: LoadMode::Footer),
        ];
    }
}
