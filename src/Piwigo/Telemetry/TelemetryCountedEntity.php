<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

/**
 * The 7 literal tables {@see TelemetryService::galleryStats()}/
 * {@see TelemetryService::extensionStats()} count --
 * enumerated-dispatch replacement for a raw `COUNT(*) FROM {$table}`
 * string splice, one case per counted entity.
 */
enum TelemetryCountedEntity
{
    case Images;
    case Categories;
    case Users;
    case Comments;
    case Plugins;
    case Themes;
    case Languages;
}
