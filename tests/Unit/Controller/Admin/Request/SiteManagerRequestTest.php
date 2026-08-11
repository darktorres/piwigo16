<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\SiteManagerRequest;

test('fromArrays does not require a CSRF check for a plain GET with no action', function (): void {
    $request = SiteManagerRequest::fromArrays([], []);

    expect($request->requiresCsrfCheck)
        ->toBeFalse();
});

test('fromArrays requires a CSRF check when POST data is present', function (): void {
    $request = SiteManagerRequest::fromArrays([
        'x' => '1',
    ], []);

    expect($request->requiresCsrfCheck)
        ->toBeTrue();
});

test('fromArrays requires a CSRF check when action is present', function (): void {
    $request = SiteManagerRequest::fromArrays([], [
        'action' => 'delete',
    ]);

    expect($request->requiresCsrfCheck)
        ->toBeTrue();
});

test('fromArrays parses a valid new-site submission', function (): void {
    $request = SiteManagerRequest::fromArrays([
        'submit' => '1',
        'galleries_url' => './galleries2',
    ], []);

    expect($request->newSiteGalleriesUrl)
        ->toBe('./galleries2');
});

test('fromArrays ignores a submission with an empty galleries_url', function (): void {
    $request = SiteManagerRequest::fromArrays([
        'submit' => '1',
        'galleries_url' => '',
    ], []);

    expect($request->newSiteGalleriesUrl)
        ->toBeNull();
});

test('fromArrays ignores a submission with a galleries_url of the string \'0\'', function (): void {
    // Every other sentinel value in the in_array() list (null, false,
    // 0, []) is already redundant with the very next `is_string()`
    // check -- is_string() rejects them regardless of whether that
    // specific value (or its exact literal, for false/0) is even in the
    // sentinel list, since none of them is a string to begin with. A
    // mutation-testing sweep confirmed all of FalseToTrue,
    // DecrementInteger/IncrementInteger (the 0 literal), and
    // RemoveArrayItem for null/false/0/[] are unobservable for this
    // exact reason -- not chased further. '0' is the one sentinel value
    // that both is_string() accepts AND the list must reject on its
    // own, matching InputValidator::emptyValue()'s own identical
    // "falsy string" case.
    $request = SiteManagerRequest::fromArrays([
        'submit' => '1',
        'galleries_url' => '0',
    ], []);

    expect($request->newSiteGalleriesUrl)
        ->toBeNull();
});

test('fromArrays ignores a submission with no submit key', function (): void {
    $request = SiteManagerRequest::fromArrays([
        'galleries_url' => './galleries2',
    ], []);

    expect($request->newSiteGalleriesUrl)
        ->toBeNull();
});

test('fromArrays parses a numeric site id and action', function (): void {
    $request = SiteManagerRequest::fromArrays([], [
        'site' => '3',
        'action' => 'delete',
    ]);

    expect($request->siteId)
        ->toBe(3)
        ->and($request->action)
        ->toBe('delete');
});

test('fromArrays returns a null site id for a non-numeric value', function (): void {
    $request = SiteManagerRequest::fromArrays([], [
        'site' => 'abc',
    ]);

    expect($request->siteId)
        ->toBeNull();
});
