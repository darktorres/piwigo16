<?php

declare(strict_types=1);

use Piwigo\Admin\Request\TagsActionRequest;

test('fromArray recognizes the delete_orphans action', function (): void {
    $request = TagsActionRequest::fromArray([
        'action' => 'delete_orphans',
    ]);

    expect($request->isDeleteOrphans)
        ->toBeTrue();
});

test('fromArray treats a missing action as not delete_orphans', function (): void {
    $request = TagsActionRequest::fromArray([]);

    expect($request->isDeleteOrphans)
        ->toBeFalse();
});

test('fromArray treats any other action value as not delete_orphans', function (): void {
    $request = TagsActionRequest::fromArray([
        'action' => 'something_else',
    ]);

    expect($request->isDeleteOrphans)
        ->toBeFalse();
});
