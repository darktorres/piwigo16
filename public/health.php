<?php

declare(strict_types=1);

// Liveness only: is the PHP runtime itself serving requests. No DB, no
// session, no Piwigo bootstrap — see ready.php for the dependency check.
// Used by the Dockerfile HEALTHCHECK, compose healthcheck:, and Helm
// livenessProbe (docs/PLAN-REPLAY.md P4).

header('Content-Type: text/plain');
echo 'OK';
