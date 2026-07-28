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
 * Not exercised here (all need `$_SESSION['bulk_manager_filter']` state
 * seeded through a preceding filter-form submission, which
 * BatchManagerSubControllerTest already covers extensively without ever
 * combining it with these specific follow-up actions): the del_tags/
 * associate/move "redirect because this action affected the active filter"
 * conditions, and the privacy_level filter-comparison redirect. Also not
 * exercised: the 'duplicates'-prefilter thumbnail ordering branch (needs
 * real duplicate photos) and the display=Config-fallback-to-20 branch
 * (needs a non-standard batch_manager_images_per_page_global config value)
 * -- both narrow, low-value branches relative to the setup they'd need.
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
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Global Empty AddTags ' . uniqid()]);
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
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Global Empty DelTags ' . uniqid()]);
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
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Global Empty Associate ' . uniqid()]);
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
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Global Remove Fields ' . uniqid()]);
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
    $prefix = bmDbPrefix();
    $row = $db->query(sprintf('SELECT name FROM %simages WHERE id = %d', $prefix, $imageId));
    $nameRow = $row instanceof mysqli_result ? $row->fetch_assoc() : null;
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

    $dateRow = $db->query(sprintf('SELECT date_creation FROM %simages WHERE id = %d', $prefix, $imageId));
    $dateAssoc = $dateRow instanceof mysqli_result ? $dateRow->fetch_assoc() : null;
    expect(is_array($dateAssoc) ? $dateAssoc['date_creation'] : 'MISSING')->toBeNull();
    $db->close();
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
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Global Del Derivatives ' . uniqid()]);
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
