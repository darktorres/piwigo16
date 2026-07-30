<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Image\SrcImage;
use ReflectionProperty;
use RuntimeException;

/**
 * Piwigo\Image\DerivativeImage had zero dedicated test file. Covers:
 * urlService()'s not-set RuntimeException; get_all()/get_one()'s
 * array-to-SrcImage coercion plus get_one()'s undefined-type fallback and
 * not-found null; build()'s defined-type-index "smaller identity match"
 * search loop (including its recursive re-entry), its './'/'../ '
 * location-prefix stripping, its extension-missing Exception, and its
 * derivative_url_style=0 (auto) mtime-based cache-freshness check;
 * get_size_css()/get_size_hr()/get_scaled_size()/get_scaled_size_htm()'s
 * size-present vs empty-string-fallback branches.
 *
 * Every build() test below deliberately omits 'width'/'height' from the
 * SrcImage $infos (or supplies them as null, key still present) so
 * SrcImage::has_size() is false at construction time -- this skips
 * build()'s own is_identity()/will_watermark() early-return block
 * entirely (already covered elsewhere per the coverage report), isolating
 * the token/extension/cache-style logic this file exists to close. The
 * one test that does need has_size()=true (the smaller-defined-type
 * search) sets width/height explicitly and says so.
 */
function derivativeImageTestSetUrlService(?UrlServiceInterface $service): void
{
    new ReflectionProperty(DerivativeImage::class, 'urlService')->setValue(null, $service);
}

/**
 * Round-trips ImageStdParams' 3 static maps through derivativeImageTestRestoreStdParams()
 * unexamined -- callers never inspect the snapshot's contents directly, so
 * this only needs to prove the outer shape is a real array, not the maps'
 * own precise element types.
 *
 * @return array{0: array<array-key, mixed>, 1: array<array-key, mixed>, 2: array<array-key, mixed>}
 */
function derivativeImageTestSnapshotStdParams(): array
{
    $typeMap = new ReflectionProperty(ImageStdParams::class, 'type_map')->getValue();
    $allTypeMap = new ReflectionProperty(ImageStdParams::class, 'all_type_map')->getValue();
    $undefinedTypeMap = new ReflectionProperty(ImageStdParams::class, 'undefined_type_map')->getValue();
    if (! is_array($typeMap) || ! is_array($allTypeMap) || ! is_array($undefinedTypeMap)) {
        throw new RuntimeException('ImageStdParams static maps were not arrays');
    }

    return [$typeMap, $allTypeMap, $undefinedTypeMap];
}

/**
 * @param array<string, DerivativeParams> $typeMap
 * @param array<string, string> $undefinedMap
 */
function derivativeImageTestSeedStdParams(array $typeMap, array $undefinedMap = []): void
{
    // Matches ImageStdParams::build_maps()'s own real behavior: each
    // DerivativeParams' `type` property is set from its own map key --
    // it defaults to ImageStdParams::CUSTOM otherwise (its declared
    // property default), which get_type() would then return instead.
    foreach ($typeMap as $type => $params) {
        $params->type = $type;
    }

    $allTypeMap = $typeMap;
    foreach ($undefinedMap as $undefinedType => $target) {
        $allTypeMap[$undefinedType] = $typeMap[$target];
    }

    new ReflectionProperty(ImageStdParams::class, 'type_map')->setValue(null, $typeMap);
    new ReflectionProperty(ImageStdParams::class, 'all_type_map')->setValue(null, $allTypeMap);
    new ReflectionProperty(ImageStdParams::class, 'undefined_type_map')->setValue(null, $undefinedMap);
}

/**
 * @param array{0: array<array-key, mixed>, 1: array<array-key, mixed>, 2: array<array-key, mixed>} $snapshot
 */
function derivativeImageTestRestoreStdParams(array $snapshot): void
{
    new ReflectionProperty(ImageStdParams::class, 'type_map')->setValue(null, $snapshot[0]);
    new ReflectionProperty(ImageStdParams::class, 'all_type_map')->setValue(null, $snapshot[1]);
    new ReflectionProperty(ImageStdParams::class, 'undefined_type_map')->setValue(null, $snapshot[2]);
}

beforeEach(function (): void {
    CurrentConfig::reset();
    derivativeImageTestSetUrlService(new DerivativeImageTestFakeUrlService());
});

afterEach(function (): void {
    CurrentConfig::reset();
    CurrentPaths::reset();
    derivativeImageTestSetUrlService(null);
});

test('urlService() throws a RuntimeException when RequestBootstrap has not set one yet', function (): void {
    derivativeImageTestSetUrlService(null);

    $src = new SrcImage([
        'id' => 42,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    expect(fn () => DerivativeImage::url(new DerivativeParams(SizingParams::classic(50, 50)), $src))
        ->toThrow(\RuntimeException::class, 'DerivativeImage: no URL service set (RequestBootstrap not run yet?)');
});

test('get_all() coerces a plain info array into a SrcImage and keys the result by defined type', function (): void {
    $snapshot = derivativeImageTestSnapshotStdParams();

    try {
        derivativeImageTestSeedStdParams([
            'square' => new DerivativeParams(SizingParams::square(120)),
        ]);

        $all = DerivativeImage::get_all([
            'id' => 7,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        expect(array_keys($all))->toBe(['square']);
        expect($all['square'])->toBeInstanceOf(DerivativeImage::class);
        expect($all['square']->src_image)->toBeInstanceOf(SrcImage::class);
        expect($all['square']->src_image->id)->toBe(7);
        expect($all['square']->src_image->rel_path)->toBe('upload/2026/07/photo.jpg');
    } finally {
        derivativeImageTestRestoreStdParams($snapshot);
    }
});

test('get_one() falls back to the mapped enabled type for a disabled type, and returns null for an unknown type', function (): void {
    $snapshot = derivativeImageTestSnapshotStdParams();

    try {
        derivativeImageTestSeedStdParams([
            'square' => new DerivativeParams(SizingParams::square(120)),
        ], [
            'thumb' => 'square',
        ]);

        $infos = [
            'id' => 9,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
        ];

        $fallback = DerivativeImage::get_one('thumb', $infos);
        if (! $fallback instanceof DerivativeImage) {
            throw new RuntimeException('expected a real DerivativeImage instance');
        }
        expect($fallback->get_type())->toBe('square');

        expect(DerivativeImage::get_one('does-not-exist', $infos))->toBeNull();
    } finally {
        derivativeImageTestRestoreStdParams($snapshot);
    }
});

test('build() throws when the source path has no extension', function (): void {
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    // Mutate directly (public property) to a path with no extension --
    // constructing SrcImage this way instead would route through the
    // mimetype-icon branch rather than IS_ORIGINAL, a different scenario.
    $src->rel_path = 'upload/2026/07/photoNoExtension';

    expect(fn () => new DerivativeImage(new DerivativeParams(SizingParams::classic(50, 50)), $src))
        ->toThrow(\Exception::class, "DerivativeImage::build(): path 'upload/2026/07/photoNoExtension' has no extension");
});

test('build() strips a leading "./" from the source location and appends the custom-type url tokens', function (): void {
    // get_path() reads CurrentPaths::get() -- set explicitly since this
    // is the first test in the file to call it.
    CurrentPaths::set(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-path-only'));

    $src = new SrcImage([
        'id' => 1,
        'path' => './gallery/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(120, 90)), $src);

    // SizingParams::classic()'s own int max_crop=0 default never
    // satisfies add_url_tokens()'s strict `=== 0.0` fast-path check (see
    // SizingParamsTest.php's own documented finding), so the token is the
    // general 2-token form ('120x90', fractionToChar(0)='a'), not the
    // 's120x90' shorthand.
    expect($derivative->get_path())->toBe(CurrentPaths::get()->root . '_data/i/gallery/photo-cu_120x90_a.jpg');
    // No real cached file exists on disk at that path -- build()'s own
    // filemtime() check correctly falls back to the dynamic i.php?
    // URL style rather than a direct cached-file URL.
    expect($derivative->get_url())->toBe('i.php?/gallery/photo-cu_120x90_a.jpg');
});

test('build() strips a leading "../" from the source location', function (): void {
    CurrentPaths::set(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-path-only'));

    $src = new SrcImage([
        'id' => 1,
        'path' => '../gallery/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(64, 64)), $src);

    expect($derivative->get_path())->toBe(CurrentPaths::get()->root . '_data/i/gallery/photo-cu_64_a.jpg');
    // Same reasoning as the leading-"./" test above: no cached file on
    // disk means the dynamic i.php? URL style, not a direct cached URL.
    expect($derivative->get_url())->toBe('i.php?/gallery/photo-cu_64_a.jpg');
});

test('build() substitutes a smaller already-defined identity-matching type when watermarking would otherwise apply, recursing until none remains', function (): void {
    $snapshot = derivativeImageTestSnapshotStdParams();
    $originalWatermark = ImageStdParams::get_watermark();

    try {
        $thumbParams = new DerivativeParams(SizingParams::classic(50, 50));
        $thumbParams->type = 'thumb';
        $mediumParams = new DerivativeParams(SizingParams::classic(200, 200));
        $mediumParams->type = 'medium';

        derivativeImageTestSeedStdParams([
            'thumb' => $thumbParams,
            'medium' => $mediumParams,
        ]);

        $watermark = new \Piwigo\Image\WatermarkParams();
        $watermark->file = 'watermark.png';
        $watermark->min_size = [30, 30];
        ImageStdParams::set_watermark($watermark);
        // apply_global() compares the watermark's min_size against each
        // type's own ideal_size (not the source size) -- both 'thumb'
        // (50x50) and 'medium' (200x200) satisfy 30<=ideal here, so both
        // end up with use_watermark=true, matching real load_from_db()
        // wiring rather than hand-setting the flag.
        $thumbParams->use_watermark = $watermark->min_size[0] <= $thumbParams->max_width();
        $mediumParams->use_watermark = $watermark->min_size[0] <= $mediumParams->max_width();

        $src = new SrcImage([
            'id' => 3,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
            'width' => 40,
            'height' => 40,
        ]);

        $derivative = new DerivativeImage($mediumParams, $src);

        // 40x40 is an identity match for both 'medium' (200x200) and the
        // smaller 'thumb' (50x50); will_watermark(40x40) is true for
        // 'medium' (30<=40), so build() substitutes 'thumb' instead of
        // returning the source as-is -- then recurses on 'thumb', where
        // will_watermark(40x40) is true again (still 30<=40) but no type
        // smaller than 'thumb' exists in the seeded map, so the inner
        // search loop's break is hit and 'thumb' is the final type.
        expect($derivative->get_type())->toBe('thumb');
        expect($derivative->same_as_source())->toBeFalse();
    } finally {
        derivativeImageTestRestoreStdParams($snapshot);
        ImageStdParams::set_watermark($originalWatermark);
    }
});

test('build() routes through i.php and marks itself not cached when derivative_url_style is auto and no cached file exists yet', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-derivative-image-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDerivativeUrlStyle(0);

    try {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'gallery/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(80, 60)), $src);

        expect($derivative->is_cached())->toBeFalse();
        expect($derivative->get_url())->toBe('i.php?/gallery/photo-cu_80x60_a.jpg');
    } finally {
        CurrentConfig::setDerivativeUrlStyle(2);
        derivativeCacheServiceRrmdirDerivativeImageTest($root);
    }
});

test('build() links directly to a static file when derivative_url_style is auto and a fresh cached file already exists', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-derivative-image-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDerivativeUrlStyle(0);

    try {
        mkdir($root . '/_data/i/gallery', 0o777, true);
        file_put_contents($root . '/_data/i/gallery/photo-cu_80x60_a.jpg', 'cached-bytes');

        $src = new SrcImage([
            'id' => 1,
            'path' => 'gallery/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(80, 60)), $src);

        expect($derivative->is_cached())->toBeTrue();
        expect($derivative->get_url())->toBe('_data/i/gallery/photo-cu_80x60_a.jpg');
    } finally {
        CurrentConfig::setDerivativeUrlStyle(2);
        derivativeCacheServiceRrmdirDerivativeImageTest($root);
    }
});

function derivativeCacheServiceRrmdirDerivativeImageTest(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? derivativeCacheServiceRrmdirDerivativeImageTest($path) : unlink($path);
    }
    rmdir($dir);
}

test('get_size_css()/get_size_htm()/get_size_hr() render the computed size, or an empty string when the size cannot be computed', function (): void {
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
        'width' => 400,
        'height' => 300,
    ]);
    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(100, 100)), $src);

    // 400x300 scaled to fit within 100x100: width is the binding
    // dimension (ratio 4 vs 3), so height scales proportionally to 75.
    expect($derivative->get_size())->toBe([100, 75]);
    expect($derivative->get_size_css())->toBe('width:100px; height:75px');
    expect($derivative->get_size_htm())->toBe('width="100" height="75"');
    expect($derivative->get_size_hr())->toBe('100 x 75');

    $root = sys_get_temp_dir() . '/piwigo-derivative-image-test-' . bin2hex(random_bytes(8));
    mkdir($root . '/upload/2026/07', 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    file_put_contents($root . '/upload/2026/07/broken.jpg', 'not-a-real-image-payload');

    try {
        // width/height present as explicit nulls (array_key_exists() is
        // true, isset() is false): SrcImage::has_size() is false at
        // construction (skipping build()'s identity check, same as every
        // other test above) but SrcImage::DIM_NOT_GIVEN is NOT set
        // either, so get_size() attempts a live getimagesize() re-read
        // instead of fatalError()-ing -- which fails silently (false, no
        // warning) against this deliberately-not-a-real-image file.
        $unknownSrc = new SrcImage([
            'id' => 2,
            'path' => 'upload/2026/07/broken.jpg',
            'file' => 'broken.jpg',
            'width' => null,
            'height' => null,
        ]);
        $unknownDerivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(100, 100)), $unknownSrc);

        expect($unknownDerivative->get_size())->toBeNull();
        expect($unknownDerivative->get_size_css())->toBe('');
        expect($unknownDerivative->get_size_htm())->toBe('');
        expect($unknownDerivative->get_size_hr())->toBe('');
        expect($unknownDerivative->get_scaled_size(50, 50))->toBeNull();
        expect($unknownDerivative->get_scaled_size_htm(50, 50))->toBe('');
    } finally {
        derivativeCacheServiceRrmdirDerivativeImageTest($root);
    }
});

test('get_scaled_size()/get_scaled_size_htm() scale down proportionally, binding on whichever dimension overflows more', function (): void {
    // Landscape source: width is the overflowing dimension (ratio 2)
    // once scaled down against 100x100 -- exercises the "ratio_w >
    // ratio_h" branch.
    $landscapeSrc = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/landscape.jpg',
        'file' => 'landscape.jpg',
        'width' => 400,
        'height' => 300,
    ]);
    $landscape = new DerivativeImage(new DerivativeParams(SizingParams::classic(100, 100)), $landscapeSrc);
    expect($landscape->get_size())->toBe([100, 75]);
    expect($landscape->get_scaled_size(50, 50))->toBe([50, 37]);
    expect($landscape->get_scaled_size_htm(50, 50))->toBe('width="50" height="37"');

    // Portrait source (dimensions swapped): height is now the
    // overflowing dimension -- exercises the "ratio_h >= ratio_w" branch.
    $portraitSrc = new SrcImage([
        'id' => 2,
        'path' => 'upload/2026/07/portrait.jpg',
        'file' => 'portrait.jpg',
        'width' => 300,
        'height' => 400,
    ]);
    $portrait = new DerivativeImage(new DerivativeParams(SizingParams::classic(100, 100)), $portraitSrc);
    expect($portrait->get_size())->toBe([75, 100]);
    expect($portrait->get_scaled_size(50, 50))->toBe([37, 50]);
    expect($portrait->get_scaled_size_htm(50, 50))->toBe('width="37" height="50"');

    // Within bounds already (no dimension overflows maxw/maxh): returned
    // unchanged, same shape as the un-scaled get_size().
    expect($landscape->get_scaled_size(200, 200))->toBe([100, 75]);
});
