<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `popuphelp.latte`'s own typed view -- constructed by both the
 * front-end {@see \Piwigo\Controller\PopuphelpController} and the
 * admin-context {@see \Piwigo\Controller\Admin\AdminPopuphelpController},
 * two real callers of the same template.
 */
#[Template('popuphelp.latte')]
final readonly class PopuphelpView implements View
{
    public function __construct(
        public string $helpContent,
    ) {}
}
