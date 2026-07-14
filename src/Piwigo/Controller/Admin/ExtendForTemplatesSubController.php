<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\ExtendForTemplatesPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/extend_for_templates.php (page slug "extend_for_templates")
 * -- a flat page, pure delegate. This batch fixed a real, verified defect:
 * its single-param config UPDATE spliced the serialized value into SQL with
 * zero escaping (unlike every other raw-SQL config write in this codebase,
 * which all double single quotes first) -- fixed with the same
 * str_replace("\'", "''", ...) escaping admin/configuration.php's own
 * generic config-row loop already uses.
 */
final class ExtendForTemplatesSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new ExtendForTemplatesPageRenderer()
            ->render();
    }
}
