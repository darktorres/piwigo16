<?php

declare(strict_types=1);

use Piwigo\Admin\Request\PluginsInstalledDisplayRequest;

test('fromArray returns null showDetails when the param is absent', function (): void {
    $request = PluginsInstalledDisplayRequest::fromArray([]);

    expect($request->showDetails)
        ->toBeNull();
});

test('fromArray returns true showDetails for the literal "1"', function (): void {
    $request = PluginsInstalledDisplayRequest::fromArray([
        'show_details' => '1',
    ]);

    expect($request->showDetails)
        ->toBeTrue();
});

test('fromArray returns false showDetails for any other value', function (): void {
    $request = PluginsInstalledDisplayRequest::fromArray([
        'show_details' => '0',
    ]);

    expect($request->showDetails)
        ->toBeFalse();
});

test('fromArray detects the incompatible_plugins presence flag', function (): void {
    $request = PluginsInstalledDisplayRequest::fromArray([
        'incompatible_plugins' => '',
    ]);

    expect($request->isIncompatiblePluginsRequest)
        ->toBeTrue();
});

test('fromArray detects the show_inactive presence flag', function (): void {
    $request = PluginsInstalledDisplayRequest::fromArray([
        'show_inactive' => '',
    ]);

    expect($request->showInactive)
        ->toBeTrue();
});

test('fromArray reports both flags false when absent', function (): void {
    $request = PluginsInstalledDisplayRequest::fromArray([]);

    expect($request->isIncompatiblePluginsRequest)
        ->toBeFalse()
        ->and($request->showInactive)
        ->toBeFalse();
});
