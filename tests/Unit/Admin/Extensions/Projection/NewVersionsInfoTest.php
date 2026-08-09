<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\Projection\NewVersionsInfo;

test('toArray always includes piwigo.org-checked and is_dev', function (): void {
    $info = new NewVersionsInfo(piwigoOrgChecked: true, isDev: false);

    expect($info->toArray())->toBe([
        'piwigo.org-checked' => true,
        'is_dev' => false,
    ]);
});

test('toArray omits minor/major/minor_php/major_php when null', function (): void {
    $info = new NewVersionsInfo(piwigoOrgChecked: false, isDev: true);

    expect($info->toArray())->not->toHaveKeys(['minor', 'major', 'minor_php', 'major_php']);
});

test('toArray includes minor/major/minor_php/major_php only when set', function (): void {
    $info = new NewVersionsInfo(
        piwigoOrgChecked: true,
        isDev: false,
        minor: '17.1',
        major: '18.0',
        minorPhp: '8.4',
        majorPhp: '8.5',
    );

    expect($info->toArray())->toBe([
        'piwigo.org-checked' => true,
        'is_dev' => false,
        'minor' => '17.1',
        'major' => '18.0',
        'minor_php' => '8.4',
        'major_php' => '8.5',
    ]);
});
