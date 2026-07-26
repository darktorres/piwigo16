<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\SiteManagerRequest;

test('fromArrays does not require a CSRF check for a plain GET with no action', function (): void {
    $request = SiteManagerRequest::fromArrays([], []);

    expect($request->requiresCsrfCheck)->toBeFalse();
});

test('fromArrays requires a CSRF check when POST data is present', function (): void {
    $request = SiteManagerRequest::fromArrays(['x' => '1'], []);

    expect($request->requiresCsrfCheck)->toBeTrue();
});

test('fromArrays requires a CSRF check when action is present', function (): void {
    $request = SiteManagerRequest::fromArrays([], ['action' => 'delete']);

    expect($request->requiresCsrfCheck)->toBeTrue();
});

test('fromArrays parses a valid new-site submission', function (): void {
    $request = SiteManagerRequest::fromArrays(['submit' => '1', 'galleries_url' => './galleries2'], []);

    expect($request->newSiteGalleriesUrl)->toBe('./galleries2');
});

test('fromArrays ignores a submission with an empty galleries_url', function (): void {
    $request = SiteManagerRequest::fromArrays(['submit' => '1', 'galleries_url' => ''], []);

    expect($request->newSiteGalleriesUrl)->toBeNull();
});

test('fromArrays ignores a submission with no submit key', function (): void {
    $request = SiteManagerRequest::fromArrays(['galleries_url' => './galleries2'], []);

    expect($request->newSiteGalleriesUrl)->toBeNull();
});

test('fromArrays parses a numeric site id and action', function (): void {
    $request = SiteManagerRequest::fromArrays([], ['site' => '3', 'action' => 'delete']);

    expect($request->siteId)->toBe(3)
        ->and($request->action)->toBe('delete');
});

test('fromArrays returns a null site id for a non-numeric value', function (): void {
    $request = SiteManagerRequest::fromArrays([], ['site' => 'abc']);

    expect($request->siteId)->toBeNull();
});
