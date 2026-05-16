<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

/**
 * Three kinds of PEM-style extensions that Piwigo's updates flow tracks.
 *
 * Cases use the historical pluralized string values that the existing
 * admin/template surface, WS API contract, and conf table all speak
 * (e.g. PageState extensions_need_update keyed by 'plugins'|'themes'|'languages').
 */
enum ExtensionType: string
{
    case Plugin   = 'plugins';
    case Theme    = 'themes';
    case Language = 'languages';
}
