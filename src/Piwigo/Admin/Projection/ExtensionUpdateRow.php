<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One pending extension update on `admin.php?page=updates&tab=ext`
 * (P58-A), built by {@see \Piwigo\Admin\UpdatesExtPageRenderer} from a
 * local scan row plus the PEM server's own entry for it.
 *
 * `$revisionId` is a string. The PEM payload is `array<string, mixed>`,
 * and this was the one consumer that passed the value straight through:
 * `LanguagesNewPageRenderer`, `ThemesNewPageRenderer` and
 * `PluginsNewPageRenderer` all narrow the same field with
 * `is_scalar($x) ? (string) $x : ''`. The API end of the round trip agrees
 * with them -- `ExtensionUpdateInput` reads the posted revision through
 * `is_string($revision) ? $revision : ''`, so a non-string arrives as an
 * empty revision, and `ExtensionLifecycle` needs it to name the archive.
 */
final readonly class ExtensionUpdateRow
{
    public function __construct(
        public string $id,
        public string $revisionId,
        public string $extId,
        public string $name,
        public string $url,
        public string $revisionDescription,
        public string $currentVersion,
        public string $newVersion,
        public string $downloadUrl,
        public bool $ignored,
    ) {}
}
