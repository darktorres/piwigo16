<?php

declare(strict_types=1);

namespace Piwigo\Asset;

enum AssetKind
{
    case Script;
    case Css;
    case InlineScript;
}
