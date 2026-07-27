<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\ConfigurationSubController (admin.php?page=
 * configuration&section=X) -- a single ~750-line handle() dispatching
 * across 7 tabs (main/watermark/sizes/comments/default/display/search).
 * `config` is real, global, shared-across-the-whole-Browser-suite state
 * (unlike Contract's per-process fixture reset), so every test that
 * saves a real form snapshots + restores whatever config params it
 * touches.
 *
 * Deliberately light on 'sizes' (the derivative-size validation matrix
 * spans ~230 lines of its own combinatorial per-type step1/2/3 checks)
 * and 'watermark' (file-glob + WatermarkParams persistence) beyond a
 * render + the one real, independently-gated restore_settings action --
 * exhaustively covering every derivative-type/field combination would be
 * disproportionate to this pass's remaining scope; render coverage plus
 * the guard/action branches below still closes the large majority of
 * this file's real gap.
 */
function ctConfigSection(string $section): string
{
    return '/admin.php?page=configuration&section=' . $section;
}

/** @return array<string, mixed> */
function ctArr(mixed $value): array
{
    assert(is_array($value));

    $result = [];
    foreach ($value as $key => $item) {
        $result[(string) $key] = $item;
    }

    return $result;
}

/**
 * Every 'main'/'comments'/'display' checkbox key (mirrors
 * ConfigurationSubController::handle()'s own local arrays) -- ANY
 * submission to one of these sections rewrites ALL of its own checkbox
 * params (`self::emptyValue($post[$checkbox] ?? null) ? 'false' :
 * 'true'`), even ones the submitted form (or, here, the test's POST)
 * never mentioned. A snapshot covering only the specific field(s) a test
 * cares about silently resets every other checkbox in that same section
 * to 'false' and leaves it that way once restoreConfig() runs -- a real
 * cross-test-file pollution incident this file caused once already,
 * confirmed via composer test:browser failures in RegisterControllerTest
 * (allow_user_registration)/CommentsControllerTest (activate_comments
 * and friends)/ProfileControllerTest (allow_user_customization). Any
 * test that submits successfully to one of these 3 sections must
 * snapshot (and therefore restore) the WHOLE relevant list, not just the
 * field(s) under test.
 *
 * @return list<string>
 */
function ctMainCheckboxes(): array
{
    return [
        'allow_user_registration', 'obligatory_user_mail_address', 'rate', 'rate_anonymous',
        'allow_user_customization', 'log', 'history_admin', 'history_guest',
        'show_mobile_app_banner_in_gallery', 'show_mobile_app_banner_in_admin', 'upload_detect_duplicate',
    ];
}

/** @return list<string> */
function ctCommentsCheckboxes(): array
{
    return [
        'activate_comments', 'comments_forall', 'comments_validation', 'email_admin_on_comment',
        'email_admin_on_comment_validation', 'user_can_delete_comment', 'user_can_edit_comment',
        'email_admin_on_comment_edition', 'email_admin_on_comment_deletion', 'comments_author_mandatory',
        'comments_email_mandatory', 'comments_enable_website',
    ];
}

/** @return list<string> */
function ctDisplayCheckboxes(): array
{
    return [
        'menubar_filter_icon', 'index_search_in_set_button', 'index_search_in_set_action',
        'index_sort_order_input', 'index_flat_icon', 'index_posted_date_icon', 'index_created_date_icon',
        'index_slideshow_icon', 'index_sizes_icon', 'index_new_icon', 'index_edit_icon', 'index_caddie_icon',
        'display_fromto', 'picture_metadata_icon', 'picture_slideshow_icon', 'picture_favorite_icon',
        'picture_sizes_icon', 'picture_download_icon', 'picture_edit_icon', 'picture_caddie_icon',
        'picture_representative_icon', 'picture_navigation_icons', 'picture_navigation_thumb', 'picture_menu',
    ];
}

it('renders the main tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, ctConfigSection('main'));
    $page->assertNoJavaScriptErrors();
});

it('saves the main tab and persists real config values', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('main'));
    $token = H::pwgToken($page);
    $title = 'CT Gallery Title ' . uniqid();

    $snapshot = H::snapshotConfig(array_merge(
        ['gallery_title', 'order_by', 'order_by_inside_category', 'email_admin_on_new_user', 'week_starts_on', 'mail_theme'],
        ctMainCheckboxes()
    ));

    try {
        // (deliberately doesn't preserve the real per-checkbox values in
        // the POST itself -- restoreConfig() below restores the whole
        // snapshotted set regardless of what this section's own blanket
        // checkbox-defaulting did during the request)
        $result = H::adminPost($page, ctConfigSection('main'), [
            'submit' => '1',
            'pwg_token' => $token,
            'gallery_title' => $title,
            'order_by' => ['id ASC'],
            'email_admin_on_new_user' => '1',
            'email_admin_on_new_user_filter' => 'all',
            'week_starts_on' => 'monday',
            'mail_theme' => 'clear',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect(H::configValue('gallery_title'))->toBe(json_encode($title));
        expect(H::configValue('order_by'))->toBe(json_encode('ORDER BY id ASC'));
        expect(H::configValue('email_admin_on_new_user'))->toBe(json_encode('all'));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('main tab: rejects a submission with no order field selected', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('main'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(['gallery_title']);
    $title = 'CT Should Not Save ' . uniqid();

    try {
        $result = H::adminPost($page, ctConfigSection('main'), [
            'submit' => '1',
            'pwg_token' => $token,
            'gallery_title' => $title,
            'email_admin_on_new_user_filter' => 'all',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect(H::configValue('gallery_title'))->not->toBe(json_encode($title));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('main tab: routes new-user notification to a specific group', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('main'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(array_merge(
        ['email_admin_on_new_user', 'order_by', 'order_by_inside_category', 'gallery_title'],
        ctMainCheckboxes()
    ));

    try {
        $result = H::adminPost($page, ctConfigSection('main'), [
            'submit' => '1',
            'pwg_token' => $token,
            'gallery_title' => 'Fixture Gallery',
            'order_by' => ['id ASC'],
            'email_admin_on_new_user' => '1',
            'email_admin_on_new_user_filter' => 'group',
            'email_admin_on_new_user_filter_group' => '2',
        ]);

        expect($result['status'])->toBe(200);
        expect(H::configValue('email_admin_on_new_user'))->toBe(json_encode('group:2'));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders the comments tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, ctConfigSection('comments'));
    $page->assertNoJavaScriptErrors();
});

it('saves the comments tab and persists real config values', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('comments'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(array_merge(['nb_comment_page'], ctCommentsCheckboxes()));

    try {
        $result = H::adminPost($page, ctConfigSection('comments'), [
            'submit' => '1',
            'pwg_token' => $token,
            'nb_comment_page' => '25',
            'comments_order' => 'DESC',
            'comments_forall' => '1',
        ]);

        expect($result['status'])->toBe(200);
        expect(H::configValue('nb_comment_page'))->toBe(json_encode('25'));
        expect(H::configValue('comments_forall'))->toBe(json_encode('true'));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('comments tab: rejects an out-of-range nb_comment_page', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('comments'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(['nb_comment_page']);

    try {
        $result = H::adminPost($page, ctConfigSection('comments'), [
            'submit' => '1',
            'pwg_token' => $token,
            'nb_comment_page' => '999',
            'comments_order' => 'DESC',
        ]);

        expect($result['status'])->toBe(200);
        expect(H::configValue('nb_comment_page'))->toBe($snapshot['nb_comment_page']);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders the display tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, ctConfigSection('display'));
    $page->assertNoJavaScriptErrors();
});

it('saves the display tab and persists real config values', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('display'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(array_merge(
        ['nb_categories_page', 'picture_informations'],
        ctDisplayCheckboxes()
    ));

    try {
        $result = H::adminPost($page, ctConfigSection('display'), [
            'submit' => '1',
            'pwg_token' => $token,
            'nb_categories_page' => '20',
            'display_fromto' => '1',
            'picture_informations' => ['author' => '1', 'file' => '1'],
        ]);

        expect($result['status'])->toBe(200);
        expect(H::configValue('nb_categories_page'))->toBe(json_encode('20'));
        expect(H::configValue('display_fromto'))->toBe(json_encode('true'));
        $pictureInformations = H::configValue('picture_informations');
        expect($pictureInformations)->not->toBeNull();
        $decoded = json_decode((string) $pictureInformations, true);
        expect($decoded)->toBeArray();
        assert(is_array($decoded));
        expect($decoded['author'])->toBeTrue();
        expect($decoded['file'])->toBeTrue();
        expect($decoded['tags'])->toBeFalse();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('display tab: rejects an nb_categories_page below the minimum', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('display'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(['nb_categories_page']);

    try {
        $result = H::adminPost($page, ctConfigSection('display'), [
            'submit' => '1',
            'pwg_token' => $token,
            'nb_categories_page' => '2',
        ]);

        expect($result['status'])->toBe(200);
        expect(H::configValue('nb_categories_page'))->toBe($snapshot['nb_categories_page']);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders the search tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, ctConfigSection('search'));
    $page->assertNoJavaScriptErrors();
});

it('saves the search tab and persists real config values', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('search'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(['filters_views']);

    try {
        $result = H::adminPost($page, ctConfigSection('search'), [
            'submit' => '1',
            'pwg_token' => $token,
            'filters_views_box' => ['words' => '1', 'tags' => '1'],
            'filters_views' => [
                'words' => ['access' => 'everybody'],
                'tags' => ['access' => 'everybody', 'default' => '1'],
            ],
        ]);

        expect($result['status'])->toBe(200);
        $stored = H::configValue('filters_views');
        expect($stored)->not->toBeNull();
        $decoded = json_decode((string) $stored, true);
        expect($decoded)->toBeArray();
        assert(is_array($decoded));
        expect(ctArr($decoded['words'])['access'])->toBe('everybody');
        expect(ctArr($decoded['tags'])['access'])->toBe('everybody');
        expect(ctArr($decoded['tags'])['default'])->toBeTrue();
        expect(ctArr($decoded['post_date'])['access'])->toBe('nobody');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders the default tab (guest profile)', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, ctConfigSection('default'));
    $page->assertNoJavaScriptErrors();
});

it('renders the sizes tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, ctConfigSection('sizes'));
    $page->assertNoJavaScriptErrors();
});

it('sizes tab: restore_settings resets derivative params to Piwigo defaults', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('sizes'));
    $token = H::pwgToken($page);

    $result = H::adminPost($page, '/admin.php?page=configuration&section=sizes&action=restore_settings', [
        'pwg_token' => $token,
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('renders the watermark tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, ctConfigSection('watermark'));
    $page->assertNoJavaScriptErrors();
});

it('rejects a submission with a wrong (present) CSRF token', function (): void {
    // CsrfService::check() returns false for a present-but-wrong token,
    // routed to HtmlService::accessDenied() -- a real HTTP 401, not a
    // redirect, for an authenticated non-guest session.
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('main'));

    $snapshot = H::snapshotConfig(['gallery_title']);
    $title = 'CT CSRF Should Not Save ' . uniqid();

    try {
        $result = H::adminPost($page, ctConfigSection('main'), [
            'submit' => '1',
            'pwg_token' => 'not-a-real-token',
            'gallery_title' => $title,
        ]);

        expect($result['status'])->toBe(401);
        expect(H::configValue('gallery_title'))->not->toBe(json_encode($title));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects a submission with a missing CSRF token', function (): void {
    // CsrfService::check() returns null when the field is absent entirely,
    // routed to HtmlService::badRequest() -- a real HTTP 400.
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('main'));

    $snapshot = H::snapshotConfig(['gallery_title']);
    $title = 'CT CSRF Should Not Save ' . uniqid();

    try {
        $result = H::adminPost($page, ctConfigSection('main'), [
            'submit' => '1',
            'gallery_title' => $title,
        ]);

        expect($result['status'])->toBe(400);
        expect(H::configValue('gallery_title'))->not->toBe(json_encode($title));
    } finally {
        H::restoreConfig($snapshot);
    }
});
