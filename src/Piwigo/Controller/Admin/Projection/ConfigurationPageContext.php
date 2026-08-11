<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The unconditional, whole-page template variable set assigned by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}
 * -- the values every tab needs regardless of which `$page['section']`
 * is active. Every per-tab template variable set is its own separate
 * context, constructed immediately at its own case in `handle()`'s
 * render-time `switch` (not deferred here) whenever a later
 * `Template::append()` call or a `Template::getTemplateVars()` read
 * needs the value already live -- see {@see ConfigurationMainPageContext},
 * {@see ConfigurationCommentsPageContext},
 * {@see ConfigurationDefaultPageContext},
 * {@see ConfigurationSizesTabPageContext},
 * {@see ConfigurationWatermarkTabPageContext} and
 * {@see ConfigurationSearchTabPageContext}. `$saveSuccess` unifies 2
 * mutually exclusive branches (the generic config-save loop, and the
 * "sizes" tab's "restore default settings" action) that both only ever
 * target this same template key.
 */
final readonly class ConfigurationPageContext implements TemplatePageContext
{
    public function __construct(
        public ?string $saveSuccess,
        public string $uHelp,
        public string $pwgToken,
        public string $fAction,
        public int $isWebmaster,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'U_HELP' => $this->uHelp,
            'CSRF_TOKEN' => $this->pwgToken,
            'F_ACTION' => $this->fAction,
            'isWebmaster' => $this->isWebmaster,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];

        if ($this->saveSuccess !== null) {
            $result['save_success'] = $this->saveSuccess;
        }

        return $result;
    }
}
