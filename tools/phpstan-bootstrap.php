<?php

declare(strict_types=1);

// Bootstrap for PHPStan's own analysis run (docs/PLAN-REPLAY.md P0 step 5).
// Empty for now: legacy constants are all define()'d within analyzed files, so
// PHPStan already sees them during the same run. Grows as later phases need
// stubs for symbols defined outside the analyzed paths.
