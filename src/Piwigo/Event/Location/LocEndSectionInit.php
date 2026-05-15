<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_end_section_init` (notify).
 *
 * fired after section initialization; $page variable is fully defined
 *
 * Dispatched from: src/Piwigo/Section/SectionInitializer.php
 */
final readonly class LocEndSectionInit
{
}
