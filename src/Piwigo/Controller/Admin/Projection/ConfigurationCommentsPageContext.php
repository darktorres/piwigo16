<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'comments'` case of
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own render-time `switch`. Constructed immediately at that case's
 * original position, for the same "a later `Template::append()` loop
 * needs this key already live" reason documented on
 * {@see ConfigurationMainPageContext}.
 */
final readonly class ConfigurationCommentsPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $comments
     */
    public function __construct(
        public array $comments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'comments' => $this->comments,
        ];
    }
}
