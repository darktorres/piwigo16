<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_end_intro` (notify).
 *
 * Dispatched from: src/Piwigo/Controller/Admin/MiscController.php
 *
 * Not present in tools/triggers_list.php — caught during B3 reverse-audit
 * (152 catalogued DTOs vs 154 unique src/ dispatch sites). The other
 * uncatalogued name was `trigger`, which is intentionally not scaffolded.
 */
final readonly class LocEndIntro
{
}
