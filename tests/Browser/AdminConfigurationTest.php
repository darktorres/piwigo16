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
 */
function ctConfigSection(string $section): string
{
    return '/admin.php?page=configuration&section=' . $section;
}

/**
 * ImageStdParams::get_default_sizes()'s own w/h values, in ImageStdParams::
 * get_all_types() order -- strictly ascending in both w and h, which
 * processSizes()'s step-2 validation (each type's w/h must exceed the
 * previous enabled type's) requires. Mirrors the real configuration_sizes.tpl
 * form, which always posts a full d[type][...] row per type (one row per
 * ImageStdParams::get_all_types() entry, unconditionally rendered).
 *
 * @return array<string, array{w: int, h: int}>
 */
function ctDefaultDerivativeSizes(): array
{
    return [
        'square' => ['w' => 120, 'h' => 120],
        'thumb' => ['w' => 144, 'h' => 144],
        '2small' => ['w' => 240, 'h' => 240],
        'xsmall' => ['w' => 432, 'h' => 324],
        'small' => ['w' => 576, 'h' => 432],
        'medium' => ['w' => 792, 'h' => 594],
        'large' => ['w' => 1008, 'h' => 756],
        'xlarge' => ['w' => 1224, 'h' => 918],
        'xxlarge' => ['w' => 1656, 'h' => 1242],
        '3xlarge' => ['w' => 2232, 'h' => 1674],
        '4xlarge' => ['w' => 3000, 'h' => 2250],
    ];
}

/**
 * @param array<string, array{w: int, h: int}> $overrides per-type w/h
 *   overrides layered on top of ctDefaultDerivativeSizes() (for tests that
 *   need one out-of-order/invalid type while keeping the rest valid).
 * @return array<string, array{enabled: string, w: string, h: string, sharpen: string}>
 */
function ctDerivativesPayload(array $overrides = []): array
{
    $payload = [];
    foreach (ctDefaultDerivativeSizes() as $type => $size) {
        $size = $overrides[$type] ?? $size;
        $payload[$type] = [
            'enabled' => '1',
            'w' => (string) $size['w'],
            'h' => (string) $size['h'],
            'sharpen' => '0',
        ];
    }

    return $payload;
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

it('saves the default tab (guest profile) and persists real user_infos values', function (): void {
    // ConfigurationSubController's 'default' case is a thin wrapper
    // around Piwigo\Controller\ProfileFormHandler::saveFromPost() applied
    // to the guest user -- guest is a "special user"
    // (ProfileFormHandler unsets username/mail_address/password/theme/
    // language from $post for it, overriding theme/language internally),
    // so those never need to be submitted here; nb_image_page/
    // recent_period are both required by saveFromPost's own validation
    // once AdminContext::isActive() (always true under admin.php).
    // `expand` is NOT unset/defaulted for the special user, though --
    // profile_content.tpl's real {html_radios name='expand' ...} always
    // submits it from a real browser (a radio group, never absent like a
    // checkbox), and massUpdate()'s own generated UPDATE always includes
    // every column in $fields regardless of whether $data has the key --
    // omitting it here reproduces a genuine `Column 'expand' cannot be
    // null` DB crash (confirmed live), same class of gap as this admin
    // form's own established "send the whole section" convention. This
    // data lives on guest's own piwigo_user_infos row, not the config
    // table -- same direct-mysqli snapshot/restore shape as
    // NotificationByMailSubControllerTest's own DB helpers, since
    // H::snapshotConfig()/restoreConfig() only ever touch `config`.
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('default'));
    $token = H::pwgToken($page);

    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $guestRow = H::fetchAssocOrFail($db, 'SELECT nb_image_page, recent_period FROM ' . $prefix . 'user_infos WHERE user_id = 2');

    try {
        $result = H::adminPost($page, ctConfigSection('default'), [
            'validate' => '1',
            'pwg_token' => $token,
            'nb_image_page' => '25',
            'recent_period' => '10',
            'expand' => 'false',
            'show_nb_hits' => 'false',
            'show_nb_comments' => 'false',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect($result['body'])->toContain('Information data registered in database');

        $updated = H::fetchAssocOrFail($db, 'SELECT nb_image_page, recent_period FROM ' . $prefix . 'user_infos WHERE user_id = 2');
        expect((int) $updated['nb_image_page'])->toBe(25);
        expect((int) $updated['recent_period'])->toBe(10);
    } finally {
        $db->query(
            'UPDATE ' . $prefix . 'user_infos SET nb_image_page = ' . (int) $guestRow['nb_image_page']
            . ', recent_period = ' . (int) $guestRow['recent_period'] . ' WHERE user_id = 2'
        );
        $db->close();
    }
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

it('sizes tab: saves every derivative type with valid ascending sizes', function (): void {
    $snapshot = H::snapshotConfig(['derivatives', 'disabled_derivatives']);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => ctDerivativesPayload(),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Your configuration settings are saved');
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('sizes tab: rejects an out-of-range resize_quality without saving', function (): void {
    $snapshot = H::snapshotConfig(['derivatives', 'disabled_derivatives']);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '10',
            'd' => ctDerivativesPayload(),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Your configuration settings are saved');
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('sizes tab: rejects a thumb size that is not strictly larger than the square size', function (): void {
    $snapshot = H::snapshotConfig(['derivatives', 'disabled_derivatives']);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        // thumb's max(w,h) must exceed square's w (120) -- 100 is smaller,
        // exercising processSizes()'s THUMB-specific max(w,h) <= prev_w branch.
        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => ctDerivativesPayload(['thumb' => ['w' => 100, 'h' => 100]]),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Your configuration settings are saved');
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('sizes tab: rejects a non-thumb size that is not strictly larger than the previous type', function (): void {
    $snapshot = H::snapshotConfig(['derivatives', 'disabled_derivatives']);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        // 'small' must exceed 'xsmall' (432x324) in both w and h --
        // exercises processSizes()'s non-THUMB `$v <= $prev_w`/`$prev_h` branch.
        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => ctDerivativesPayload(['small' => ['w' => 100, 'h' => 100]]),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Your configuration settings are saved');
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('sizes tab: leaving a non-required type disabled skips its validation', function (): void {
    $snapshot = H::snapshotConfig(['derivatives', 'disabled_derivatives']);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $payload = ctDerivativesPayload();
        // '4xlarge' is not must_enable (not square/thumb/the configured
        // default derivative size) -- omitting its 'enabled' key disables
        // it and skips step-2 validation entirely, even with an otherwise-
        // invalid (too-small) size posted for it.
        unset($payload['4xlarge']['enabled']);
        $payload['4xlarge']['w'] = '1';
        $payload['4xlarge']['h'] = '1';

        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => $payload,
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Your configuration settings are saved');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders the watermark tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, ctConfigSection('watermark'));
    $page->assertNoJavaScriptErrors();
});

it('saves the watermark tab with a fixed topleft position, persisting the derived xpos/ypos', function (): void {
    $snapshot = H::snapshotConfig(['derivatives']);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('watermark'), [
            'pwg_token' => $token,
            'submit' => '1',
            'w' => [
                'file' => '',
                'position' => 'topleft',
                'xpos' => '0',
                'ypos' => '0',
                'xrepeat' => '0',
                'yrepeat' => '0',
                'opacity' => '50',
                'minw' => '10',
                'minh' => '10',
            ],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Your configuration settings are saved');

        // w[position] is a radio group (input[type=radio]), not a
        // <select>/<option> pair -- confirmed live via a real raw POST +
        // GET round trip.
        $page = H::navigateOk($page, ctConfigSection('watermark'));
        $page->assertPresent('input[name="w[position]"][value="topleft"][checked]');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('saves a custom watermark position with explicit xpos/ypos/repeat', function (): void {
    $snapshot = H::snapshotConfig(['derivatives']);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('watermark'), [
            'pwg_token' => $token,
            'submit' => '1',
            'w' => [
                'file' => '',
                'position' => 'custom',
                'xpos' => '25',
                'ypos' => '75',
                'xrepeat' => '1',
                'yrepeat' => '0',
                'opacity' => '80',
                'minw' => '10',
                'minh' => '10',
            ],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Your configuration settings are saved');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects an out-of-range watermark opacity, without saving', function (): void {
    $snapshot = H::snapshotConfig(['derivatives']);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('watermark'), [
            'pwg_token' => $token,
            'submit' => '1',
            'w' => [
                'file' => '',
                'position' => 'middle',
                'xpos' => '50',
                'ypos' => '50',
                'xrepeat' => '0',
                'yrepeat' => '0',
                'opacity' => '0',
                'minw' => '10',
                'minh' => '10',
            ],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Your configuration settings are saved');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects an out-of-range watermark xpos, without saving', function (): void {
    $snapshot = H::snapshotConfig(['derivatives']);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('watermark'), [
            'pwg_token' => $token,
            'submit' => '1',
            'w' => [
                'file' => '',
                'position' => 'custom',
                'xpos' => '150',
                'ypos' => '50',
                'xrepeat' => '0',
                'yrepeat' => '0',
                'opacity' => '50',
                'minw' => '10',
                'minh' => '10',
            ],
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Your configuration settings are saved');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('saves the watermark tab with each named position, deriving the matching xpos/ypos', function (): void {
    $snapshot = H::snapshotConfig(['derivatives']);

    try {
        $page = H::loginAsAdmin($this);

        $cases = [
            'topright' => ['100', '0'],
            'middle' => ['50', '50'],
            'bottomleft' => ['0', '100'],
            'bottomright' => ['100', '100'],
        ];

        foreach ($cases as $position => [$expectedXpos, $expectedYpos]) {
            $token = H::pwgToken($page);

            $result = H::adminPost($page, ctConfigSection('watermark'), [
                'pwg_token' => $token,
                'submit' => '1',
                'w' => [
                    'file' => '',
                    'position' => $position,
                    // deliberately wrong, to prove the switch overwrites them
                    'xpos' => '5',
                    'ypos' => '5',
                    'xrepeat' => '0',
                    'yrepeat' => '0',
                    'opacity' => '50',
                    'minw' => '10',
                    'minh' => '10',
                ],
            ]);

            expect($result['status'])->toBe(200);
            expect($result['body'])->toContain('Your configuration settings are saved');

            $page = H::navigateOk($page, ctConfigSection('watermark'));
            $page->assertPresent('input[name="w[position]"][value="' . $position . '"][checked]');
        }
    } finally {
        H::restoreConfig($snapshot);
    }
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
