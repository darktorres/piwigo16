<?php

declare(strict_types=1);

use Piwigo\Controller\Request\ProfileActionRequest;

test('fromArray does not require a CSRF check for an empty POST', function (): void {
    $request = ProfileActionRequest::fromArray([]);

    expect($request->requiresCsrfCheck)->toBeFalse()
        ->and($request->resetToDefault)->toBeFalse();
});

test('fromArray requires a CSRF check when POST data is present', function (): void {
    $request = ProfileActionRequest::fromArray(['nb_image_page' => '10']);

    expect($request->requiresCsrfCheck)->toBeTrue();
});

test('fromArray recognizes the reset_to_default toggle', function (): void {
    $request = ProfileActionRequest::fromArray(['reset_to_default' => '1']);

    expect($request->resetToDefault)->toBeTrue();
});
