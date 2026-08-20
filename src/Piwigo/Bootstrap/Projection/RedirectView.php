<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `redirect.latte`'s own typed view, constructed by {@see
 * \Piwigo\Bootstrap\RedirectService::redirectHtml()}.
 */
#[Template('redirect.latte')]
final readonly class RedirectView implements View
{
    public function __construct(
        public string $redirectMsg,
    ) {}
}
