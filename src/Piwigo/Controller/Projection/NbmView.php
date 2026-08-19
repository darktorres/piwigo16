<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `nbm.latte`'s own typed view -- {@see \Piwigo\Controller\NbmController}
 * assigns no page-specific data of its own; the template renders entirely
 * off ambient globals (`MENUBAR`/`U_HOME`/`LEVEL_SEPARATOR`, `errors`/
 * `infos` via `infos_errors.latte`), so this stays a bare marker with no
 * properties.
 */
#[Template('nbm.latte')]
final readonly class NbmView implements View {}
