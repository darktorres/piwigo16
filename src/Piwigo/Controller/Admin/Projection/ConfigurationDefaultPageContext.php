<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'default'` case of
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own render-time `switch` -- the `default` key is always the empty
 * array; every real field this tab's form needs is assigned separately
 * by {@see \Piwigo\Controller\ProfileFormHandler::loadIntoTemplate()},
 * called just before this context.
 */
final readonly class ConfigurationDefaultPageContext implements TemplatePageContext
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'default' => [],
        ];
    }
}
