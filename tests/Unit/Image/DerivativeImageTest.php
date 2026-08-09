<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Error;
use Exception;
use Piwigo\Config\CurrentConfig;
use Piwigo\Image\WatermarkParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\Event\GetDerivativeUrl;
use Piwigo\Image\ImageStdParams;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Image\SizingParams;
use Piwigo\Image\SrcImage;
use Piwigo\Tests\Support\KernelContainerOverride;
use ReflectionMethod;
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
 *
 * urlService() has no independently-settable static -- it resolves fresh
 * from the container on every call. A plain Kernel::boot(Paths::fromRoot(...))
 * is enough for it to succeed at all (config/container.php's own default
 * UrlServiceInterface binding), and that default, unconfigured UrlService
 * computes the same '' root url + identity-like embellishUrl() that
 * DerivativeImageTestFakeUrlService's own default does -- so most tests
 * below need no override at all. The 2 tests that assert a non-default,
 * non-empty root url install a real DerivativeImageTestFakeUrlService via
 * KernelContainerOverride::with() instead.
 */

// ---------------------------------------------------------------------
// Mutation-testing gap closure (G1, when-i-run-the-mutable-cookie.md):
// a fresh scoped rerun (2026-08-10) found 20 untested. Every one
// confirmed genuinely inert this pass -- this file's existing coverage
// (above) already exercises every REAL branch; the survivors are all
// structurally unkillable, so no new test was needed:
// - url()'s `$rel_path = ''; $rel_url = '';` (EmptyStringToNotEmpty x2)
//   and build()'s own identical pre-initialization of the same two
//   by-ref out-params: both are ALWAYS overwritten by build() before
//   ever being read -- every one of build()'s own return paths
//   (the identity-no-watermark early return, the recursive
//   smaller-type substitution, and the main token/cache-style path)
//   unconditionally assigns both. The initial value never survives to
//   be observed.
// - Every `(bool) $size`/`(bool) $url_style`/`(bool) $src->rotation`
//   cast inside an `if`/`!` condition (RemoveBooleanCast, 6 sites:
//   build()'s watermark check, its url_style check, and all 5 of
//   get_size_css()/get_size_htm()/get_size_hr()/get_scaled_size()/
//   get_scaled_size_htm()'s own "size computed" guards): the
//   already-coercing if-condition/negation position, same established
//   pattern as every other cast-removal finding across this campaign.
// - build()'s `$url_style = 1;` (DecrementInteger to 0): $url_style is
//   a pure local, never a by-ref/return value -- its only later read is
//   `if ($url_style === 2)`, which neither 0 nor 1 satisfies, so both
//   values reach the identical `else` branch.
// - build()'s defined-type search loop's own `$i = 0` starting index
//   (IncrementInteger to 1, line 275): traced through every real
//   consequence -- this loop exists solely to find $params->type's own
//   position so the INNER loop can search STRICTLY SMALLER types.
//   Skipping index 0 can only affect whether the type AT index 0 gets
//   matched; if it would have matched there, there's nothing smaller to
//   search anyway (immediate break, same as a proper match with an
//   empty inner search), and if it wouldn't have, skipping it changes
//   nothing. Both outcomes converge on "fall through to the main
//   unsubstituted-type path" -- confirmed against the real existing
//   substitution test above, which happens to match at index 1, not 0
//   (the general case this file needed a fresh trace for, not that
//   specific instance).
// - get_scaled_size()'s ratio-vs-1 and ratio-vs-ratio comparisons
//   (GreaterToGreaterOrEqual x3) and their surrounding `(float)`/
//   `floor()` casts (RemoveDoubleCast x4): the real (non-tied) scaling
//   math is already thoroughly covered above (both the
//   width-overflows and height-overflows branches, with genuinely
//   different computed outputs). The only remaining case (`>` vs `>=`
//   at an EXACT ratio of 1, or EXACT ratio_w===ratio_h) is a
//   mathematical-identity self-reassignment -- verified algebraically
//   that both branches compute the IDENTICAL final ($maxw, $maxh) at
//   that exact tie, same "wrongly taken branch reassigns to its own
//   current value" reasoning as PwgImage.php's own dest_ratio===
//   img_ratio finding. `(float)`/floor()'s own casts are additionally
//   inert on their own terms too: `/` between two ints already produces
//   a type-correct result PHP's numeric comparisons don't care about,
//   and floor() always returns float regardless of its argument's type.
// ---------------------------------------------------------------------

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
    $instance = ImageStdParamsTestFactory::get();
    $typeMap = new ReflectionProperty(ImageStdParams::class, 'type_map')->getValue($instance);
    $allTypeMap = new ReflectionProperty(ImageStdParams::class, 'all_type_map')->getValue($instance);
    $undefinedTypeMap = new ReflectionProperty(ImageStdParams::class, 'undefined_type_map')->getValue($instance);
    if (! is_array($typeMap) || ! is_array($allTypeMap) || ! is_array($undefinedTypeMap)) {
        throw new RuntimeException('ImageStdParams instance maps were not arrays');
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

    $instance = ImageStdParamsTestFactory::get();
    new ReflectionProperty(ImageStdParams::class, 'type_map')->setValue($instance, $typeMap);
    new ReflectionProperty(ImageStdParams::class, 'all_type_map')->setValue($instance, $allTypeMap);
    new ReflectionProperty(ImageStdParams::class, 'undefined_type_map')->setValue($instance, $undefinedMap);
}

/**
 * @param array{0: array<array-key, mixed>, 1: array<array-key, mixed>, 2: array<array-key, mixed>} $snapshot
 */
function derivativeImageTestRestoreStdParams(array $snapshot): void
{
    $instance = ImageStdParamsTestFactory::get();
    new ReflectionProperty(ImageStdParams::class, 'type_map')->setValue($instance, $snapshot[0]);
    new ReflectionProperty(ImageStdParams::class, 'all_type_map')->setValue($instance, $snapshot[1]);
    new ReflectionProperty(ImageStdParams::class, 'undefined_type_map')->setValue($instance, $snapshot[2]);
}

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('url() throws a RuntimeException when RequestBootstrap has not set a CurrentConfig yet', function (): void {
    // SrcImage's own constructor needs a booted Kernel (its
    // pictureExtensions() read) -- construct it under a real boot, then
    // reset before calling url(). url() -> build() resolves CurrentConfig
    // via DerivativeImage's own private static currentConfig() helper,
    // which (like SrcImage's own equivalent helper) throws eagerly on a
    // not-booted Kernel rather than falling back to
    // CurrentConfigTestFactory::get()'s own memoized pre-boot instance --
    // this guard fires before build() ever reaches urlService(), so this
    // is the RuntimeException url() actually throws first, not
    // urlService()'s own (see the dedicated get_url() test below for that
    // one, which is reachable without a CurrentConfig read). $src itself
    // holds no live container reference once built, so resetting
    // afterward doesn't invalidate it.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-url-not-booted'));
    $src = new SrcImage([
        'id' => 42,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    Kernel::reset();

    expect(fn () => DerivativeImage::url(new DerivativeParams(SizingParams::classic(50, 50)), $src))
        ->toThrow(RuntimeException::class, 'DerivativeImage: no CurrentConfig set (RequestBootstrap not run yet?)');
});

test('url() throws a RuntimeException when the container returns an unexpected type for CurrentConfig', function (): void {
    // Kills line 97's InstanceOfToTrue -- currentConfig()'s own
    // instanceof guard, paired with the "not booted" half tested by the
    // sibling test above. This half can only ever fire when the
    // container's real binding for CurrentConfig::class resolves to
    // something other than a CurrentConfig instance, something that
    // never legitimately happens through Kernel::boot()'s own public
    // API -- KernelContainerOverride::withWrongTypeFor() rebinds it to a
    // plain stdClass to force the branch. $src is built under a real
    // prior boot (same reasoning as the sibling test above: SrcImage's
    // own constructor needs a booted Kernel), then reused once the
    // container is swapped out from under it.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-currentconfig-wrong-type'));
    $src = new SrcImage([
        'id' => 42,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    KernelContainerOverride::withWrongTypeFor(CurrentConfig::class, function () use ($src): void {
        expect(fn () => DerivativeImage::url(new DerivativeParams(SizingParams::classic(50, 50)), $src))
            ->toThrow(RuntimeException::class, 'DerivativeImage: no CurrentConfig set (RequestBootstrap not run yet?)');
    });
});

test('constructing a DerivativeImage throws a RuntimeException when the container returns an unexpected type for ImageStdParams', function (): void {
    // Kills line 64's InstanceOfToTrue -- imageStdParams()'s own
    // instanceof guard. Passing a string $type routes the constructor
    // through `self::imageStdParams()->get_by_type($type)` before
    // self::build() ever runs, the simplest public entry point that
    // reaches imageStdParams() alone -- unlike build()'s own
    // imageStdParams() read (only reachable via the is_identity() branch,
    // which needs has_size()=true and a whole seeded type map).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-stdparams-wrong-type'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    $currentConfig = CurrentConfigTestFactory::get();

    KernelContainerOverride::withWrongTypeFor(ImageStdParams::class, function () use ($src, $currentConfig): void {
        expect(fn () => new DerivativeImage(ImageStdParams::THUMB, $src, $currentConfig))
            ->toThrow(RuntimeException::class, 'DerivativeImage: no ImageStdParams set (RequestBootstrap not run yet?)');
    });
});

test('get_url() throws a RuntimeException when RequestBootstrap has not set a URL service yet', function (): void {
    // Unlike url() above, the instance get_url() method never reads
    // currentConfig() -- constructing the DerivativeImage instance itself
    // (under a real boot, with a real CurrentConfig instance already
    // injected) is enough; resetting afterward only invalidates the
    // static container-resolve helpers (urlService()/eventDispatcher()),
    // not the already-injected instance property, so this isolates
    // urlService()'s own not-booted guard from currentConfig()'s own
    // guard in the test above.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-geturl-not-booted'));
    $src = new SrcImage([
        'id' => 42,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(50, 50)), $src, CurrentConfigTestFactory::get());
    Kernel::reset();

    expect(fn () => $derivative->get_url())
        ->toThrow(RuntimeException::class, 'DerivativeImage: no URL service set (RequestBootstrap not run yet?)');
});

test('get_url() throws a RuntimeException when the container returns an unexpected type for UrlServiceInterface', function (): void {
    // Kills line 43's InstanceOfToTrue -- urlService()'s own instanceof
    // guard, paired with the "not booted" half tested by the sibling
    // test above. This half can only ever fire when the container's real
    // binding for UrlServiceInterface::class resolves to something other
    // than a UrlServiceInterface instance, something that never
    // legitimately happens through Kernel::boot()'s own public API. The
    // instance is built under a real boot first (params is real and
    // non-null, so get_url() actually reaches urlService()), then the
    // container is swapped out from under it via
    // KernelContainerOverride::withWrongTypeFor().
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-urlservice-wrong-type'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'gallery/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(80, 60)), $src, CurrentConfigTestFactory::get());

    KernelContainerOverride::withWrongTypeFor(UrlServiceInterface::class, function () use ($derivative): void {
        expect(fn () => $derivative->get_url())
            ->toThrow(RuntimeException::class, 'DerivativeImage: no URL service set (RequestBootstrap not run yet?)');
    });
});

test('eventDispatcher() throws a RuntimeException when the container returns an unexpected type for EventDispatcher', function (): void {
    // Kills line 77's InstanceOfToTrue -- eventDispatcher()'s own
    // instanceof guard. Unlike the urlService()/ImageStdParams guards
    // above, this can't be reached through the public get_url() API: it
    // calls self::urlService() first, which -- now that
    // KernelContainerOverride carries the real Paths through -- actually
    // resolves UrlService, whose own HtmlRenderingInterface dependency
    // (HtmlService) *also* constructor-injects EventDispatcher. That
    // makes HtmlService's own constructor the first thing to observe the
    // stdClass override, as a hard TypeError, before get_url() ever
    // reaches eventDispatcher()'s own instanceof guard. Calling the
    // private static method directly is the only way to isolate this
    // guard from that unrelated, earlier collaborator-construction
    // failure (no setAccessible() call -- a deprecated no-op since PHP
    // 8.1).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-eventdispatcher-wrong-type'));

    $eventDispatcherMethod = new ReflectionMethod(DerivativeImage::class, 'eventDispatcher');

    KernelContainerOverride::withWrongTypeFor(EventDispatcher::class, function () use ($eventDispatcherMethod): void {
        expect(static fn (): mixed => $eventDispatcherMethod->invoke(null))
            ->toThrow(RuntimeException::class, 'DerivativeImage: no EventDispatcher set (RequestBootstrap not run yet?)');
    });
});

test('get_path() throws a RuntimeException when the container returns an unexpected type for Paths', function (): void {
    // Kills line 113's InstanceOfToTrue -- paths()'s own instanceof
    // guard. get_path() is the simplest public entry point that reaches
    // paths() alone: derivativeUrlStyle() defaults to 2 (not the "auto"
    // 0 that would additionally route build() itself through paths()
    // during construction), so the instance builds cleanly under the
    // real container before Paths::class is swapped out for get_path()'s
    // own call.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-paths-wrong-type'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'gallery/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(80, 60)), $src, CurrentConfigTestFactory::get());

    KernelContainerOverride::withWrongTypeFor(Paths::class, function () use ($derivative): void {
        expect(fn () => $derivative->get_path())
            ->toThrow(RuntimeException::class, 'DerivativeImage: no Paths set (RequestBootstrap not run yet?)');
    });
});

/**
 * Confirmed-equivalent: line 101/102's EmptyStringToNotEmpty (the
 * `$rel_path`/`$rel_url` local initializers). Both are ALWAYS
 * unconditionally reassigned by the very next self::build() call --
 * every one of build()'s own exit paths (the early-return-as-source
 * branch, and the fallthrough token-building path) assigns both
 * by-ref out-params before returning -- so whatever placeholder value
 * they start with never survives to be observed.
 */
test('url() computes the derivative url via a real build() call, prefixed by the real root url', function (): void {
    // Kills line 103's RemoveMethodCall (without a real build() call,
    // $rel_url stays '' and $params stays non-null, producing just the
    // bare root url instead) and line 107's ConcatRemoveLeft/
    // ConcatRemoveRight/ConcatSwitchSides -- every other test in this
    // file boots a plain Kernel with the container's own default
    // UrlService, whose '' root url can't distinguish a dropped or
    // reordered getRootUrl() call from a kept, correctly-ordered one.
    KernelContainerOverride::with([
        Paths::class => Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-url-only'),
        UrlServiceInterface::class => new DerivativeImageTestFakeUrlService('/gallery/'),
    ], function (): void {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'gallery/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        $url = DerivativeImage::url(new DerivativeParams(SizingParams::classic(80, 60)), $src);

        expect($url)->toBe('/gallery/i.php?/gallery/photo-cu_80x60_a.jpg');
    });
});

test('url() throws when a get_derivative_url handler returns something other than a GetDerivativeUrl instance', function (): void {
    // Kernel must boot before the handler is registered -- EventDispatcherTestFactory::get()
    // resolves the pre-boot memoized fallback instance until Kernel::boot()
    // builds the container's own (different) shared instance; registering
    // before boot would silently register on an instance DerivativeImage's
    // own get()-shim call never sees once the container exists.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-url-filter-only'));
    $handler = static fn (): int => 42;
    EventDispatcherTestFactory::get()->addEventHandler(GetDerivativeUrl::class, $handler);

    try {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'gallery/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        expect(fn () => DerivativeImage::url(new DerivativeParams(SizingParams::classic(80, 60)), $src))
            ->toThrow(Error::class, 'must return an instance of');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(GetDerivativeUrl::class, $handler);
    }
});

test('get_all() coerces a plain info array into a SrcImage and keys the result by defined type', function (): void {
    // get_all() coerces the plain array via `new SrcImage(...)`, which
    // needs a booted Kernel (SrcImage's own pictureExtensions() read) --
    // boot before snapshotting/seeding ImageStdParams so every
    // ImageStdParamsTestFactory::get() call in this test resolves the same
    // (container-shared) instance, not a pre-boot fallback later abandoned.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-get-all-1'));
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
        expect($all['square']->src_image->id)->toBe(7);
        expect($all['square']->src_image->rel_path)->toBe('upload/2026/07/photo.jpg');
    } finally {
        derivativeImageTestRestoreStdParams($snapshot);
    }
});

test('get_all() maps a disabled type to the SAME instance as its fallback enabled type', function (): void {
    // Kills line 144's ForeachEmptyIterable -- the sibling test above
    // seeds an empty undefined-type map (the default), so this second
    // foreach never has anything to iterate regardless of whether the
    // real iterable or an empty one is used.
    //
    // Boot first for the same reason as the sibling get_all() test above --
    // get_all() constructs a SrcImage internally, which now needs a booted
    // Kernel, and ImageStdParamsTestFactory::get() must resolve the same instance
    // throughout.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-get-all-2'));
    $snapshot = derivativeImageTestSnapshotStdParams();

    try {
        derivativeImageTestSeedStdParams([
            'square' => new DerivativeParams(SizingParams::square(120)),
        ], [
            'thumb' => 'square',
        ]);

        $all = DerivativeImage::get_all([
            'id' => 7,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        expect(array_keys($all))->toBe(['square', 'thumb']);
        expect($all['thumb'])->toBe($all['square']);
    } finally {
        derivativeImageTestRestoreStdParams($snapshot);
    }
});

test('get_one() falls back to the mapped enabled type for a disabled type, and returns null for an unknown type', function (): void {
    // get_one() also constructs a SrcImage internally from the plain array
    // -- same "boot before snapshot/seed" reasoning as get_all() above.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-get-one'));
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
    // SrcImage's own constructor needs a booted Kernel (its
    // pictureExtensions() read).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-no-extension'));
    $src = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
    ]);
    // Mutate directly (public property) to a path with no extension --
    // constructing SrcImage this way instead would route through the
    // mimetype-icon branch rather than IS_ORIGINAL, a different scenario.
    $src->rel_path = 'upload/2026/07/photoNoExtension';

    expect(fn () => new DerivativeImage(new DerivativeParams(SizingParams::classic(50, 50)), $src,CurrentConfigTestFactory::get()))
        ->toThrow(Exception::class, "DerivativeImage::build(): path 'upload/2026/07/photoNoExtension' has no extension");
});

test('build() strips a leading "./" from the source location and appends the custom-type url tokens', function (): void {
    // get_path() reads CurrentPathsTestFactory::get() -- set explicitly since this
    // is the first test in the file to call it.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-path-only'));

    $src = new SrcImage([
        'id' => 1,
        'path' => './gallery/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(120, 90)), $src,CurrentConfigTestFactory::get());

    // SizingParams::classic()'s own int max_crop=0 default never
    // satisfies add_url_tokens()'s strict `=== 0.0` fast-path check (see
    // SizingParamsTest.php's own documented finding), so the token is the
    // general 2-token form ('120x90', fractionToChar(0)='a'), not the
    // 's120x90' shorthand.
    expect($derivative->get_path())->toBe(CurrentPathsTestFactory::get()->root . '_data/i/gallery/photo-cu_120x90_a.jpg');
    // No real cached file exists on disk at that path -- build()'s own
    // filemtime() check correctly falls back to the dynamic i.php?
    // URL style rather than a direct cached-file URL.
    expect($derivative->get_url())->toBe('i.php?/gallery/photo-cu_120x90_a.jpg');
});

test('build() strips a leading "../" from the source location', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-path-only'));

    $src = new SrcImage([
        'id' => 1,
        'path' => '../gallery/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(64, 64)), $src,CurrentConfigTestFactory::get());

    expect($derivative->get_path())->toBe(CurrentPathsTestFactory::get()->root . '_data/i/gallery/photo-cu_64_a.jpg');
    // Same reasoning as the leading-"./" test above: no cached file on
    // disk means the dynamic i.php? URL style, not a direct cached URL.
    expect($derivative->get_url())->toBe('i.php?/gallery/photo-cu_64_a.jpg');
});

test('build() searches the whole defined-type list without an out-of-bounds read when the current type is not found in it', function (): void {
    // Kills line 207's SmallerToSmallerOrEqual (`$i <= count(...)`
    // instead of `<`) -- every other test above either skips this loop
    // entirely or has $params->type genuinely present in the seeded
    // defined-type list, so the loop always exits via its own internal
    // `break` before ever reaching the boundary. A type that's simply
    // absent from the map (real code: e.g. a since-disabled or
    // custom-only type reaching this search) forces the loop to run to
    // genuine natural completion, exposing the extra, out-of-bounds
    // `$defined_types[$i]` read as a real E_WARNING real code never
    // raises.
    //
    // Boot first -- this test constructs a real SrcImage below, which
    // needs a booted Kernel, and ImageStdParamsTestFactory::get() must
    // resolve the same instance throughout, not a pre-boot fallback
    // abandoned once Kernel::boot() runs later.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-defined-type-search'));
    $snapshot = derivativeImageTestSnapshotStdParams();
    $originalWatermark = ImageStdParamsTestFactory::get()->get_watermark();

    try {
        $thumbParams = new DerivativeParams(SizingParams::classic(50, 50));
        $thumbParams->type = 'thumb';
        derivativeImageTestSeedStdParams([
            'thumb' => $thumbParams,
        ]);

        $mysteryParams = new DerivativeParams(SizingParams::classic(200, 200));
        $mysteryParams->type = 'mystery';

        $watermark = new WatermarkParams();
        $watermark->file = 'watermark.png';
        $watermark->min_size = [5, 5];
        ImageStdParamsTestFactory::get()->set_watermark($watermark);
        $mysteryParams->use_watermark = true;

        $src = new SrcImage([
            'id' => 6,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
            'width' => 10,
            'height' => 10,
        ]);

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });
        try {
            $derivative = new DerivativeImage($mysteryParams, $src,CurrentConfigTestFactory::get());
        } finally {
            restore_error_handler();
        }

        expect($derivative->get_type())->toBe('mystery');
        expect($warnings)->toBe([]);
    } finally {
        derivativeImageTestRestoreStdParams($snapshot);
        ImageStdParamsTestFactory::get()->set_watermark($originalWatermark);
    }
});

/**
 * Confirmed-equivalent: line 207's IncrementInteger (`$i = 1` instead of
 * `$i = 0`). Skipping index 0 only matters if $params->type matches
 * defined_types[0] exactly -- but a match at index 0 can never lead to
 * a smaller substitute anyway (the inner search loop starts at $i-1 =
 * -1, which never runs), so a match there and no-match-at-all both
 * settle on the exact same outcome: no substitution. Proven for every
 * possible defined-type-list shape, not just one tested case; live
 * sed-verified against the full suite too.
 */

/**
 * Confirmed-equivalent: line 201's RemoveBooleanCast (`!$src->rotation`
 * instead of `!(bool) $src->rotation`). SrcImage::$rotation is always
 * assigned a real int (intval()'s result, see SrcImage's own
 * constructor) -- PHP's `!` operator already performs the exact same
 * truthiness coercion an explicit `(bool)` cast would, for every int
 * value, so the cast is redundant here (unlike e.g. a string "0" where
 * the two could disagree). Live sed-verified against the full suite:
 * passes identically.
 */
test('build() uses the source image as-is, unrotated and unwatermarked, when it already fits the requested size', function (): void {
    // Kills line 201's RemoveNot -- every other
    // test in this file either skips this branch entirely (no
    // width/height, per the file's own docblock) or forces
    // will_watermark()=true specifically to reach the smaller-type
    // search below it. This is the only test that actually reaches the
    // early-return "use source as-is" path at all: no watermark
    // configured (a real ImageStdParams::get_watermark() default has a
    // large min_size, so will_watermark() is false for these small
    // dimensions) and rotation=0 (the SrcImage default).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-as-is-only'));

    $src = new SrcImage([
        'id' => 5,
        'path' => 'upload/2026/07/photo.jpg',
        'file' => 'photo.jpg',
        'width' => 40,
        'height' => 40,
    ]);

    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(200, 200)), $src,CurrentConfigTestFactory::get());

    expect($derivative->same_as_source())->toBeTrue();
    expect($derivative->get_path())->toBe(CurrentPathsTestFactory::get()->root . 'upload/2026/07/photo.jpg');
});

test('build() does not strip a leading "../" look-alike that is not actually followed by a slash', function (): void {
    // Kills line 233's DecrementInteger (substr_compare(..., 0, 2)
    // instead of 0, 3) -- the sibling "strips a leading ../" test's own
    // path starts with the exact 3 characters '../', which a 2-char
    // "..") comparison also happens to match, hiding the difference.
    // A path starting with '..' but NOT followed by '/' must be left
    // untouched by real code.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-path-only'));

    $src = new SrcImage([
        'id' => 1,
        'path' => '..2026/gallery/photo.jpg',
        'file' => 'photo.jpg',
    ]);

    $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(64, 64)), $src,CurrentConfigTestFactory::get());

    expect($derivative->get_path())->toBe(CurrentPathsTestFactory::get()->root . '_data/i/..2026/gallery/photo-cu_64_a.jpg');
});

/**
 * Confirmed-equivalent: line 245's RemoveBooleanCast (`!$url_style`
 * instead of `!(bool) $url_style`). CurrentConfig::derivativeUrlStyle()
 * is declared `: int`, always a real int -- PHP's `!` already performs
 * the same truthiness coercion an explicit `(bool)` cast would for any
 * int, same reasoning as line 201's rotation cast. Also
 * confirmed-equivalent: line 252's DecrementInteger (`$url_style = 0`
 * instead of `= 1`). $url_style is only ever compared with `=== 2`
 * afterward (never `=== 1` or `=== 0`), so 0 and 1 are indistinguishable
 * final values -- both take the exact same "direct static link" branch.
 * Live sed-verified both against the full suite: pass identically.
 */
test('build() treats a cached file as fresh when its mtime exactly equals last_mod_time, not just when newer', function (): void {
    // Kills line 248's SmallerToSmallerOrEqual (`$mtime <=
    // $params->last_mod_time` instead of `<`) -- the sibling "fresh
    // cached file" test's file mtime is however old real filesystem
    // writes land (definitely newer than last_mod_time=0's default), so
    // it can't reach the exact-equality boundary. Setting
    // last_mod_time to the file's own real, just-read mtime pins it
    // exactly on that boundary: real code (a strict `<`) still treats
    // an exactly-equal mtime as fresh; the mutant would wrongly treat
    // it as stale.
    $root = sys_get_temp_dir() . '/piwigo-derivative-image-test-' . bin2hex(random_bytes(8));
    mkdir($root . '/_data/i/gallery', 0o777, true);
    file_put_contents($root . '/_data/i/gallery/photo-cu_80x60_a.jpg', 'cached-bytes');
    Kernel::boot(Paths::fromRoot($root));
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->derivativeUrlStyle = 0;

    try {
        $params = new DerivativeParams(SizingParams::classic(80, 60));
        $mtime = filemtime($root . '/_data/i/gallery/photo-cu_80x60_a.jpg');
        if ($mtime === false) {
            throw new RuntimeException('filemtime() unexpectedly failed on a file this test just wrote');
        }
        $params->last_mod_time = $mtime;

        $src = new SrcImage([
            'id' => 1,
            'path' => 'gallery/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        $derivative = new DerivativeImage($params, $src, $currentConfig);

        expect($derivative->is_cached())->toBeTrue();
        expect($derivative->get_url())->toBe('_data/i/gallery/photo-cu_80x60_a.jpg');
    } finally {
        $currentConfig->derivativeUrlStyle = 2;
        derivativeCacheServiceRrmdirDerivativeImageTest($root);
    }
});

test('build() substitutes a smaller already-defined identity-matching type when watermarking would otherwise apply, recursing until none remains', function (): void {
    // Kernel::boot() must run before the snapshot/get_watermark() reads
    // below -- ImageStdParamsTestFactory::get() resolves a different (fresh,
    // container-built) instance once Kernel is booted than the pre-boot
    // memoized fallback it returns beforehand, and build() (inside the
    // real DerivativeImage construction further down) always reads
    // whichever instance is current *after* boot.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-substitute-only'));
    $snapshot = derivativeImageTestSnapshotStdParams();
    $originalWatermark = ImageStdParamsTestFactory::get()->get_watermark();

    try {
        $thumbParams = new DerivativeParams(SizingParams::classic(50, 50));
        $thumbParams->type = 'thumb';
        $mediumParams = new DerivativeParams(SizingParams::classic(200, 200));
        $mediumParams->type = 'medium';

        derivativeImageTestSeedStdParams([
            'thumb' => $thumbParams,
            'medium' => $mediumParams,
        ]);

        $watermark = new WatermarkParams();
        $watermark->file = 'watermark.png';
        $watermark->min_size = [30, 30];
        ImageStdParamsTestFactory::get()->set_watermark($watermark);
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

        $derivative = new DerivativeImage($mediumParams, $src,CurrentConfigTestFactory::get());

        // 40x40 is an identity match for both 'medium' (200x200) and the
        // smaller 'thumb' (50x50); will_watermark(40x40) is true for
        // 'medium' (30<=40), so build() substitutes 'thumb' instead of
        // returning the source as-is -- then recurses on 'thumb', where
        // will_watermark(40x40) is true again (still 30<=40) but no type
        // smaller than 'thumb' exists in the seeded map, so the inner
        // search loop's break is hit and 'thumb' is the final type.
        expect($derivative->get_type())->toBe('thumb');
        expect($derivative->same_as_source())->toBeFalse();
        // Kills line 213's RemoveMethodCall (the recursive self::build()
        // call that recomputes rel_path/rel_url for the substituted
        // 'thumb' type) -- get_type()/same_as_source() alone can't tell
        // it apart: $params is already reassigned to 'thumb' by the line
        // just above build()'s own call, regardless of whether build()
        // itself ever runs again. Only rel_path/rel_url (and therefore
        // get_path()) would still reflect the never-recomputed initial
        // '' otherwise.
        expect($derivative->get_path())->toBe(CurrentPathsTestFactory::get()->root . '_data/i/upload/2026/07/photo-th.jpg');
    } finally {
        derivativeImageTestRestoreStdParams($snapshot);
        ImageStdParamsTestFactory::get()->set_watermark($originalWatermark);
    }
});

test('build() never substitutes a same-size candidate whose max_crop does not match, even though it is otherwise an identity match', function (): void {
    // Kills line 211's BooleanAndToBooleanOr (`||` instead of `&&`) --
    // the sibling test above's only smaller candidate matches on
    // BOTH clauses, which can't tell a dropped `&&` requirement apart
    // from a kept one. 'thumb' here is deliberately identity-matching
    // (is_identity() alone would say yes) but max_crop-mismatched --
    // real code (requiring both) finds no valid smaller candidate at
    // all and leaves 'medium' unsubstituted; the `||` mutant would
    // wrongly accept 'thumb' on is_identity() alone.
    //
    // This same test also kills line 209's PostDecrementToPostIncrement
    // (the inner loop's own per-iteration `$i--` mutated to `$i++`):
    // with the one candidate here failing its (correctly-&&'d) check,
    // real code's `$i--` naturally exhausts the loop (no candidates
    // left below index 0); the mutant's `$i++` would instead step BACK
    // UP onto 'medium' itself, which always trivially re-matches its
    // own criteria, substituting 'medium' with itself and recursing
    // into build() with identical parameters every time -- an infinite
    // recursion, not a clean pass/fail, so this case is deliberately
    // NOT hand-verified via the live sed-and-rerun technique used
    // elsewhere in this test suite (unlike a real assertion mismatch, there
    // is no safe bounded way to trigger it without risking a hung
    // process) -- accepted as a real, reasoned-through kill instead.
    //
    // Boot first -- same "SrcImage below needs a booted Kernel, keep
    // ImageStdParamsTestFactory::get() consistent throughout" reasoning as the
    // sibling defined-type-search test above.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-same-size-mismatch'));
    $snapshot = derivativeImageTestSnapshotStdParams();
    $originalWatermark = ImageStdParamsTestFactory::get()->get_watermark();

    try {
        $thumbParams = new DerivativeParams(SizingParams::classic(50, 50));
        $thumbParams->type = 'thumb';
        $thumbParams->sizing->max_crop = 0.5;
        $mediumParams = new DerivativeParams(SizingParams::classic(200, 200));
        $mediumParams->type = 'medium';

        derivativeImageTestSeedStdParams([
            'thumb' => $thumbParams,
            'medium' => $mediumParams,
        ]);

        $watermark = new WatermarkParams();
        $watermark->file = 'watermark.png';
        $watermark->min_size = [5, 5];
        ImageStdParamsTestFactory::get()->set_watermark($watermark);
        $thumbParams->use_watermark = true;
        $mediumParams->use_watermark = true;

        $src = new SrcImage([
            'id' => 4,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
            'width' => 10,
            'height' => 10,
        ]);

        $derivative = new DerivativeImage($mediumParams, $src,CurrentConfigTestFactory::get());

        expect($derivative->get_type())->toBe('medium');
        expect($derivative->same_as_source())->toBeFalse();
    } finally {
        derivativeImageTestRestoreStdParams($snapshot);
        ImageStdParamsTestFactory::get()->set_watermark($originalWatermark);
    }
});

test('build() routes through i.php and marks itself not cached when derivative_url_style is auto and no cached file exists yet', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-derivative-image-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->derivativeUrlStyle = 0;

    try {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'gallery/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(80, 60)), $src, $currentConfig);

        expect($derivative->is_cached())->toBeFalse();
        expect($derivative->get_url())->toBe('i.php?/gallery/photo-cu_80x60_a.jpg');
    } finally {
        $currentConfig->derivativeUrlStyle = 2;
        derivativeCacheServiceRrmdirDerivativeImageTest($root);
    }
});

test('build() links directly to a static file when derivative_url_style is auto and a fresh cached file already exists', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-derivative-image-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->derivativeUrlStyle = 0;

    try {
        mkdir($root . '/_data/i/gallery', 0o777, true);
        file_put_contents($root . '/_data/i/gallery/photo-cu_80x60_a.jpg', 'cached-bytes');

        $src = new SrcImage([
            'id' => 1,
            'path' => 'gallery/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(80, 60)), $src, $currentConfig);

        expect($derivative->is_cached())->toBeTrue();
        expect($derivative->get_url())->toBe('_data/i/gallery/photo-cu_80x60_a.jpg');
    } finally {
        $currentConfig->derivativeUrlStyle = 2;
        derivativeCacheServiceRrmdirDerivativeImageTest($root);
    }
});

test('get_url() prefixes the computed rel_url with the real root url', function (): void {
    // Kills line 280's ConcatRemoveLeft/ConcatSwitchSides -- every
    // other get_url()-exercising test in this file boots a plain Kernel
    // with the container's own default UrlService, whose '' root url
    // can't distinguish a dropped or reordered getRootUrl() call from a
    // kept, correctly-ordered one.
    KernelContainerOverride::with([
        Paths::class => Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-get-url-only'),
        UrlServiceInterface::class => new DerivativeImageTestFakeUrlService('/gallery/'),
    ], function (): void {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'gallery/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(80, 60)), $src,CurrentConfigTestFactory::get());

        expect($derivative->get_url())->toBe('/gallery/i.php?/gallery/photo-cu_80x60_a.jpg');
    });
});

test('get_url() throws when a get_derivative_url handler returns something other than a GetDerivativeUrl instance', function (): void {
    // Kernel must boot before the handler is registered -- see the sibling
    // url() test's own comment above for why.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-filter-only'));
    $handler = static fn (): int => 42;
    EventDispatcherTestFactory::get()->addEventHandler(GetDerivativeUrl::class, $handler);

    try {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'gallery/photo.jpg',
            'file' => 'photo.jpg',
        ]);

        $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(80, 60)), $src,CurrentConfigTestFactory::get());

        expect(fn () => $derivative->get_url())->toThrow(Error::class, 'must return an instance of');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(GetDerivativeUrl::class, $handler);
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

/**
 * Confirmed-equivalent: lines 330/345/360/375/401's RemoveBooleanCast
 * (`if ($size)` instead of `if ((bool) $size)`, across get_size_css/
 * get_size_htm/get_size_hr/get_scaled_size/get_scaled_size_htm). $size
 * is always `?array` (get_size()'s own return type) -- PHP's `if()`
 * already treats a non-empty array as true and an empty array (or
 * null) as false with no cast needed, the exact same rule an explicit
 * `(bool)` cast would apply. True for every possible array/null value,
 * not just the tested cases; live sed-verified 2 of the 5 (structurally
 * identical across all 5) against the full suite too.
 */
test('get_size_css()/get_size_htm()/get_size_hr() render the computed size, or an empty string when the size cannot be computed', function (): void {
    // Boot first, with the SAME root used for the real broken.jpg file
    // below -- SrcImage's own constructor needs a booted Kernel (its
    // pictureExtensions() read), and Kernel::boot() is idempotent, so a
    // second boot() call with a different root later in this test would
    // silently no-op rather than actually switching roots.
    $root = sys_get_temp_dir() . '/piwigo-derivative-image-test-' . bin2hex(random_bytes(8));
    mkdir($root . '/upload/2026/07', 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        $src = new SrcImage([
            'id' => 1,
            'path' => 'upload/2026/07/photo.jpg',
            'file' => 'photo.jpg',
            'width' => 400,
            'height' => 300,
        ]);
        $derivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(100, 100)), $src,CurrentConfigTestFactory::get());

        // 400x300 scaled to fit within 100x100: width is the binding
        // dimension (ratio 4 vs 3), so height scales proportionally to 75.
        expect($derivative->get_size())->toBe([100, 75]);
        expect($derivative->get_size_css())->toBe('width:100px; height:75px');
        expect($derivative->get_size_htm())->toBe('width="100" height="75"');
        expect($derivative->get_size_hr())->toBe('100 x 75');

        file_put_contents($root . '/upload/2026/07/broken.jpg', 'not-a-real-image-payload');

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
        $unknownDerivative = new DerivativeImage(new DerivativeParams(SizingParams::classic(100, 100)), $unknownSrc,CurrentConfigTestFactory::get());

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
    // SrcImage's own constructor needs a booted Kernel (its
    // pictureExtensions() read).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-scaled-size'));

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
    $landscape = new DerivativeImage(new DerivativeParams(SizingParams::classic(100, 100)), $landscapeSrc,CurrentConfigTestFactory::get());
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
    $portrait = new DerivativeImage(new DerivativeParams(SizingParams::classic(100, 100)), $portraitSrc,CurrentConfigTestFactory::get());
    expect($portrait->get_size())->toBe([75, 100]);
    expect($portrait->get_scaled_size(50, 50))->toBe([37, 50]);
    expect($portrait->get_scaled_size_htm(50, 50))->toBe('width="37" height="50"');

    // Within bounds already (no dimension overflows maxw/maxh): returned
    // unchanged, same shape as the un-scaled get_size().
    expect($landscape->get_scaled_size(200, 200))->toBe([100, 75]);
});

test('get_scaled_size() still scales when only ONE dimension overflows, not just when both do', function (): void {
    // Kills line 378's two IncrementInteger mutations (`$ratio_w > 2`/
    // `$ratio_h > 2` instead of `> 1`) and BooleanOrToBooleanAnd (`&&`
    // instead of `||`) -- the sibling test above always has BOTH
    // ratios simultaneously > 1 (a uniformly-scaled-down source), so
    // neither an incremented threshold nor a stricter && can be told
    // apart from the real || (whichever clause survives the mutation
    // still independently triggers scaling). A source already at
    // exactly its target size on one axis and overflowing only on the
    // other closes both: ratio 1.5 on the overflowing axis (between 1
    // and 2, so `> 2` wrongly reads it as in-bounds) and ratio 0.5 on
    // the other (comfortably <= 1, so it can never independently
    // trigger scaling either).
    //
    // SrcImage's own constructor needs a booted Kernel (its
    // pictureExtensions() read).
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-derivative-image-test-scaled-size-one-dim'));
    $wideSrc = new SrcImage([
        'id' => 1,
        'path' => 'upload/2026/07/wide.jpg',
        'file' => 'wide.jpg',
        'width' => 150,
        'height' => 50,
    ]);
    // classic(1000, 1000) never shrinks a 150x50 source -- get_size()
    // returns the real dimensions unscaled.
    $wide = new DerivativeImage(new DerivativeParams(SizingParams::classic(1000, 1000)), $wideSrc,CurrentConfigTestFactory::get());
    expect($wide->get_size())->toBe([150, 50]);
    // ratio_w = 150/100 = 1.5 (overflows, but not > 2); ratio_h = 50/100
    // = 0.5 (comfortably within bounds).
    expect($wide->get_scaled_size(100, 100))->toBe([100, 33]);

    $tallSrc = new SrcImage([
        'id' => 2,
        'path' => 'upload/2026/07/tall.jpg',
        'file' => 'tall.jpg',
        'width' => 50,
        'height' => 150,
    ]);
    $tall = new DerivativeImage(new DerivativeParams(SizingParams::classic(1000, 1000)), $tallSrc,CurrentConfigTestFactory::get());
    expect($tall->get_size())->toBe([50, 150]);
    // ratio_w = 50/100 = 0.5 (within bounds); ratio_h = 150/100 = 1.5
    // (overflows, but not > 2).
    expect($tall->get_scaled_size(100, 100))->toBe([33, 100]);
});

/**
 * Confirmed-equivalent: line 378's two GreaterToGreaterOrEqual (`>= 1`
 * instead of `> 1`) and line 379's GreaterToGreaterOrEqual (`>=`
 * instead of `>`). All three only disagree with real code at an EXACT
 * ratio of 1 (378) or exactly-equal ratio_w/ratio_h (379) -- and at
 * that precise boundary, entering the scaling block is mathematically
 * a no-op: dividing by a ratio of exactly 1, or computing either
 * "bound" dimension when both ratios already match, converges on the
 * identical [$maxw, $maxh]-derived result either way. True for every
 * possible size/maxw/maxh combination at that boundary, not just one
 * tested case; live sed-verified too.
 */
