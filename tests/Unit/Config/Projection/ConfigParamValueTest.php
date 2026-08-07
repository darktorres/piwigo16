<?php

declare(strict_types=1);

use Piwigo\Config\Projection\ConfigParamValue;

test('constructs with the given param and value', function (): void {
    $entry = new ConfigParamValue('nbm_send_html_mail', 'true');

    expect($entry->param)->toBe('nbm_send_html_mail')
        ->and($entry->value)->toBe('true');
});

test('constructs with a null value', function (): void {
    $entry = new ConfigParamValue('nbm_send_html_mail', null);

    expect($entry->value)->toBeNull();
});
