<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `tabsheet_before_select` (dispatch).
 *
 * New in 2.4, use this trigger to add tabs to a tabsheet
 *
 * Dispatched from: src/Piwigo/Admin/Tabsheet.php (Tabsheet::select)
 */
final class TabsheetBeforeSelect
{
    /**
     * @param array<mixed> $sheets
     */
    public function __construct(
        public array $sheets,
        public readonly string $tabsheetId,
    ) {
    }
}
