<?php

declare(strict_types=1);

namespace Piwigo\Asset;

/**
 * Where a script tag renders, replacing `ScriptLoader`'s magic ints
 * (0/1/2 -- see that class's own `$loadMode` docblock). Only scripts
 * have a load mode; CSS is ordered by `AssetContribution::$order`
 * instead (see that class's own docblock for why the two axes are
 * independent).
 *
 * Backed by int, ordered Header < Footer < Async, so `PageAssets`'s
 * dependency-promotion pass (a dependency can't load more loosely than
 * its dependent -- ScriptLoader::checkLoadDep()'s real, preserved
 * behavior) can compare modes with a plain `<=>`.
 */
enum LoadMode: int
{
    case Header = 0;
    case Footer = 1;
    case Async = 2;
}
