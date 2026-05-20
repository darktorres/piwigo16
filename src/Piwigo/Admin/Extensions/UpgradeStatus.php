<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

/**
 * Outcome of a PEM extension install/upgrade extraction pass — emitted by
 * `Plugins::extractPluginFiles()`, `Themes::extractThemeFiles()` and
 * `Languages::extractLanguageFiles()`. Languages cannot surface
 * `ManifestInvalid` (no manifest is consulted), but the wider set is
 * shared so callers can branch on a single closed type.
 */
enum UpgradeStatus: string
{
    case Ok              = 'ok';
    case TempPathError   = 'temp_path_error';
    case DlArchiveError  = 'dl_archive_error';
    case ArchiveError    = 'archive_error';
    case ExtractError    = 'extract_error';
    case ManifestInvalid = 'manifest_invalid';
}
