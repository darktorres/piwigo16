<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for the legacy `tabsheet_before_select` filter. Registered
 * (`CoreTabs::addCoreTabs()`, wired from `AdminShell.php`) -- mutable on
 * `$sheets`. `$tabsheetId` is nullable -- diverges from the reference's
 * non-nullable `string` -- since its one real dispatch site
 * (`Tabsheet::select()`) passes `$this->uniqid`, only ever set via
 * `setId()`, `null` until then.
 *
 * `$sheets` matches the reference's own loose `array<mixed>`, not
 * `Tabsheet::$sheets`'s real precise shape
 * (`array<string, array{caption: string, url: string}>`) -- a real
 * plugin handler can hand back a malformed shape (a genuinely different
 * risk than a genuinely wrong TYPE, which PHP's own native `array`
 * property typing still rules out -- a handler assigning anything else
 * to `$event->sheets` throws a TypeError at that assignment), so the one
 * real consumer (`Tabsheet::select()`) always re-validates each entry
 * defensively regardless of what this property declares; a precise
 * shape here would only fight that -- and PHPStan itself -- for both
 * this file's own tests (which deliberately construct malformed shapes
 * to exercise that defense) and any real plugin.
 */
final class TabsheetBeforeSelect
{
    /**
     * @param array<mixed> $sheets
     */
    public function __construct(
        public array $sheets,
        public readonly ?string $tabsheetId,
    ) {}
}
