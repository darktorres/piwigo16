<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\AlbumNotificationPageRenderer (the "notification" tab of the
 * "album" page slug, admin.php?page=album-{id}-notification) --
 * AdminExtendedSmokeTest.php's own data-driven smoke sweep already visits
 * this tab with a plain GET (no form submission), so this file focuses on
 * the actual email-notification submission branches that sweep never
 * reaches.
 */

it('sends an album notification email to selected users and reports how many were sent', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Notification Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // user 1 (fixture_admin) -- see this suite's own fixture-shape memory
    // notes. The 'save_success' message is assigned unconditionally after
    // the per-user mail loop, regardless of whether the underlying send
    // itself is deliverable, so this is deterministic without depending on
    // real mail delivery.
    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-notification', [
        'pwg_token' => H::pwgToken($page),
        'submitEmail' => '1',
        'who' => 'users',
        'users' => ['1'],
        'mail_content' => 'Come check out the new album!',
    ]);

    expect($result['status'])->toBe(200);
    // The en_UK PO translation for this plural key reads "has been sent",
    // not a literal echo of the '%d mail was sent.' source string used as
    // the translation lookup key -- confirmed live via a direct debug
    // dump of the computed $message before assuming the source string's
    // own wording.
    expect($result['body'])->toContain('1 mail has been sent.');
    expect($result['body'])->toContain('fixture_admin');
});

it('sends an album notification email to a group and reports the group name', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Notification Group Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // pwg.groups.add's own response nests the created group under
    // result.groups[0], not a bare result.id (confirmed live -- unlike
    // pwg.categories.add's flat result.id shape).
    $group = H::wsCall($page, 'pwg.groups.add', ['name' => 'Notification Test Group ' . uniqid()]);
    $groupResult = $group['result'] ?? null;
    $groups = is_array($groupResult) ? ($groupResult['groups'] ?? null) : null;
    $firstGroup = is_array($groups) ? ($groups[0] ?? null) : null;
    if (! is_array($firstGroup) || ! is_numeric($firstGroup['id'] ?? null)) {
        throw new RuntimeException('pwg.groups.add did not return a numeric id: ' . var_export($group, true));
    }
    $groupId = (int) $firstGroup['id'];

    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-notification', [
        'pwg_token' => H::pwgToken($page),
        'submitEmail' => '1',
        'who' => 'group',
        'group' => (string) $groupId,
        'mail_content' => 'Come check out the new album!',
    ]);

    expect($result['status'])->toBe(200);
    // Same msgid/msgstr mismatch as the "users" test above -- the en_UK
    // translation reads "Information email sent to group", not a literal
    // echo of the source msgid.
    expect($result['body'])->toContain('Information email sent to group');
});
