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
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_RETURN' => '',
            'USERNAME' => '',
            'U_FAQ' => '',
            'U_CHANGE_THEME' => '',
            'U_LOGOUT' => '',
        ];
    }
}
