<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\StatsPageContext;
use Piwigo\Common\ValueObject\LangCode;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new StatsPageContext(
        helpUrl: 'https://example.test/admin/popuphelp.php?page=history',
        formAction: 'https://example.test/admin.php?page=history',
        compareYears: ['2025-01' => 3],
        monthStats: ['month' => [['2026-01-01' => 5]], 'avg' => 1.5],
        lastHours: ['2026-01-01T00:00' => 1],
        lastDays: ['2026-01-01' => 2],
        lastMonths: ['2026-01' => 3],
        lastYears: ['2026' => 4],
        langCode: LangCode::from('en_UK'),
        monthLabels: 'January~February',
        adminPageTitle: 'History',
    );

    expect($context->toArray())->toBe([
        'U_HELP' => 'https://example.test/admin/popuphelp.php?page=history',
        'F_ACTION' => 'https://example.test/admin.php?page=history',
        'compareYears' => ['2025-01' => 3],
        'monthStats' => ['month' => [['2026-01-01' => 5]], 'avg' => 1.5],
        'lastHours' => ['2026-01-01T00:00' => 1],
        'lastDays' => ['2026-01-01' => 2],
        'lastMonths' => ['2026-01' => 3],
        'lastYears' => ['2026' => 4],
        'langCode' => 'en_UK',
        'month_labels' => 'January~February',
        'ADMIN_PAGE_TITLE' => 'History',
    ]);
});
