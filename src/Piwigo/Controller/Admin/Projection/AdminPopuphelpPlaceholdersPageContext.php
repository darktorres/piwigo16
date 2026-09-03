<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * Placeholder template vars assigned by
 * {@see \Piwigo\Controller\Admin\AdminPopuphelpController} to avoid an
 * "Undefined array key" warning in header.latte -- this admin-context
 * popup never assigns the real values PageHeaderRenderer's normal
 * caller chain would.
 */
final readonly class AdminPopuphelpPlaceholdersPageContext implements TemplatePageContext
{
    public function __construct(
        public string $uReturn = '',
        public string $username = '',
        public string $uFaq = '',
        public string $uChangeTheme = '',
        public string $uLogout = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_RETURN' => $this->uReturn,
            'USERNAME' => $this->username,
            'U_FAQ' => $this->uFaq,
            'U_CHANGE_THEME' => $this->uChangeTheme,
            'U_LOGOUT' => $this->uLogout,
        ];
    }
}
