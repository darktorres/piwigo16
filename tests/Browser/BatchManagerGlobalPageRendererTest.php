<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\BatchManagerGlobalPageRenderer -- the vast majority of its
 * action branches (add_tags/del_tags success, associate, move, dissociate,
 * author/title/date_creation/level mass-update, caddie, delete, metadata,
 * generate_derivatives) are already exercised indirectly through
 * tests/Browser/BatchManagerSubControllerTest.php (which dispatches here
 * for every non-"unit" tab submission -- see that file's own docblock).
 * This file adds only the specific error/edge branches that suite's
 * happy-path scenarios never reach: empty-selection/empty-tag/empty-album
 * validation errors, the "remove" checkbox variants of the title/
 * date_creation mass-update, delete-with-nothing-to-delete, delete_derivatives,
 * and the display= GET override for the thumbnail page size.
 *
 * Also covers (each seeds `$_SESSION['bulk_manager_filter']` itself via a
 * preceding filter-form submission on the same authenticated session, then
 * drives the specific follow-up action that reads it): the add_tags/
 * del_tags/associate/move "redirect because this action affected the
 * active filter" conditions, the privacy_level filter-comparison redirect,
 * the delete_derivatives representative_ext passthrough (seeded via a
 * direct DB write -- real setup, not an assertion on the DB), the
 * 'duplicates'-prefilter thumbnail ordering branch (two real photos
 * sharing one uploaded basename, not a DB fixture hack -- see
 * bmMakeNamedTestImage()'s own docblock), and the display=Config-
 * fallback-to-20 branch (a real non-standard batch_manager_images_per_page_global
 * config value, snapshotted/restored around the test).
 */
it('reports "select at least one photo" when submitting an action with nothing selected', function (): void {
    $page = H::loginAsAdmin($this);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'add_tags',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Select at least one photo');
});

it('reports "select at least one tag" for add_tags with no tags chosen', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Empty AddTags ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global Empty AddTags Photo');
    @unlink($image);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'add_tags',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Select at least one tag');
});

it('reports "select at least one tag" for del_tags with no tags chosen', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Empty DelTags ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global Empty DelTags Photo');
    @unlink($image);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'del_tags',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Select at least one tag');
});

it('reports "select at least one album" for associate with no album chosen', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Empty Associate ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global Empty Associate Photo');
    @unlink($image);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'associate',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Select at least one album');
});

it('clears the title/date_creation via their own "remove" checkboxes instead of an empty string', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Remove Fields ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global Remove Fields Photo Original Name');
    @unlink($image);

    $titleResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'title',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'remove_title' => '1',
        // A non-empty 'title' is deliberately also sent, to prove
        // remove_title wins over it (post['title'] is nulled BEFORE the
        // per-image $datas loop reads it), not merely that an empty title
        // was submitted.
        'title' => 'This Should Be Ignored',
    ]);
    expect($titleResult['status'])->toBe(200);

    $db = bmDbConnect();
    $nameRow = H::dbFetchAssoc($db, sprintf('SELECT name FROM images WHERE id = %d', $imageId));
    expect(is_array($nameRow) ? $nameRow['name'] : 'MISSING')->toBeNull();

    $dateResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'date_creation',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'remove_date_creation' => '1',
        'date_creation' => '2020-05-05',
    ]);
    expect($dateResult['status'])->toBe(200);

    $dateAssoc = H::dbFetchAssoc($db, sprintf('SELECT date_creation FROM images WHERE id = %d', $imageId));
    expect(is_array($dateAssoc) ? $dateAssoc['date_creation'] : 'MISSING')->toBeNull();
    H::dbClose($db);
});

it('reports "no photo can be deleted" when confirm_deletion is sent with an empty selection', function (): void {
    $page = H::loginAsAdmin($this);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'delete',
        'confirm_deletion' => '1',
    ]);

    expect($result['status'])->toBe(200);
    // Both the outer "empty collection" guard (shared by every action) and
    // this action's own more specific "nothing to delete" guard fire
    // together -- the outer one doesn't stop execution, it only adds its
    // own error and falls through into the action switch below it.
    expect($result['body'])->toContain('Select at least one photo');
    expect($result['body'])->toContain('No photo can be deleted');
});

it('deletes cached derivatives of the chosen type for a real selection via delete_derivatives', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Del Derivatives ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global Del Derivatives Photo');
    @unlink($image);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'delete_derivatives',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'del_derivatives_type' => ['thumb'],
    ]);

    expect($result['status'])->toBe(200);
    H::assertNoServerErrors($page, 'batch_manager delete_derivatives');
});

it('honors display=all and a numeric display= to override the per-page thumbnail count', function (): void {
    $page = H::loginAsAdmin($this);

    $allResult = H::rawGet($page, '/admin.php?page=batch_manager&display=all');
    expect($allResult['status'])->toBe(200);

    $numericResult = H::rawGet($page, '/admin.php?page=batch_manager&display=50');
    expect($numericResult['status'])->toBe(200);
});

it('redirects after add_tags when the active filter is the "no_tag" prefilter', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global NoTag Redirect ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global NoTag Redirect Photo');
    @unlink($image);

    $filterResult = bmPost($page, [
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'no_tag',
    ]);
    expect($filterResult['status'])->toBe(200);

    // add_tags's own success path only refreshes the page (BatchManagerGlobalPageRenderer.php's
    // own `$redirect = true`) when the active session filter is specifically
    // the "no_tag" prefilter -- tagging a photo removes it from that
    // filtered set, so the listing must reload to stay accurate. A real
    // HTTP redirect becomes an opaque response under fetch(manual), status
    // always 0 (see BatchManagerSubControllerTest's own empty_caddie test
    // for this same documented Fetch API behavior).
    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'add_tags',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'add_tags' => ['~~1~~'],
    ]);
    expect($result['status'])->toBe(0);
});

it("redirects after del_tags when the removed tags overlap the active filter's tags", function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global DelTags Redirect ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global DelTags Redirect Photo');
    @unlink($image);

    $addResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'add_tags',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'add_tags' => ['~~1~~'],
    ]);
    expect($addResult['status'])->toBe(200);

    // Fixture tag 1 ('nature'), same sentinel format as this suite's other
    // tag tests. Stores $_SESSION['bulk_manager_filter']['tags'] = [1].
    $filterResult = bmPost($page, [
        'submitFilter' => '1',
        'filter_tags_use' => '1',
        'filter_tags' => ['~~1~~'],
        'tag_mode' => 'AND',
    ]);
    expect($filterResult['status'])->toBe(200);

    // del_tags redirects when the removed tag ids intersect
    // $bulk_manager_filter['tags'] -- opaque status-0 redirect, same as above.
    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'del_tags',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'del_tags' => ['1'],
    ]);
    expect($result['status'])->toBe(0);
});

it('redirects after associate when the active filter is the "no_album" prefilter', function (): void {
    $page = H::loginAsAdmin($this);
    $sourceAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Associate NoAlbum Source ' . uniqid(),
    ]);
    $sourceResult = $sourceAlbum['result'] ?? null;
    if (! is_array($sourceResult) || ! is_numeric($sourceResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($sourceAlbum, true));
    }
    $targetAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Associate NoAlbum Target ' . uniqid(),
    ]);
    $targetResult = $targetAlbum['result'] ?? null;
    if (! is_array($targetResult) || ! is_numeric($targetResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($targetAlbum, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $sourceResult['id'], 'Batch Global Associate NoAlbum Photo');
    @unlink($image);

    $filterResult = bmPost($page, [
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'no_album',
    ]);
    expect($filterResult['status'])->toBe(200);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'associate',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'associate' => [(string) $targetResult['id']],
    ]);
    expect($result['status'])->toBe(0);
});

it('redirects after associate when the active filter is "no_virtual_album" and the target album is virtual', function (): void {
    $page = H::loginAsAdmin($this);
    $sourceAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Associate NoVirtual Source ' . uniqid(),
    ]);
    $sourceResult = $sourceAlbum['result'] ?? null;
    if (! is_array($sourceResult) || ! is_numeric($sourceResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($sourceAlbum, true));
    }
    // An album created via pwg.categories.add always has a NULL `dir`
    // column (confirmed live -- only filesystem-synchronized albums get a
    // real directory), so this is a genuine "no_virtual_album" match, not
    // a DB fixture hack.
    $targetAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Associate NoVirtual Target ' . uniqid(),
    ]);
    $targetResult = $targetAlbum['result'] ?? null;
    if (! is_array($targetResult) || ! is_numeric($targetResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($targetAlbum, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $sourceResult['id'], 'Batch Global Associate NoVirtual Photo');
    @unlink($image);

    $filterResult = bmPost($page, [
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'no_virtual_album',
    ]);
    expect($filterResult['status'])->toBe(200);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'associate',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'associate' => [(string) $targetResult['id']],
    ]);
    expect($result['status'])->toBe(0);
});

it('redirects after move when the active filter is the "no_album" prefilter', function (): void {
    $page = H::loginAsAdmin($this);
    $sourceAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Move NoAlbum Source ' . uniqid(),
    ]);
    $sourceResult = $sourceAlbum['result'] ?? null;
    if (! is_array($sourceResult) || ! is_numeric($sourceResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($sourceAlbum, true));
    }
    $targetAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Move NoAlbum Target ' . uniqid(),
    ]);
    $targetResult = $targetAlbum['result'] ?? null;
    if (! is_array($targetResult) || ! is_numeric($targetResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($targetAlbum, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $sourceResult['id'], 'Batch Global Move NoAlbum Photo');
    @unlink($image);

    $filterResult = bmPost($page, [
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'no_album',
    ]);
    expect($filterResult['status'])->toBe(200);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'move',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'move' => (string) $targetResult['id'],
    ]);
    expect($result['status'])->toBe(0);
});

it('redirects after move when the active filter is "no_virtual_album" and the move target is virtual', function (): void {
    $page = H::loginAsAdmin($this);
    $sourceAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Move NoVirtual Source ' . uniqid(),
    ]);
    $sourceResult = $sourceAlbum['result'] ?? null;
    if (! is_array($sourceResult) || ! is_numeric($sourceResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($sourceAlbum, true));
    }
    $targetAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Move NoVirtual Target ' . uniqid(),
    ]);
    $targetResult = $targetAlbum['result'] ?? null;
    if (! is_array($targetResult) || ! is_numeric($targetResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($targetAlbum, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $sourceResult['id'], 'Batch Global Move NoVirtual Photo');
    @unlink($image);

    $filterResult = bmPost($page, [
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'no_virtual_album',
    ]);
    expect($filterResult['status'])->toBe(200);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'move',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'move' => (string) $targetResult['id'],
    ]);
    expect($result['status'])->toBe(0);
});

it('redirects after move when the active category filter no longer matches the move target', function (): void {
    $page = H::loginAsAdmin($this);
    $sourceAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Move CatMismatch Source ' . uniqid(),
    ]);
    $sourceResult = $sourceAlbum['result'] ?? null;
    if (! is_array($sourceResult) || ! is_numeric($sourceResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($sourceAlbum, true));
    }
    $filterAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Move CatMismatch Filter ' . uniqid(),
    ]);
    $filterResultAlbum = $filterAlbum['result'] ?? null;
    if (! is_array($filterResultAlbum) || ! is_numeric($filterResultAlbum['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($filterAlbum, true));
    }
    $targetAlbum = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Move CatMismatch Target ' . uniqid(),
    ]);
    $targetResult = $targetAlbum['result'] ?? null;
    if (! is_array($targetResult) || ! is_numeric($targetResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($targetAlbum, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $sourceResult['id'], 'Batch Global Move CatMismatch Photo');
    @unlink($image);

    // filter_category (not filter_prefilter) -- $bulk_manager_filter['category']
    // set to $filterResultAlbum's id, distinct from the move target below.
    $filterSubmit = bmPost($page, [
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => (string) $filterResultAlbum['id'],
    ]);
    expect($filterSubmit['status'])->toBe(200);

    // move's 3rd elseif: no prefilter matched above, but the active
    // 'category' filter differs from the move target -> redirect.
    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'move',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'move' => (string) $targetResult['id'],
    ]);
    expect($result['status'])->toBe(0);
});

it('redirects after a level change when the new level is lower than the active level filter', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Level Redirect ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global Level Redirect Photo');
    @unlink($image);

    // $bulk_manager_filter['level'] = '4' (a real available permission
    // level -- CurrentConfig::availablePermissionLevels()'s own default
    // [0, 1, 2, 4, 8]).
    $filterResult = bmPost($page, [
        'submitFilter' => '1',
        'filter_level_use' => '1',
        'filter_level' => '4',
    ]);
    expect($filterResult['status'])->toBe(200);

    // '2' < '4' -- BatchManagerGlobalPageRenderer's own level action
    // compares $post['level'] against $bulk_manager_filter['level'] as
    // numeric strings (PHP's own loose `<` does numeric comparison here).
    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'level',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'level' => '2',
    ]);
    expect($result['status'])->toBe(0);
});

it('passes representative_ext through to the derivative-deletion payload when the image has one', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Del Deriv RepExt ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global Del Deriv RepExt Photo');
    @unlink($image);

    // representative_ext models a non-image original (pdf/video/psd/...)
    // whose derivatives are generated from a representative jpg -- direct
    // DB write is test SETUP here (there is no admin UI path to make a
    // plain jpg upload carry a representative_ext), not an assertion on
    // the DB; the assertion below is on the real HTTP response.
    $db = bmDbConnect();
    H::dbQuery($db, sprintf("UPDATE images SET representative_ext = 'jpg' WHERE id = %d", $imageId));
    H::dbClose($db);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'delete_derivatives',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'del_derivatives_type' => ['thumb'],
    ]);

    expect($result['status'])->toBe(200);
    H::assertNoServerErrors($page, 'batch_manager delete_derivatives with representative_ext');
});

it('falls back to the hardcoded 20-per-page default when the configured page size is not one of the allowed presets', function (): void {
    $snapshot = H::snapshotConfig(['batch_manager_images_per_page_global']);
    // 15 is not one of {20, 50, 100} -- BatchManagerGlobalPageRenderer's own
    // page-size resolution deliberately ignores an out-of-range configured
    // value and falls back to the hardcoded default of 20 rather than
    // trusting it verbatim.
    H::setConfigValue('batch_manager_images_per_page_global', H::jsonEncode(15));

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', [
            'name' => 'Batch Global BadPageSize ' . uniqid(),
        ]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, (int) $albumResult['id'], 'Batch Global BadPageSize Photo');
        @unlink($image);

        // No `display=` GET param on this request, so displayRequested is
        // false and the invalid config value drives the nb_images
        // resolution -- filtering by this real category (rather than the
        // default caddie prefilter) guarantees cat_elements_id is non-empty,
        // so the resolved nb_images value is actually consumed by the real
        // pagination/thumbnail query below it, not merely computed and
        // discarded.
        $filterResult = bmPost($page, [
            'submitFilter' => '1',
            'filter_category_use' => '1',
            'filter_category' => (string) $albumResult['id'],
        ]);

        expect($filterResult['status'])->toBe(200);
        expect($filterResult['body'])->toContain('Batch Global BadPageSize Photo');
        expect($filterResult['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Two different real JPEGs (distinct pixel content -> distinct md5sum, so
 * UploadService::addUploadedFile()'s own upload_detect_duplicate
 * short-circuit -- which silently returns the SAME existing image_id
 * for byte-identical uploads instead of creating a new row -- never kicks
 * in) written under the SAME basename in two different temp directories.
 * CURLFile's own postname (uploadPhotoViaApi()'s 3rd \CURLFile argument)
 * is basename($imagePath), so both uploads report the identical filename
 * to pwg.images.addSimple, and UploadService::addUploadedFile() stores
 * that verbatim in images.file (confirmed by reading the source:
 * `'file' => $original_filename ?? basename($file_path)`) -- producing a
 * real `file`-column duplicate pair without ever touching the DB by hand.
 */
function bmMakeNamedTestImage(string $basename): string
{
    $dir = sys_get_temp_dir() . '/pwg_batch_dup_' . uniqid('', true);
    if (! mkdir($dir, 0777, true) && ! is_dir($dir)) {
        throw new RuntimeException("failed to create temp dir {$dir}");
    }
    $path = $dir . '/' . $basename;

    $img = imagecreatetruecolor(200, 150);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $bg = imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
    if ($bg === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefill($img, 0, 0, $bg);
    imagejpeg($img, $path, 80);

    return $path;
}

it('orders thumbnails by the duplicate-detection fields when the active filter is "duplicates"', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Batch Global Duplicates ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $basename = 'batch-dup-' . uniqid() . '.jpg';
    $imagePathA = bmMakeNamedTestImage($basename);
    $imagePathB = bmMakeNamedTestImage($basename);
    H::uploadPhotoViaApi($imagePathA, $albumId, 'Batch Duplicates A ' . uniqid());
    H::uploadPhotoViaApi($imagePathB, $albumId, 'Batch Duplicates B ' . uniqid());
    @unlink($imagePathA);
    @rmdir(dirname($imagePathA));
    @unlink($imagePathB);
    @rmdir(dirname($imagePathB));

    // display=all so the whole (globally-scoped, not album-scoped --
    // FilterResolver's own duplicate-detection query groups by `file`
    // across all of images) duplicate set renders on one page,
    // regardless of how many unrelated duplicate groups already exist.
    $result = H::adminPost($page, '/admin.php?page=batch_manager&display=all', [
        'pwg_token' => H::pwgToken($page),
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'duplicates',
        'filter_duplicates_filename' => '1',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Batch Duplicates A');
    expect($result['body'])->toContain('Batch Duplicates B');
    expect($result['body'])->not->toContain('Fatal error');
});
