<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * CatPermPageRenderer (admin.php?page=album&cat_id=X&tab=permissions, the
 * "permissions" tab of the "album" page) -- 0% coverage before this file.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): piwigo_group_access grants
 * all 3 groups (1 "Editors", 2 "Reviewers", 3 "Guests") access to category 1
 * "Sample Album", but only group 1 access to category 2 "Nested Sub Album" --
 * so asserting the exact granted-group-id set for each category (not just
 * "some groups are granted") catches a renderer that queried the wrong
 * category's group_access rows. piwigo_user_access has no rows at all in
 * this fixture, so users_selected is always empty regardless of category.
 */
afterEach(function (): void {
    H::setCategoryPrivate(2, private: false);
});

it('shows the public status and all 3 granted groups for category 1', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=permissions');
    $page->assertNoJavaScriptErrors();

    $page->assertRadioSelected('status', 'public');
    $page->assertRadioNotSelected('status', 'private');

    $groupsSelected = $page->attribute('[data-selectize=groups]', 'data-value');
    expect($groupsSelected)->not->toBeNull();
    $decodedGroups = json_decode((string) $groupsSelected, true);
    if (! is_array($decodedGroups)) {
        throw new \RuntimeException('expected an array from data-value JSON, got: ' . var_export($decodedGroups, true));
    }
    sort($decodedGroups);
    expect($decodedGroups)->toBe([1, 2, 3]);

    $usersSelected = $page->attribute('[data-selectize=users]', 'data-value');
    expect(json_decode((string) $usersSelected, true))->toBe([]);
});

it('shows the private status and the single granted group for category 2', function (): void {
    H::setCategoryPrivate(2, private: true);

    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=2&tab=permissions');
    $page->assertNoJavaScriptErrors();

    $page->assertRadioSelected('status', 'private');
    $page->assertRadioNotSelected('status', 'public');

    $groupsSelected = $page->attribute('[data-selectize=groups]', 'data-value');
    expect(json_decode((string) $groupsSelected, true))->toBe([1]);
});
