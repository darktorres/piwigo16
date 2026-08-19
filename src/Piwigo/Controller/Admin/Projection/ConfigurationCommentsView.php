<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_comments.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'comments'` case, merged with the whole-page shared fields every
 * `configuration_*.latte` tab needs -- see {@see ConfigurationMainView}.
 */
#[Template('configuration_comments.latte')]
final readonly class ConfigurationCommentsView implements View
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
}
