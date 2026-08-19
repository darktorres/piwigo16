<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `popuphelp.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\PopuphelpController::__invoke()} in place of its
 * former `PopuphelpPageContext`.
 */
#[Template('popuphelp.latte')]
final readonly class PopuphelpView implements View
{
    public function __construct(
        public string $helpContent,
    ) {}
}
