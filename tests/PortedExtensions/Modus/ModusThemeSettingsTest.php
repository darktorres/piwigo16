<?php

declare(strict_types=1);

use Piwigo\Image\ImageStdParams;
use Piwigo\Theme\Modus\ModusThemeSettings;
use Piwigo\Theme\Modus\SkinCatalog;

// Pure value-object logic, no DB/Kernel needed -- classes resolve via
// PortedExtensionAutoloader::register() (tests/bootstrap.php), reading
// modus_17.0.0's own real theme.json autoload.psr-4 map.

test('all() returns exactly the 18 real modus_16.3.0.1 skins', function (): void {
    $ids = array_map(static fn ($skin) => $skin->id, SkinCatalog::all());

    expect($ids)
        ->toHaveCount(18)
        ->and($ids)
        ->toEqualCanonicalizing([
            'avocado', 'blueberry', 'cafe_latte', 'clear', 'dark', 'dark_lagoon',
            'dark_radish', 'dark_sky', 'debug', 'glacier', 'grey', 'neon_orange',
            'neon_pink', 'newspaper', 'quartz', 'splash', 'strawberry_jam', 'swimming_pool',
        ]);
});

test('every colorscheme is light or dark, never legacy\'s own "clear"', function (): void {
    foreach (SkinCatalog::all() as $skin) {
        expect($skin->colorscheme)->toBeIn(['light', 'dark']);
    }
});

test('debug is light despite silently inheriting the theme\'s dark default in legacy', function (): void {
    expect(SkinCatalog::get('debug')?->colorscheme)->toBe('light');
});

test('grey/dark/dark_lagoon/dark_radish/dark_sky are dark, matching their real BODY colors', function (): void {
    foreach (['grey', 'dark', 'dark_lagoon', 'dark_radish', 'dark_sky'] as $id) {
        expect(SkinCatalog::get($id)?->colorscheme)->toBe('dark');
    }
});

test('get() returns null for an unknown skin id', function (): void {
    expect(SkinCatalog::get('does-not-exist'))->toBeNull();
});

test('defaultSkin() is newspaper, matching functions.inc.php\'s modus_get_default_config()', function (): void {
    expect(SkinCatalog::defaultSkin()->id)->toBe('newspaper')
        ->and(SkinCatalog::DEFAULT_SKIN_ID)->toBe('newspaper');
});

test('label() prettifies the id the same way legacy admin.inc.php does', function (): void {
    expect(SkinCatalog::get('cafe_latte')?->label())->toBe('Cafe Latte')
        ->and(SkinCatalog::get('dark_lagoon')?->label())->toBe('Dark Lagoon')
        ->and(SkinCatalog::get('avocado')?->label())->toBe('Avocado');
});

test('ModusThemeSettings::defaults() matches modus_get_default_config() exactly', function (): void {
    $defaults = ModusThemeSettings::defaults();

    expect($defaults->skin)
        ->toBe('newspaper')
        ->and($defaults->albumThumbSize)
        ->toBe(250)
        ->and($defaults->indexPhotoDeriv)
        ->toBe(ImageStdParams::XXSMALL)
        ->and($defaults->indexPhotoDerivHdpi)
        ->toBe(ImageStdParams::XSMALL)
        ->and($defaults->displayPageBanner)
        ->toBeFalse();
});

test('fromArray() falls back to defaults for every missing/invalid field', function (): void {
    $settings = ModusThemeSettings::fromArray([]);

    expect($settings->toArray())
        ->toBe(ModusThemeSettings::defaults()->toArray());
});

test('fromArray() rejects an unknown skin id, falling back to the default', function (): void {
    expect(ModusThemeSettings::fromArray([
        'skin' => 'not-a-real-skin',
    ])->skin)->toBe('newspaper');
});

test('fromArray() rejects an unknown derivative type', function (): void {
    expect(ModusThemeSettings::fromArray([
        'index_photo_deriv' => 'not-a-real-type',
    ])->indexPhotoDeriv)
        ->toBe(ImageStdParams::XXSMALL);
});

test('fromArray() distinguishes "key absent" (default 250) from "key present, value 0" (real off state)', function (): void {
    // Real bug, caught before this port ever shipped: album_thumb_size
    // is legacy's own on/off switch for the "masonry"-style album
    // listing (themeconf.inc.php's modus_index_category_thumbnails()),
    // not just a pixel size -- both cases used to collapse to 250.
    expect(ModusThemeSettings::fromArray([])->albumThumbSize)->toBe(250)
        ->and(ModusThemeSettings::fromArray([
            'album_thumb_size' => 0,
        ])->albumThumbSize)->toBeNull()
        ->and(ModusThemeSettings::fromArray([
            'album_thumb_size' => 'garbage',
        ])->albumThumbSize)->toBe(250);
});

test('fromArray() clamps a stored non-zero album_thumb_size to the 200-400 slider range', function (): void {
    expect(ModusThemeSettings::fromArray([
        'album_thumb_size' => 50,
    ])->albumThumbSize)->toBe(200)
        ->and(ModusThemeSettings::fromArray([
            'album_thumb_size' => 999,
        ])->albumThumbSize)->toBe(400)
        ->and(ModusThemeSettings::fromArray([
            'album_thumb_size' => 300,
        ])->albumThumbSize)->toBe(300);
});

test('fromArray() round-trips real, valid data exactly', function (): void {
    $data = [
        'skin' => 'dark_sky',
        'album_thumb_size' => 320,
        'index_photo_deriv' => ImageStdParams::SMALL,
        'index_photo_deriv_hdpi' => ImageStdParams::MEDIUM,
        'display_page_banner' => true,
    ];

    expect(ModusThemeSettings::fromArray($data)->toArray())->toBe($data);
});

test('fromPost() unchecked use_album_square_thumbs forces masonry off, regardless of a leftover slider value', function (): void {
    // Legacy: `if (!isset($_POST['use_album_square_thumbs'])) { $my_conf['album_thumb_size'] = 0; }`
    $settings = ModusThemeSettings::fromPost([
        'album_thumb_size' => '300',
    ]);

    expect($settings->albumThumbSize)
        ->toBeNull();
});

test('fromPost() checked + a valid value keeps that value', function (): void {
    $settings = ModusThemeSettings::fromPost([
        'use_album_square_thumbs' => 'on',
        'album_thumb_size' => '300',
    ]);

    expect($settings->albumThumbSize)
        ->toBe(300);
});

test('fromPost() checked but garbage/zero value falls back to the default size, never null', function (): void {
    expect(ModusThemeSettings::fromPost([
        'use_album_square_thumbs' => 'on',
        'album_thumb_size' => 'garbage',
    ])->albumThumbSize)->toBe(250)
        ->and(ModusThemeSettings::fromPost([
            'use_album_square_thumbs' => 'on',
            'album_thumb_size' => '0',
        ])->albumThumbSize)->toBe(250);
});

test('fromPost() checkbox fields absent from $post mean false, not "keep the default"', function (): void {
    // Legacy: `$my_conf[$k] = isset($_POST[$k]) ? true : false;` for
    // every boolean field, unconditionally -- no default fallback.
    $settings = ModusThemeSettings::fromPost([]);

    expect($settings->displayPageBanner)
        ->toBeFalse();
});

test('fromPost() checkbox field present means true', function (): void {
    expect(ModusThemeSettings::fromPost([
        'display_page_banner' => 'on',
    ])->displayPageBanner)->toBeTrue();
});

test('toArray() converts null albumThumbSize back to legacy\'s own 0 sentinel', function (): void {
    $settings = ModusThemeSettings::fromArray([
        'album_thumb_size' => 0,
    ]);

    expect($settings->toArray()['album_thumb_size'])->toBe(0);
});
