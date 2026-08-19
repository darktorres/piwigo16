<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'default'` (Guest
 * settings) case of {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * render-time `switch`. The `GUEST_`-prefixed fields come from {@see
 * \Piwigo\Controller\Projection\ProfileFormData}, the same data the
 * front-end profile page's own View classes render from -- this tab
 * renders its own inline form directly in `configuration_default.latte`
 * instead, so the fields are threaded through here rather than a View.
 */
final readonly class ConfigurationDefaultPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $radioOptions
     */
    public function __construct(
        public string $username,
        public ?string $email,
        public bool $allowUserCustomization,
        public bool $activateComments,
        public int $nbImagePage,
        public int $recentPeriod,
        public string $expand,
        public string $nbComments,
        public string $nbHits,
        public string $redirect,
        public string $fAction,
        public array $radioOptions,
        public string $csrfToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'default' => [],
            'GUEST_USERNAME' => $this->username,
            'GUEST_EMAIL' => $this->email,
            'GUEST_ALLOW_USER_CUSTOMIZATION' => $this->allowUserCustomization,
            'GUEST_ACTIVATE_COMMENTS' => $this->activateComments,
            'GUEST_NB_IMAGE_PAGE' => $this->nbImagePage,
            'GUEST_RECENT_PERIOD' => $this->recentPeriod,
            'GUEST_EXPAND' => $this->expand,
            'GUEST_NB_COMMENTS' => $this->nbComments,
            'GUEST_NB_HITS' => $this->nbHits,
            'GUEST_REDIRECT' => $this->redirect,
            'GUEST_F_ACTION' => $this->fAction,
            'radio_options' => $this->radioOptions,
            'CSRF_TOKEN' => $this->csrfToken,
        ];
    }
}
