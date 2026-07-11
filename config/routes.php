<?php

declare(strict_types=1);

use Symfony\Component\Routing\RouteCollection;

// Empty for now -- P9 builds the routing mechanism (see
// src/Piwigo/Routing/Router.php); real routes are added incrementally
// starting P22, once real Controllers exist to reference as `_controller`.
// P9's own tests/Unit/Routing/RouterTest.php dispatches against an
// in-memory RouteCollection built inline, not this file.

return new RouteCollection();
