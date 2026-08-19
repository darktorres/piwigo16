<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\Projection\AnomalyDisplayRow;
use Piwigo\Admin\Integrity\Projection\CheckIntegrityPageContext;

test('toArray flattens both submit flags and c13y_list, and omits c13y_do_check when null', function (): void {
    $row = new AnomalyDisplayRow(
        id: 'abc',
        anomaly: 'Something is wrong',
        showIgnoreMsg: false,
        showCorrectionSuccessFct: false,
        correctionErrorFct: '',
        showCorrectionFct: false,
        showCorrectionBadFct: false,
        correctionMsg: '',
        canSelect: true,
    );

    expect(new CheckIntegrityPageContext(showSubmitAutomaticCorrection: true, showSubmitIgnore: false, c13yList: [$row], c13yDoCheck: null)->toArray())
        ->toBe([
            'c13y_show_submit_automatic_correction' => true,
            'c13y_show_submit_ignore' => false,
            'c13y_list' => [$row->toArray()],
        ]);
});

test('toArray includes c13y_do_check when set', function (): void {
    $result = new CheckIntegrityPageContext(showSubmitAutomaticCorrection: true, showSubmitIgnore: true, c13yList: [], c13yDoCheck: ['abc', 'def'])->toArray();

    expect($result['c13y_do_check'])->toBe(['abc', 'def']);
});
