<?php

declare(strict_types=1);

use Piwigo\Image\WatermarkParams;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SizingParams;
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
 * Raw mysqli row values here are `mixed` -- these narrow a fetched
 * column to the numeric type ctDecodedDerivatives() actually needs
 * without PHPStan's Cannot-cast-mixed complaint, falling back to
 * $default only for a genuinely absent/non-numeric column.
 */
function ctIntFromMixed(mixed $value, int $default): int
{
    return is_numeric($value) ? (int) $value : $default;
}

function ctFloatFromMixed(mixed $value, float $default): float
{
    return is_numeric($value) ? (float) $value : $default;
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

/**
 * Reconstructs the 'd'/'q'/'w'/'c' structure, sourced from the real
 * derivative_settings/derivative_size tables ImageStdParams persists to
 * (see its own
 * sizesFromEntities()/watermarkFromJson()/customFromJson() -- this mirrors
 * that same mapping, just against raw mysqli rows instead of Doctrine
 * entities, since this file has no app-container access).
 *
 * @return array{d: array<string, DerivativeParams>, q: int, w: WatermarkParams, c: array<string, int>}
 */
function ctDecodedDerivatives(): array
{
    $snapshot = H::snapshotDerivativeConfig();
    $settings = $snapshot['settings'];
    expect($settings)->not->toBeNull();
    assert(is_array($settings));

    $watermarkJson = $settings['watermark_json'] ?? null;
    $watermarkDecoded = is_string($watermarkJson) ? json_decode($watermarkJson, true) : [];
    $w = new WatermarkParams();
    if (is_array($watermarkDecoded)) {
        $w->file = is_string($watermarkDecoded['file'] ?? null) ? $watermarkDecoded['file'] : $w->file;
        $minSize = $watermarkDecoded['min_size'] ?? null;
        if (is_array($minSize) && isset($minSize[0], $minSize[1])) {
            $w->min_size = [ctIntFromMixed($minSize[0], $w->min_size[0]), ctIntFromMixed($minSize[1], $w->min_size[1])];
        }
        $w->xpos = is_numeric($watermarkDecoded['xpos'] ?? null) ? (int) $watermarkDecoded['xpos'] : $w->xpos;
        $w->ypos = is_numeric($watermarkDecoded['ypos'] ?? null) ? (int) $watermarkDecoded['ypos'] : $w->ypos;
        $w->xrepeat = is_numeric($watermarkDecoded['xrepeat'] ?? null) ? (int) $watermarkDecoded['xrepeat'] : $w->xrepeat;
        $w->yrepeat = is_numeric($watermarkDecoded['yrepeat'] ?? null) ? (int) $watermarkDecoded['yrepeat'] : $w->yrepeat;
        $w->opacity = is_numeric($watermarkDecoded['opacity'] ?? null) ? (int) $watermarkDecoded['opacity'] : $w->opacity;
    }

    $customJson = $settings['custom_json'] ?? null;
    $customDecoded = is_string($customJson) ? json_decode($customJson, true) : [];
    $c = [];
    foreach (is_array($customDecoded) ? $customDecoded : [] as $key => $value) {
        if (is_string($key) && is_numeric($value)) {
            $c[$key] = (int) $value;
        }
    }

    $q = ctIntFromMixed($settings['default_quality'] ?? null, 95);

    $d = [];
    foreach ($snapshot['sizes'] as $row) {
        if (ctIntFromMixed($row['enabled'] ?? null, 0) !== 1) {
            continue;
        }
        $rowName = $row['name'] ?? null;
        $name = is_string($rowName) ? $rowName : '';
        $minWidth = $row['min_width'] ?? null;
        $minHeight = $row['min_height'] ?? null;
        $minSize = $minWidth !== null && $minHeight !== null ? [ctIntFromMixed($minWidth, 0), ctIntFromMixed($minHeight, 0)] : null;

        $params = new DerivativeParams(new SizingParams(
            [ctIntFromMixed($row['max_width'] ?? null, 0), ctIntFromMixed($row['max_height'] ?? null, 0)],
            ctFloatFromMixed($row['max_crop'] ?? null, 0.0),
            $minSize,
        ));
        $params->sharpen = ctFloatFromMixed($row['sharpen'] ?? null, 0.0);
        $params->last_mod_time = ctIntFromMixed($row['last_mod_time'] ?? null, 0);
        $params->type = $name;

        $d[$name] = $params;
    }

    return ['d' => $d, 'q' => $q, 'w' => $w, 'c' => $c];
}

/**
 * Writes back a specific settings/enabled-sizes state -- the counterpart
 * to ctDecodedDerivatives(), for seeding a specific fixture state (a
 * custom-derivative entry, an artificially-old last_mod_time) this page's
 * own POST handlers have no form field to set directly. Only ever touches
 * derivative_settings and the *enabled* partition of derivative_size --
 * disabled rows are left untouched.
 *
 * @param array{d: array<string, DerivativeParams>, q: int, w: WatermarkParams, c: array<string, int>} $decoded
 */
function ctSetDecodedDerivatives(array $decoded): void
{
    H::setDerivativeSettingsRow([
        'default_quality' => $decoded['q'],
        'watermark_json' => H::jsonEncode([
            'file' => $decoded['w']->file,
            'min_size' => $decoded['w']->min_size,
            'xpos' => $decoded['w']->xpos,
            'ypos' => $decoded['w']->ypos,
            'xrepeat' => $decoded['w']->xrepeat,
            'yrepeat' => $decoded['w']->yrepeat,
            'opacity' => $decoded['w']->opacity,
        ]),
        'custom_json' => H::jsonEncode($decoded['c']),
    ]);

    $rows = [];
    foreach ($decoded['d'] as $name => $params) {
        $rows[] = [
            'name' => $name,
            'enabled' => 1,
            'max_width' => $params->sizing->ideal_size[0],
            'max_height' => $params->sizing->ideal_size[1],
            'max_crop' => number_format((float) $params->sizing->max_crop, 4, '.', ''),
            'min_width' => $params->sizing->min_size[0] ?? null,
            'min_height' => $params->sizing->min_size[1] ?? null,
            'sharpen' => number_format($params->sharpen, 4, '.', ''),
            'last_mod_time' => $params->last_mod_time,
        ];
    }
    H::syncEnabledDerivativeSizes($rows);
}

/**
 * Reads derivative_settings.watermark_json's own `file` field directly --
 * watermark config is real JSON, with no unserialize() class-
 * instantiation side effect to avoid.
 */
function ctWatermarkFileFromSettings(string $errorMessage): string
{
    $settings = H::snapshotDerivativeConfig()['settings'];
    $watermarkJson = is_array($settings) ? ($settings['watermark_json'] ?? null) : null;
    $decoded = is_string($watermarkJson) ? json_decode($watermarkJson, true) : null;
    $file = is_array($decoded) ? ($decoded['file'] ?? null) : null;
    if (! is_string($file) || $file === '') {
        throw new RuntimeException($errorMessage);
    }

    return $file;
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
    // data lives on guest's own user_infos row, not the config
    // table -- same direct-mysqli snapshot/restore shape as
    // NotificationByMailSubControllerTest's own DB helpers, since
    // H::snapshotConfig()/restoreConfig() only ever touch `config`.
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('default'));
    $token = H::pwgToken($page);

    $db = H::connect();
    $guestRow = H::fetchAssocOrFail($db, 'SELECT nb_image_page, recent_period FROM user_infos WHERE user_id = 2');

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

        $updated = H::fetchAssocOrFail($db, 'SELECT nb_image_page, recent_period FROM user_infos WHERE user_id = 2');
        expect((int) $updated['nb_image_page'])->toBe(25);
        expect((int) $updated['recent_period'])->toBe(10);
    } finally {
        H::dbQuery($db,
            'UPDATE user_infos SET nb_image_page = ' . (int) $guestRow['nb_image_page']
            . ', recent_period = ' . (int) $guestRow['recent_period'] . ' WHERE user_id = 2'
        );
        H::dbClose($db);
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
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: rejects an out-of-range resize_quality without saving', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: rejects a thumb size that is not strictly larger than the square size', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: rejects a non-thumb size that is not strictly larger than the previous type', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: leaving a non-required type disabled skips its validation', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('renders the watermark tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, ctConfigSection('watermark'));
    $page->assertNoJavaScriptErrors();
});

it('saves the watermark tab with a fixed topleft position, persisting the derived xpos/ypos', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('saves a custom watermark position with explicit xpos/ypos/repeat', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('rejects an out-of-range watermark opacity, without saving', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('rejects an out-of-range watermark xpos, without saving', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('saves the watermark tab with each named position, deriving the matching xpos/ypos', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

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
        H::restoreDerivativeConfig($snapshot);
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

it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::loginAsAdmin($this);
    $username = 'config_ct_admin_' . uniqid();
    $password = 'a-strong-test-password-1';

    $addResult = H::wsCall($page, 'pwg.users.add', [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = wsAddedUserId($addResult);

    $db = H::connect();
    H::dbQuery($db, sprintf("UPDATE user_infos SET status = 'admin' WHERE user_id = %d", $userId));

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        H::assertNoServerErrors($adminPage, 'plain-admin identification page');
        $adminPage = $adminPage->fill('username', $username)->fill('password', $password)->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, ctConfigSection('main'));
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});

it('uploads a real PNG watermark image and rejects a non-PNG upload', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_watermark_upload_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    $curl = static function (string $url, array $fields = []) use ($cookieJar): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($fields !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusResult = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
    $decodedStatus = json_decode($statusResult['body'], true);
    $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
    $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)->not->toBe('');

    $watermarkUrl = $baseUrl . '/' . ltrim(ctConfigSection('watermark'), '/');
    $baseFields = [
        'pwg_token' => $pwgToken,
        'submit' => '1',
        'w[file]' => '',
        'w[position]' => 'topleft',
        'w[xpos]' => '0',
        'w[ypos]' => '0',
        'w[xrepeat]' => '0',
        'w[yrepeat]' => '0',
        'w[opacity]' => '50',
        'w[minw]' => '10',
        'w[minh]' => '10',
    ];

    $pngPath = tempnam(sys_get_temp_dir(), 'pwg_watermark_') . '.png';
    $img = imagecreatetruecolor(20, 20);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($img, $pngPath);

    $jpgPath = tempnam(sys_get_temp_dir(), 'pwg_watermark_') . '.jpg';
    $imgJpg = imagecreatetruecolor(20, 20);
    if ($imgJpg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($imgJpg, $jpgPath);

    try {
        // Wrong file type first: getimagesize()'s own IMAGETYPE_PNG check
        // rejects a real JPEG upload with a real error, without ever
        // writing anything to disk.
        $jpgResult = $curl($watermarkUrl, array_merge($baseFields, [
            'watermarkImage' => new CURLFile($jpgPath, 'image/jpeg', 'not-a-watermark.jpg'),
        ]));
        expect($jpgResult['status'])->toBe(200);
        expect($jpgResult['body'])->toContain('Allowed file types: PNG');

        // Real PNG upload: StorageRegistry::disk('watermarks') actually
        // writes the file, and w[file] gets persisted as a real,
        // root-relative path under local/watermarks/.
        $pngResult = $curl($watermarkUrl, array_merge($baseFields, [
            'watermarkImage' => new CURLFile($pngPath, 'image/png', 'ct-real-watermark.png'),
        ]));
        expect($pngResult['status'])->toBe(200);
        expect($pngResult['body'])->toContain('Your configuration settings are saved');

        // ImageStdParams::save() persists watermark config as real JSON in
        // derivative_settings.watermark_json -- `file` is root-relative to
        // the live, container-bound Paths->root.
        $settings = H::snapshotDerivativeConfig()['settings'];
        $watermarkJson = is_array($settings) ? ($settings['watermark_json'] ?? null) : null;
        $watermarkDecoded = is_string($watermarkJson) ? json_decode($watermarkJson, true) : null;
        $watermarkFile = is_array($watermarkDecoded) ? ($watermarkDecoded['file'] ?? null) : null;
        if (! is_string($watermarkFile) || $watermarkFile === '') {
            throw new RuntimeException('Could not find a watermark file entry in derivative_settings: ' . var_export($settings, true));
        }
        expect($watermarkFile)->not->toBe('');
        expect($watermarkFile)->toContain('watermarks/');

        $uploadedPath = __DIR__ . '/../../' . ltrim($watermarkFile, '/');
        expect(file_exists($uploadedPath))->toBeTrue();
        @unlink($uploadedPath);
    } finally {
        @unlink($cookieJar);
        @unlink($pngPath);
        @unlink($jpgPath);
        H::restoreDerivativeConfig($snapshot);
    }
});

it('main tab: dedups a repeated order_by selection into a single field', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('main'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(array_merge(
        ['order_by', 'order_by_inside_category', 'gallery_title'],
        ctMainCheckboxes()
    ));

    try {
        $result = H::adminPost($page, ctConfigSection('main'), [
            'submit' => '1',
            'pwg_token' => $token,
            'gallery_title' => 'CT Dedup Gallery',
            // The same value submitted twice -- the per-entry dedup loop
            // (handle()'s own `isset($used[$val])` check) must collapse
            // this back down to a single field, not persist a duplicate.
            'order_by' => ['id ASC', 'id ASC'],
            'email_admin_on_new_user_filter' => 'all',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect(H::configValue('order_by'))->toBe(json_encode('ORDER BY id ASC'));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('main tab: a rank-only order_by selection falls back to id ASC while order_by_inside_category keeps rank', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('main'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(array_merge(
        ['order_by', 'order_by_inside_category', 'gallery_title'],
        ctMainCheckboxes()
    ));

    try {
        $result = H::adminPost($page, ctConfigSection('main'), [
            'submit' => '1',
            'pwg_token' => $token,
            'gallery_title' => 'CT Rank Only Gallery',
            'order_by' => ['`rank` ASC'],
            'email_admin_on_new_user_filter' => 'all',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        // '`rank` ASC' is stripped out of $order_by specifically ("there is
        // no rank outside categories"), falling back to the hardcoded
        // 'id ASC' default -- order_by_inside_category has no such
        // restriction and keeps the rank selection untouched, distinguishing
        // the two fields rather than asserting the same value for both.
        expect(H::configValue('order_by'))->toBe(json_encode('ORDER BY id ASC'));
        expect(H::configValue('order_by_inside_category'))->toBe(json_encode('ORDER BY `rank` ASC'));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('main tab: rejects an order_by submission that becomes empty after de-duplication', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('main'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(['order_by', 'gallery_title']);
    $title = 'CT Should Not Save Empty Order ' . uniqid();

    try {
        $result = H::adminPost($page, ctConfigSection('main'), [
            'submit' => '1',
            'pwg_token' => $token,
            'gallery_title' => $title,
            // A non-empty array (so the *outer* emptyValue() check lets it
            // through, unlike the "no order field selected" test above,
            // which omits 'order_by' entirely and never enters this inner
            // branch) whose only entry is itself an empty string -- the
            // per-entry emptyValue() filter drops it, leaving nothing
            // selected: a genuinely different source line.
            'order_by' => [''],
            'email_admin_on_new_user_filter' => 'all',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect($result['body'])->toContain('No order field selected');
        expect(H::configValue('gallery_title'))->not->toBe(json_encode($title));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('main tab: shows the order-by-is-custom notice and disables the selector when order_by_custom is set', function (): void {
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['order_by_custom']);

    try {
        H::setConfigValue('order_by_custom', H::jsonEncode('ORDER BY RAND()'));

        $page = H::navigateOk($page, ctConfigSection('main'));
        $page->assertSee("You can't define a default photo order because you have a custom setting in your local configuration.");
        $page->assertPresent('select[name="order_by[]"][disabled]');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('sizes tab: saves valid original-resize settings alongside derivative sizes', function (): void {
    $derivativeSnapshot = H::snapshotDerivativeConfig();
    $snapshot = H::snapshotConfig([
        'original_resize', 'original_resize_maxwidth', 'original_resize_maxheight', 'original_resize_quality',
    ]);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'original_resize' => '1',
            'original_resize_maxwidth' => '3000',
            'original_resize_maxheight' => '2500',
            'original_resize_quality' => '85',
            'd' => ctDerivativesPayload(),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Your configuration settings are saved');
        // UploadService::saveUploadFormConfig() writes these 4 params via
        // its own raw BatchWriter::massUpdate() call, bypassing
        // ConfigService::confUpdateParam()'s json_encode() -- unlike every
        // other field on this page, these are plain literal strings in the
        // config table, not JSON-quoted.
        expect(H::configValue('original_resize'))->toBe('true');
        expect(H::configValue('original_resize_maxwidth'))->toBe('3000');
        expect(H::configValue('original_resize_maxheight'))->toBe('2500');
        expect(H::configValue('original_resize_quality'))->toBe('85');
    } finally {
        H::restoreConfig($snapshot);
        H::restoreDerivativeConfig($derivativeSnapshot);
    }
});

it('sizes tab: rejects an out-of-range original_resize_maxwidth, redisplaying the submitted fields', function (): void {
    $derivativeSnapshot = H::snapshotDerivativeConfig();
    $snapshot = H::snapshotConfig([
        'original_resize', 'original_resize_maxwidth', 'original_resize_maxheight', 'original_resize_quality',
    ]);

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'original_resize' => '1',
            // Below UploadService::getUploadFormConfig()'s own 500 minimum
            // -- rejected by saveUploadFormConfig()'s range check, which
            // shares processSizes()'s own $errors array and so aborts the
            // whole "sizes" save, not just this one field.
            'original_resize_maxwidth' => '100',
            'original_resize_maxheight' => '2500',
            'original_resize_quality' => '85',
            'd' => ctDerivativesPayload(),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Your configuration settings are saved');
        expect($result['body'])->not->toContain('Fatal error');
        expect(H::configValue('original_resize_maxwidth'))->toBe($snapshot['original_resize_maxwidth']);
    } finally {
        H::restoreConfig($snapshot);
        H::restoreDerivativeConfig($derivativeSnapshot);
    }
});

it('sizes tab: toggles a non-required derivative type off then back on across successive saves', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $page = H::loginAsAdmin($this);

        // Step 1: baseline -- every type enabled.
        $token = H::pwgToken($page);
        $baseline = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => ctDerivativesPayload(),
        ]);
        expect($baseline['status'])->toBe(200);
        expect($baseline['body'])->toContain('Your configuration settings are saved');

        // Step 2: disable '4xlarge' (not must_enable, so omitting its
        // 'enabled' key is enough) -- it was enabled a moment ago, so this
        // hits the "now disabled, before was enabled" branch.
        $disablePayload = ctDerivativesPayload();
        unset($disablePayload['4xlarge']['enabled']);
        $token = H::pwgToken($page);
        $disabled = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => $disablePayload,
        ]);
        expect($disabled['status'])->toBe(200);
        expect($disabled['body'])->toContain('Your configuration settings are saved');

        $page = H::navigateOk($page, ctConfigSection('sizes'));
        $page->assertMissing('input[name="d[4xlarge][enabled]"][checked]');

        // Step 3: re-enable '4xlarge' -- it was disabled a moment ago, so
        // this hits the "now enabled, before was disabled" branch.
        $token = H::pwgToken($page);
        $reenabled = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => ctDerivativesPayload(),
        ]);
        expect($reenabled['status'])->toBe(200);
        expect($reenabled['body'])->toContain('Your configuration settings are saved');

        $page = H::navigateOk($page, ctConfigSection('sizes'));
        $page->assertPresent('input[name="d[4xlarge][enabled]"][checked]');
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('rejects an out-of-range watermark ypos, without saving', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('watermark'), [
            'pwg_token' => $token,
            'submit' => '1',
            'w' => [
                'file' => '',
                'position' => 'custom',
                'xpos' => '50',
                'ypos' => '150',
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
        H::restoreDerivativeConfig($snapshot);
    }
});

it('avoids a watermark filename collision by appending a numbered suffix on a repeat upload', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_watermark_collision_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    $curl = static function (string $url, array $fields = []) use ($cookieJar): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($fields !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => H::ADMIN_USER,
        'password' => H::ADMIN_PASS,
        'login' => 'Login',
    ]);

    $statusResult = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
    $decodedStatus = json_decode($statusResult['body'], true);
    $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
    $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
    $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
    expect($pwgToken)->not->toBe('');

    $watermarkUrl = $baseUrl . '/' . ltrim(ctConfigSection('watermark'), '/');
    $baseFields = [
        'pwg_token' => $pwgToken,
        'submit' => '1',
        'w[file]' => '',
        'w[position]' => 'topleft',
        'w[xpos]' => '0',
        'w[ypos]' => '0',
        'w[xrepeat]' => '0',
        'w[yrepeat]' => '0',
        'w[opacity]' => '50',
        'w[minw]' => '10',
        'w[minh]' => '10',
    ];

    $sharedName = 'ctdupmark' . uniqid();

    $firstPath = tempnam(sys_get_temp_dir(), 'pwg_watermark_') . '.png';
    $img1 = imagecreatetruecolor(20, 20);
    if ($img1 === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($img1, $firstPath);

    $secondPath = tempnam(sys_get_temp_dir(), 'pwg_watermark_') . '.png';
    $img2 = imagecreatetruecolor(20, 20);
    if ($img2 === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($img2, $secondPath);

    $firstStoredPath = null;
    $secondStoredPath = null;

    try {
        $firstResult = $curl($watermarkUrl, array_merge($baseFields, [
            'watermarkImage' => new CURLFile($firstPath, 'image/png', $sharedName . '.png'),
        ]));
        expect($firstResult['status'])->toBe(200);
        expect($firstResult['body'])->toContain('Your configuration settings are saved');

        $firstStoredFile = ctWatermarkFileFromSettings('Could not find a watermark file entry after the first upload');
        expect($firstStoredFile)->toContain($sharedName . '.png');
        expect($firstStoredFile)->not->toContain($sharedName . '-1.png');
        $firstStoredPath = __DIR__ . '/../../' . ltrim($firstStoredFile, '/');
        expect(file_exists($firstStoredPath))->toBeTrue();

        // Same reported original filename again -- getWatermarkFilename()
        // must find the first upload already on disk (via its own
        // glob('watermarks/*.png') existing-files scan) and append a "-1"
        // numbered suffix rather than overwrite it.
        $secondResult = $curl($watermarkUrl, array_merge($baseFields, [
            'watermarkImage' => new CURLFile($secondPath, 'image/png', $sharedName . '.png'),
        ]));
        expect($secondResult['status'])->toBe(200);
        expect($secondResult['body'])->toContain('Your configuration settings are saved');

        $secondStoredFile = ctWatermarkFileFromSettings('Could not find a watermark file entry after the second upload');
        expect($secondStoredFile)->toContain($sharedName . '-1.png');
        $secondStoredPath = __DIR__ . '/../../' . ltrim($secondStoredFile, '/');
        expect(file_exists($secondStoredPath))->toBeTrue();

        // Both files must still exist side by side -- the first was never
        // overwritten by the second.
        expect(file_exists($firstStoredPath))->toBeTrue();
    } finally {
        @unlink($cookieJar);
        @unlink($firstPath);
        @unlink($secondPath);
        if ($firstStoredPath !== null) {
            @unlink($firstStoredPath);
        }
        if ($secondStoredPath !== null) {
            @unlink($secondStoredPath);
        }
        H::restoreDerivativeConfig($snapshot);
    }
});

it('main tab: falls back to "all" new-user notifications when a group filter has no group selected', function (): void {
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
            'gallery_title' => 'CT Group Fallback Gallery',
            'order_by' => ['id ASC'],
            'email_admin_on_new_user' => '1',
            // filter is 'group' (not 'all'), but the group id field is
            // deliberately omitted -- the inner emptyValue() check on
            // email_admin_on_new_user_filter_group must fall back to
            // 'all', not silently produce a bare 'group:' value.
            'email_admin_on_new_user_filter' => 'group',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect(H::configValue('email_admin_on_new_user'))->toBe(json_encode('all'));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('main tab: warns about a deprecated $conf[\'order_by\'] set in a real local configuration file', function (): void {
    $repoRoot = dirname(__DIR__, 2);
    $localConfigPath = $repoRoot . '/local/config/config.inc.php';

    // local/config/ is torres-owned in this dev environment (unlike
    // local/watermarks/, created at runtime by the live www-data-run app --
    // see this file's own watermark-upload tests above), so a plain direct
    // file write is safe here -- the same direct-filesystem-fixture
    // technique BrowserTestHelpers::setCustomLogo() already uses under
    // `local/`, just targeting the actual file orderByIsLocal() itself
    // @include()s (the live, container-bound Paths->local .
    // 'config/config.inc.php'), not a config-table row. Confirmed via a
    // full grep of every real @include site of this exact path
    // (Admin\UserListPageRenderer, Admin\Install\LegacyFileConf, this
    // controller, and BackupService's own file copy) that it is NEVER read
    // into the live app's real runtime $conf during normal request
    // bootstrap (ConfigLoader::applyDefaults()/applyEnvOverrides() are both
    // no-ops) -- writing it here cannot affect any other concurrently-
    // scoped behavior.
    if (file_exists($localConfigPath)) {
        throw new RuntimeException("Refusing to overwrite a pre-existing {$localConfigPath} -- clean up a prior failed run first.");
    }

    // Also sets $conf['local_dir_site'], the only thing that makes
    // orderByIsLocal() take its second @include (of siteLocal's own
    // config.inc.php) -- PIWIGO_LOCAL_DIR is unset in this test
    // environment, so local === siteLocal and this second include re-reads
    // the exact same file, but the source line itself only runs when this
    // key is set, so this is needed to exercise that line at all.
    file_put_contents(
        $localConfigPath,
        "<?php\n\$conf['order_by'] = 'ORDER BY id ASC';\n\$conf['local_dir_site'] = true;\n"
    );

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, ctConfigSection('main'));
        $page->assertSee('in your local configuration file, this parameter in deprecated, please remove it or rename it into');
    } finally {
        @unlink($localConfigPath);
    }
});

it('sizes tab: resubmitting identical derivative values leaves an unchanged type\'s last_mod_time untouched', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $page = H::loginAsAdmin($this);

        $payload = [
            'submit' => '1',
            'resize_quality' => '90',
            'd' => ctDerivativesPayload(),
        ];

        $token = H::pwgToken($page);
        $first = H::adminPost($page, ctConfigSection('sizes'), array_merge(['pwg_token' => $token], $payload));
        expect($first['status'])->toBe(200);
        expect($first['body'])->toContain('Your configuration settings are saved');

        $lastModAfterFirst = ctDecodedDerivatives()['d']['square']->last_mod_time;

        // A real elapsed-time gap (not just "same request"): if
        // processSizes()'s own same-value detection (comparing ideal_size/
        // max_crop/min_size/sharpen/quality against the already-saved
        // DerivativeParams) were broken and unconditionally stamped
        // last_mod_time = time() on every save, this second, byte-identical
        // resubmission would show a strictly newer timestamp.
        sleep(2);

        $token = H::pwgToken($page);
        $second = H::adminPost($page, ctConfigSection('sizes'), array_merge(['pwg_token' => $token], $payload));
        expect($second['status'])->toBe(200);
        expect($second['body'])->toContain('Your configuration settings are saved');

        $lastModAfterSecond = ctDecodedDerivatives()['d']['square']->last_mod_time;

        expect($lastModAfterSecond)->toBe($lastModAfterFirst);
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: deletes a custom derivative entry when its delete flag is submitted', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $decoded = ctDecodedDerivatives();
        $customKey = 'ct_custom_' . uniqid();
        $decoded['c'][$customKey] = time() - 100;
        ctSetDecodedDerivatives($decoded);

        expect(ctDecodedDerivatives()['c'])->toHaveKey($customKey);

        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => ctDerivativesPayload(),
            // Mirrors ImageStdParams::get_custom()'s own key shape closely
            // enough for this branch's purposes -- processSizes() only
            // checks `isset($post['delete_custom_derivative_' . $custom])`
            // for each key already present in ImageStdParams::$custom, it
            // never validates the key's own format.
            'delete_custom_derivative_' . $customKey => '1',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Your configuration settings are saved');
        expect(ctDecodedDerivatives()['c'])->not->toHaveKey($customKey);
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('watermark tab: reports a write-access error for a genuinely unwritable upload directory', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    $repoRoot = dirname(__DIR__, 2);
    $watermarksDir = $repoRoot . '/local/watermarks';
    $holdingDir = sys_get_temp_dir() . '/pwg_watermarks_holding_' . uniqid();
    $preserved = [];
    $cookieJar = null;
    $pngPath = null;

    // Every step below (including the fixture setup itself, not just the
    // HTTP assertions) is inside this one try/finally -- local/watermarks/
    // is real, shared state this whole file's OTHER watermark-upload tests
    // depend on, so even a failure while seeding the unwritable fixture
    // must still restore it, not just a failure in the assertions.
    try {
        mkdir($holdingDir, 0777, true);

        // Preserve whatever the live app (running as www-data -- confirmed
        // via this environment's reciprocal torres/www-data group
        // membership, the same shared-filesystem assumption every direct-
        // DB/-filesystem fixture helper in BrowserTestHelpers already
        // makes) currently has in local/watermarks/, rather than deleting
        // it outright, so this test is fully restorable regardless of what
        // an earlier run left behind.
        // None of rename()/rmdir()/mkdir() below are @-suppressed or
        // success-checked by accident: a silent failure here would leave
        // $watermarksDir in some unknown intermediate state (e.g. non-empty,
        // still www-data-owned) while the rest of this test keeps assuming
        // its own "fresh torres-owned 0555 directory" setup succeeded --
        // the same class of gap the finally block below guards against.
        $entries = scandir($watermarksDir);
        foreach ($entries !== false ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (! rename($watermarksDir . '/' . $entry, $holdingDir . '/' . $entry)) {
                throw new RuntimeException("Failed to move existing entry \"{$entry}\" out of {$watermarksDir} while seeding the unwritable-directory fixture.");
            }
            $preserved[] = $entry;
        }
        if (! rmdir($watermarksDir)) {
            throw new RuntimeException("Failed to remove {$watermarksDir} while seeding the unwritable-directory fixture.");
        }

        // A real, deterministic unwritable directory: torres owns it (mode
        // 0555, no write bit for owner/group/other), which the live Apache
        // process (running as www-data, a member of torres' own group in
        // this environment) can still read/traverse but never write into --
        // so FilesystemHelper::mkgetdir()'s own is_writable() check
        // genuinely fails, a real filesystem permission state, not a mock.
        // Deleting the directory (torres owns its parent, local/) and
        // recreating it as torres is the only way to control its
        // permissions at all: the live app normally creates/owns
        // local/watermarks/ as www-data, which torres cannot chmod()
        // (confirmed live: chmod() requires file ownership, group
        // membership alone isn't enough).
        if (! mkdir($watermarksDir, 0555)) {
            throw new RuntimeException("Failed to recreate {$watermarksDir} while seeding the unwritable-directory fixture.");
        }
        chmod($watermarksDir, 0555);

        $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_watermark_unwritable_');
        if ($cookieJar === false) {
            throw new RuntimeException('tempnam failed');
        }

        $curl = static function (string $url, array $fields = []) use ($cookieJar): array {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            if ($fields !== []) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
            }
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);

            return ['status' => $status, 'body' => is_string($body) ? $body : ''];
        };

        $baseUrl = H::baseUrl();
        $curl($baseUrl . '/identification.php');
        $curl($baseUrl . '/identification.php', [
            'username' => H::ADMIN_USER,
            'password' => H::ADMIN_PASS,
            'login' => 'Login',
        ]);

        $statusResult = $curl($baseUrl . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
        $decodedStatus = json_decode($statusResult['body'], true);
        $statusResultData = is_array($decodedStatus) ? ($decodedStatus['result'] ?? null) : null;
        $pwgTokenRaw = is_array($statusResultData) ? ($statusResultData['pwg_token'] ?? null) : null;
        $pwgToken = is_string($pwgTokenRaw) || is_int($pwgTokenRaw) ? (string) $pwgTokenRaw : '';
        expect($pwgToken)->not->toBe('');

        $watermarkUrl = $baseUrl . '/' . ltrim(ctConfigSection('watermark'), '/');
        $pngPath = tempnam(sys_get_temp_dir(), 'pwg_watermark_') . '.png';
        $img = imagecreatetruecolor(20, 20);
        if ($img === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        imagepng($img, $pngPath);

        $result = $curl($watermarkUrl, [
            'pwg_token' => $pwgToken,
            'submit' => '1',
            'w[file]' => '',
            'w[position]' => 'topleft',
            'w[xpos]' => '0',
            'w[ypos]' => '0',
            'w[xrepeat]' => '0',
            'w[yrepeat]' => '0',
            'w[opacity]' => '50',
            'w[minw]' => '10',
            'w[minh]' => '10',
            'watermarkImage' => new CURLFile($pngPath, 'image/png', 'ct-unwritable-watermark.png'),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Add write access to the');
        expect($result['body'])->not->toContain('Your configuration settings are saved');
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        if (is_string($cookieJar)) {
            @unlink($cookieJar);
        }
        if ($pngPath !== null) {
            @unlink($pngPath);
        }

        // Best-effort, idempotent restoration -- @-suppressed since a step
        // here may legitimately no-op (e.g. chmod() on a directory this
        // process doesn't own, if the try block above never got far enough
        // to replace it with a torres-owned one in the first place).
        if (! is_dir($watermarksDir)) {
            @mkdir($watermarksDir, 0777);
        }
        @chmod($watermarksDir, 0777);
        // If the upload attempt above ever actually succeeds (a race
        // against the 0555 setup, or an earlier run having left
        // watermarksDir mid-swap), the resulting file is not in
        // $preserved and would otherwise stay behind permanently,
        // accumulating debris. Clear anything currently there before
        // restoring the genuinely pre-existing entries, so a single bad
        // run can never leave this shared fixture directory in a dirty
        // state for every later one.
        $currentEntries = scandir($watermarksDir);
        foreach ($currentEntries !== false ? $currentEntries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($watermarksDir . '/' . $entry);
        }
        foreach ($preserved as $entry) {
            $from = $holdingDir . '/' . $entry;
            if (file_exists($from)) {
                @rename($from, $watermarksDir . '/' . $entry);
            }
        }
        if (is_dir($holdingDir)) {
            @rmdir($holdingDir);
        }

        H::restoreDerivativeConfig($snapshot);
    }
});

it('watermark tab: lowering the size threshold bumps last_mod_time for a still-eligible derivative type', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $decoded = ctDecodedDerivatives();
        expect($decoded['d'])->toHaveKey('small');

        // 'small' (576x432) qualifies for the watermark at BOTH thresholds
        // below (500<=576 and 300<=576) -- its own use_watermark boolean
        // never flips, isolating the specific "the threshold itself moved"
        // branch (processWatermark()'s 3rd $changed check) from the other
        // two (use_watermark flipping, or file/xpos/ypos/xrepeat/yrepeat/
        // opacity changing).
        $decoded['w']->file = 'watermarks/ct_lastmod_fixture.png';
        $decoded['w']->min_size = [500, 500];
        $decoded['w']->xpos = 0;
        $decoded['w']->ypos = 0;
        $decoded['w']->xrepeat = 0;
        $decoded['w']->yrepeat = 0;
        $decoded['w']->opacity = 50;
        $seededLastMod = time() - 100_000;
        $decoded['d']['small']->last_mod_time = $seededLastMod;
        ctSetDecodedDerivatives($decoded);

        $page = H::loginAsAdmin($this);

        $watermarkFields = [
            'file' => 'watermarks/ct_lastmod_fixture.png',
            'position' => 'topleft',
            'xpos' => '0',
            'ypos' => '0',
            'xrepeat' => '0',
            'yrepeat' => '0',
            'opacity' => '50',
        ];

        // First: resubmit the SAME threshold seeded above -- confirms the
        // seeded last_mod_time is a stable starting point, not itself a
        // fresh "changed" write (a real, elapsed-time-independent no-op).
        $token = H::pwgToken($page);
        $unchanged = H::adminPost($page, ctConfigSection('watermark'), [
            'pwg_token' => $token,
            'submit' => '1',
            'w' => $watermarkFields + ['minw' => '500', 'minh' => '500'],
        ]);
        expect($unchanged['status'])->toBe(200);
        expect($unchanged['body'])->toContain('Your configuration settings are saved');
        expect(ctDecodedDerivatives()['d']['small']->last_mod_time)->toBe($seededLastMod);

        // Second: lower the threshold only, everything else identical.
        $token = H::pwgToken($page);
        $changed = H::adminPost($page, ctConfigSection('watermark'), [
            'pwg_token' => $token,
            'submit' => '1',
            'w' => $watermarkFields + ['minw' => '300', 'minh' => '300'],
        ]);
        expect($changed['status'])->toBe(200);
        expect($changed['body'])->toContain('Your configuration settings are saved');
        expect(ctDecodedDerivatives()['d']['small']->last_mod_time)->toBeGreaterThan($seededLastMod);
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('default tab: reaches the no-op massaging branch when submitted via the generic "submit" flag', function (): void {
    // handle()'s own per-tab POST-massaging switch only runs when
    // ConfigurationRequest::isSubmitted (isset($_POST['submit'])) is true
    // -- the "saves the default tab" test above submits via 'validate'
    // (ProfileFormHandler::saveFromPost()'s own field), never 'submit', so
    // it never enters this switch at all. The real profile_content.tpl
    // form for this tab never posts 'submit' either (there's no generic
    // config value for the "default"/guest-profile tab to massage), but
    // this is a public HTTP endpoint keyed only on $_GET['section'] and
    // $_POST['submit'] -- a client can genuinely combine
    // section=default with submit=1, landing on the switch's own
    // otherwise-empty `case 'default':` branch (the generic config-row
    // update loop right after it then runs too, same as every other
    // section, just with no massaged fields to pick up).
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('default'));
    $token = H::pwgToken($page);

    $result = H::adminPost($page, ctConfigSection('default'), [
        'submit' => '1',
        'pwg_token' => $token,
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('main tab: strips HTML tags from the gallery title when HTML descriptions are disabled', function (): void {
    // CurrentConfig::$allowHtmlDescriptions defaults to true, so the
    // strip_tags() branch inside the generic config-save loop (only
    // reached when it's explicitly turned off) is never exercised by the
    // plain "saves the main tab" test above, which never touches
    // allow_html_descriptions and posts a plain-text title anyway.
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, ctConfigSection('main'));
    $token = H::pwgToken($page);

    $snapshot = H::snapshotConfig(array_merge(
        ['gallery_title', 'order_by', 'order_by_inside_category', 'email_admin_on_new_user', 'allow_html_descriptions'],
        ctMainCheckboxes()
    ));

    try {
        H::setConfigValue('allow_html_descriptions', H::jsonEncode(false));

        $rawTitle = '<b>CT HTML Title ' . uniqid() . '</b>';

        $result = H::adminPost($page, ctConfigSection('main'), [
            'submit' => '1',
            'pwg_token' => $token,
            'gallery_title' => $rawTitle,
            'order_by' => ['id ASC'],
            'email_admin_on_new_user_filter' => 'all',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect(H::configValue('gallery_title'))->toBe(json_encode(strip_tags($rawTitle)));
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('sizes/watermark tabs: a plain "admin"-status user\'s submission is silently ignored (webmaster-only gate)', function (): void {
    // processSizes()/processWatermark() each gate their OWN write behind
    // is_webmaster() -- the only thing gating either tab's save, since
    // handle()'s own generic config-row UPDATE loop explicitly excludes
    // both 'sizes'/'watermark' from its own is_webmaster() check. Every
    // existing "sizes tab"/"watermark tab" save test runs as the real
    // fixture webmaster, so this early-return branch (reached by a
    // logged-in, but merely "admin"-status, non-webmaster user) is never
    // exercised elsewhere in this file.
    $page = H::loginAsAdmin($this);
    $username = 'config_ct_nonweb_' . uniqid();
    $password = 'a-strong-test-password-1';

    $addResult = H::wsCall($page, 'pwg.users.add', [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = wsAddedUserId($addResult);

    $db = H::connect();
    H::dbQuery($db, sprintf("UPDATE user_infos SET status = 'admin' WHERE user_id = %d", $userId));

    $derivativeSnapshot = H::snapshotDerivativeConfig();

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        H::assertNoServerErrors($adminPage, 'plain-admin identification page');
        $adminPage = $adminPage->fill('username', $username)->fill('password', $password)->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $sizesToken = H::pwgToken($adminPage);
        $sizesResult = H::adminPost($adminPage, ctConfigSection('sizes'), [
            'pwg_token' => $sizesToken,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => ctDerivativesPayload(),
        ]);
        expect($sizesResult['status'])->toBe(200);
        expect($sizesResult['body'])->not->toContain('Your configuration settings are saved');
        expect($sizesResult['body'])->not->toContain('Fatal error');

        $watermarkToken = H::pwgToken($adminPage);
        $watermarkResult = H::adminPost($adminPage, ctConfigSection('watermark'), [
            'pwg_token' => $watermarkToken,
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
        expect($watermarkResult['status'])->toBe(200);
        expect($watermarkResult['body'])->not->toContain('Your configuration settings are saved');
        expect($watermarkResult['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreDerivativeConfig($derivativeSnapshot);
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});

it('sizes tab: ignores a malformed non-array "d" entry instead of crashing', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        // A tampered/malformed field: d[bogus_type] posted as a plain
        // scalar rather than a nested d[bogus_type][w]/[h]/... array --
        // processSizes()'s own normalization loop (see its own docblock)
        // must drop it rather than treat the string as an array.
        // 'bogus_type' also isn't a real ImageStdParams type, so even if
        // it survived normalization it would never be read back out by
        // the step-2/step-3 loops (both iterate get_all_types()).
        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => array_merge(ctDerivativesPayload(), ['bogus_type' => 'not-an-array']),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Your configuration settings are saved');
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: rejects a thumb size with a non-positive width and height', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        // Exercises processSizes()'s THUMB-specific `$w <= 0`/`$h <= 0`
        // checks directly -- distinct from "rejects a thumb size that is
        // not strictly larger than the square size" above, which submits
        // a positive-but-too-small thumb size and only ever trips the
        // later `max($w, $h) <= $prev_w` check, never these two.
        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => ctDerivativesPayload(['thumb' => ['w' => 0, 'h' => 0]]),
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Your configuration settings are saved');
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: rejects an out-of-range sharpen value for a derivative type', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $payload = ctDerivativesPayload();
        $payload['medium']['sharpen'] = '150';

        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => $payload,
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Your configuration settings are saved');
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: changing an already-enabled type\'s own dimensions bumps its last_mod_time', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $page = H::loginAsAdmin($this);

        // ImageStdParams::load_from_db() self-heals disabled_type_map back
        // to ImageStdParams::get_disabled_default_sizes() (3xlarge/4xlarge)
        // whenever it reads back empty -- a faithful port of piwigo16's own
        // include/derivative_std_params.inc.php load_from_db(), not a
        // rewrite bug. ctDerivativesPayload()'s default submits every type
        // (including 3xlarge) as enabled, which leaves disabled_type_map
        // genuinely empty after a save -- confirmed live: the very next
        // request's own load_from_db() then silently resets 4xlarge back
        // to disabled, so it can never reach the "already enabled" branch
        // this test means to exercise. Keeping 3xlarge disabled (omitting
        // its own 'enabled' key) avoids that self-heal without touching
        // production behavior.
        $baselinePayload = ctDerivativesPayload();
        unset($baselinePayload['3xlarge']['enabled']);

        $token = H::pwgToken($page);
        $baseline = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => $baselinePayload,
        ]);
        expect($baseline['status'])->toBe(200);
        expect($baseline['body'])->toContain('Your configuration settings are saved');

        $lastModBefore = ctDecodedDerivatives()['d']['4xlarge']->last_mod_time;

        // A real elapsed-time gap -- same rationale as the "resubmitting
        // identical derivative values" test above.
        sleep(2);

        // '4xlarge' stays enabled both times -- only its own ideal_size
        // grows (still > '3xlarge', so ordering validation still passes)
        // -- exercising the "already enabled, size/crop genuinely
        // changed" branch, distinct from the enable/disable toggle test
        // above (which never changes an already-enabled type's own size).
        $changedPayload = ctDerivativesPayload(['4xlarge' => ['w' => 3200, 'h' => 2400]]);
        unset($changedPayload['3xlarge']['enabled']);

        $token = H::pwgToken($page);
        $changed = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => $changedPayload,
        ]);
        expect($changed['status'])->toBe(200);
        expect($changed['body'])->toContain('Your configuration settings are saved');

        $lastModAfter = ctDecodedDerivatives()['d']['4xlarge']->last_mod_time;
        expect($lastModAfter)->toBeGreaterThan($lastModBefore);
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: reconciles a min_size that drifted out of sync with an unchanged ideal_size', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $decoded = ctDecodedDerivatives();
        expect($decoded['d'])->toHaveKey('small');

        // A real, if unusual, state a hand-edited or legacy-migrated
        // derivative_size row can be in: max_crop is non-zero (a crop
        // threshold is active) but min_size was never kept in sync with
        // the type's own ideal_size -- processSizes()'s own step-1
        // sanitize always keeps the two 1:1 whenever this form's own
        // "d[type][crop]" field is submitted (see its own docblock), so
        // this specific inconsistency can't be produced through the form
        // itself and is seeded directly at the DB layer instead, the same
        // "direct DB fixture, not a mock of the class under test"
        // technique the watermark last_mod_time test above already uses.
        [$idealW, $idealH] = $decoded['d']['small']->sizing->ideal_size;
        $decoded['d']['small']->sizing = new SizingParams(
            [$idealW, $idealH],
            1.0,
            [$idealW - 76, $idealH - 57]
        );
        $seededLastMod = time() - 100_000;
        $decoded['d']['small']->last_mod_time = $seededLastMod;
        ctSetDecodedDerivatives($decoded);

        $page = H::loginAsAdmin($this);
        $token = H::pwgToken($page);

        $payload = ctDerivativesPayload();
        // Same ideal_size as seeded above, but now WITH the "crop" field
        // submitted -- max_crop stays non-zero on both sides (so the
        // ideal_size/max_crop comparison alone doesn't already flag a
        // change), isolating the min_size-only mismatch this branch
        // detects.
        $payload['small']['w'] = (string) $idealW;
        $payload['small']['h'] = (string) $idealH;
        $payload['small']['crop'] = '1';

        $result = H::adminPost($page, ctConfigSection('sizes'), [
            'pwg_token' => $token,
            'submit' => '1',
            'resize_quality' => '90',
            'd' => $payload,
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Your configuration settings are saved');

        $reconciled = ctDecodedDerivatives()['d']['small'];
        expect($reconciled->last_mod_time)->toBeGreaterThan($seededLastMod);
        expect($reconciled->sizing->min_size)->toBe([$idealW, $idealH]);
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});

it('sizes tab: renders age labels for existing custom derivatives without error', function (): void {
    $snapshot = H::snapshotDerivativeConfig();

    try {
        $decoded = ctDecodedDerivatives();
        $freshKey = 'ct_custom_fresh_' . uniqid();
        $oldKey = 'ct_custom_old_' . uniqid();
        // One inside the "<= 24h" window (renders via Lang::t('today'))
        // and one well outside it (renders via DateHelper::timeSince()) --
        // both branches of handle()'s own 'custom_derivatives' ternary.
        // configuration_sizes.tpl assigns 'custom_derivatives' but never
        // actually reads it in the current admin theme, so this test can
        // only assert the render completes cleanly with real
        // custom-derivative fixture rows present (both ages), not the
        // specific rendered text.
        $decoded['c'][$freshKey] = time();
        $decoded['c'][$oldKey] = time() - (3 * 24 * 3600);
        ctSetDecodedDerivatives($decoded);

        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, ctConfigSection('sizes'));
        $page->assertNoJavaScriptErrors();

        expect(ctDecodedDerivatives()['c'])->toHaveKey($freshKey);
        expect(ctDecodedDerivatives()['c'])->toHaveKey($oldKey);
    } finally {
        H::restoreDerivativeConfig($snapshot);
    }
});
