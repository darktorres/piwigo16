<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeCacheService;

// Unique per-run root: clearDerivativeCache()/deleteElementDerivatives()
// read CurrentPathsTestFactory::get()->root . CurrentConfig::derivativeDir() internally, not
// an explicit test-supplied path -- pointing CurrentPaths at a fresh,
// uniquely-named temp directory per test (rather than a shared constant)
// means the recursive-delete helper below can never touch anything outside
// this run's own sandbox, regardless of test ordering or other suites'
// own Kernel::boot() calls.
function derivative_cache_test_rrmdir(string $dir): void
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
        is_dir($path) ? derivative_cache_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    CurrentConfig::current()->reset();
    $root = sys_get_temp_dir() . '/piwigo-derivative-cache-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfig::current()->setDataLocation('data/');
    mkdir(CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir(), 0o777, true);
});

afterEach(function (): void {
    derivative_cache_test_rrmdir(CurrentPathsTestFactory::get()->root);
    CurrentConfig::current()->reset();
    Kernel::reset();
});

test('clearDerivativeCacheRecursive deletes only files matching the pattern', function (): void {
    $dir = CurrentPathsTestFactory::get()->root . 'derivatives';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/photo-th.jpg', 'x');
    file_put_contents($dir . '/photo-sq.jpg', 'x');
    file_put_contents($dir . '/original.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCacheRecursive($dir, '#.*-th\.jpg$#');

    expect(file_exists($dir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($dir . '/photo-sq.jpg'))->toBeTrue()
        ->and(file_exists($dir . '/original.jpg'))->toBeTrue();
});

test('clearDerivativeCacheRecursive removes an emptied subdirectory', function (): void {
    $base = CurrentPathsTestFactory::get()->root . 'derivatives';
    $dir = $base . '/2026';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/photo-th.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCacheRecursive($base, '#.*-th\.jpg$#');

    expect(is_dir($dir))->toBeFalse();
});

test('clearDerivativeCacheRecursive recurses into nested directories', function (): void {
    $base = CurrentPathsTestFactory::get()->root . 'derivatives';
    $nested = $base . '/a/b';
    mkdir($nested, 0o777, true);
    file_put_contents($nested . '/photo-th.jpg', 'x');
    file_put_contents($nested . '/photo-sq.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCacheRecursive($base, '#.*-th\.jpg$#');

    expect(file_exists($nested . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($nested . '/photo-sq.jpg'))->toBeTrue();
});

test('deleteElementDerivatives removes every derivative for the given element', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');
    file_put_contents($derivDir . '/other-th.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives([
        'path' => '2026/07/photo.jpg',
    ]);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/other-th.jpg'))->toBeTrue();
});

test('deleteElementDerivatives filters by a specific derivative type', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives([
        'path' => '2026/07/photo.jpg',
    ], 'thumb');

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});

test('deleteElementDerivatives\'s type pattern uses a trailing wildcard, matching a suffixed derivative filename too', function (): void {
    // Kills line 94's ConcatRemoveRight (`'-' . derivativeToUrl($type)`,
    // dropping the trailing '*') and ConcatSwitchSides (`'*-th'` instead
    // of `'-th*'`, wildcard on the wrong side). The sibling test above
    // ('photo-th.jpg', nothing between the type code and the extension)
    // can't distinguish either: '*' consuming zero characters produces
    // the identical literal match regardless of which side it's on or
    // whether it's present at all. A filename with a real suffix AFTER
    // the type code (a realistic "-th_100x100.jpg" size-stamped
    // derivative name) requires the wildcard, in the right place, to
    // match at all.
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th_100x100.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives([
        'path' => '2026/07/photo.jpg',
    ], 'thumb');

    expect(file_exists($derivDir . '/photo-th_100x100.jpg'))->toBeFalse();
});

test('deleteElementDerivatives inserts the type pattern without consuming any of the original filename', function (): void {
    // Kills line 96's DecrementInteger/IncrementInteger
    // (substr_replace(..., $dot, -1)/(..., $dot, 1) instead of `, 0`) --
    // both eat into the real extension text at $dot, but since a
    // trailing wildcard star can absorb the difference, a same-shaped
    // sibling test ('photo-th.jpg', matched either way) can't tell them
    // apart from real code: the pattern still matches the SAME real
    // file regardless. What differs is what it also, WRONGLY, matches:
    // real code's pattern requires the LITERAL '.jpg' (with the dot) at
    // the very end; both mutants only require literal "jpg"/"g"
    // (dropping the dot, or dropping the whole extension but one
    // letter). A stray file that ends the same way but has no dot at
    // all exposes the over-match.
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-thabcjpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives([
        'path' => '2026/07/photo.jpg',
    ], 'thumb');

    expect(file_exists($derivDir . '/photo-thabcjpg'))->toBeTrue();
});

test('deleteElementDerivatives does not rewrite the path when representative_ext is explicitly an empty string', function (): void {
    // Kills line 81's EmptyStringToNotEmpty. The existing
    // "representative_ext is given" test only exercises a genuinely
    // non-empty extension; an explicitly-empty string is falsy but
    // non-null, so it needs its own case to reach the false side of
    // this exact comparison.
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives([
        'path' => '2026/07/photo.jpg',
        'representative_ext' => '',
    ]);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse();
});

test('deleteElementDerivatives only strips a leading "../" when it is genuinely present, not any 2-char prefix', function (): void {
    // Kills line 84's DecrementInteger (substr_compare(..., 0, 2)
    // instead of 0, 3) -- a path starting with '..' but NOT followed by
    // '/' (so NOT a real "../" prefix) must be left untouched. Real code
    // requires all 3 characters to match; the mutant only checks the
    // first 2, wrongly treating "..2" as a "../" prefix and stripping
    // 3 characters off a path that never had one.
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '..2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives([
        'path' => '..2026/07/photo.jpg',
    ]);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse();
});

test('deleteElementDerivatives throws for a path with no extension', function (): void {
    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives(['path' => 'no_extension']);
})->throws(Exception::class);

test('deleteElementDerivatives never attempts to iterate a failed glob() result', function (): void {
    // Kills line 99's FalseToTrue (`$glob !== true` instead of `!==
    // false`). glob() never returns the literal `true`, only an array
    // or `false` -- so the mutant condition is ALWAYS true, meaning it
    // would still try to foreach() the `false` glob() failure result.
    // foreach() over a non-iterable doesn't throw (confirmed live, a
    // wrong initial assumption on this one) -- it raises its OWN
    // second, real E_WARNING and just skips the loop body, on top of
    // glob()'s own length-limit warning that fires either way. A
    // pattern longer than PHP's own glob() length limit (4096 chars)
    // is a real, reproducible way to make glob() actually return
    // false, confirmed live via a throwaway probe.
    $warnings = [];
    set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = $errstr;

        return true;
    });
    try {
        new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives([
            'path' => str_repeat('a', 5000) . '.jpg',
        ]);
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toHaveCount(1);
});

test('clearDerivativeCache with an explicit type list only matches those types', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCache(['thumb']);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});

test('clearDerivativeCache accepts a single type given as a plain string, not wrapped in an array', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCache('thumb');

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});

test('clearDerivativeCache re-indexes a non-sequential type array before iterating it by numeric index', function (): void {
    // Kills line 34's UnwrapArrayValues (`$resolvedTypes = $types`
    // instead of `array_values($types)`) -- the resolution loop right
    // below reads $resolvedTypes[$i] for $i = 0, 1, 2..., which only
    // works if the array is sequentially 0-indexed. An associative
    // array with non-numeric keys (as clearDerivativeCache's own
    // param type `array<int|string, string>` explicitly allows)
    // exposes the gap: without re-indexing, $resolvedTypes[0] would be
    // undefined.
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCache(['a' => 'thumb']);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse();
});

test('clearDerivativeCache groups 2+ types into a single alternation pattern', function (): void {
    // Kills line 52's IfNegated, GreaterToSmallerOrEqual, and
    // IncrementInteger -- every existing test above resolves to exactly
    // ONE type, never reaching the `count($resolvedTypes) > 1` grouped-
    // pattern branch at all.
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');
    file_put_contents($derivDir . '/photo-2s.jpg', 'x');
    // Kills line 53's ConcatSwitchSides (`implode('|', $resolvedTypes) .
    // '(' . ')'` instead of `'(' . implode(...) . ')'`) -- dropping the
    // wrapping parens turns a scoped `(th|sq)` alternation into an
    // UNSCOPED `th|sq` spanning the WHOLE surrounding regex, splitting
    // it into two independent alternatives: `.*-th` (no extension
    // requirement at all) and `sq()\.[a-zA-Z0-9]{3,4}$` (no '-' or
    // preceding-content requirement). The two files above happen to
    // still match either way (coincidentally: "sq" sits immediately
    // before the extension in "photo-sq.jpg" either way) -- this one
    // has "-th" as a mere substring, nowhere near a valid extension,
    // which only the FIRST (unscoped) alternative would wrongly match.
    file_put_contents($derivDir . '/photo-th_not_a_real_derivative.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCache(['thumb', 'square']);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-2s.jpg'))->toBeTrue()
        ->and(file_exists($derivDir . '/photo-th_not_a_real_derivative.jpg'))->toBeTrue();
});

/**
 * Confirmed-equivalent: line 52's GreaterToGreaterOrEqual (`>= 1`
 * instead of `> 1`) and DecrementInteger (`> 0` instead of `> 1`). Both
 * only disagree with real code at exactly count($resolvedTypes) === 1,
 * where they'd take the "wrap in a single-element alternation group"
 * branch instead of the plain one. A regex capturing group around a
 * single alternative with no '|' inside it (`implode('|', $one)` is
 * just that one element, unchanged) matches exactly the same set of
 * strings as the ungrouped value -- preg_match() only cares whether the
 * pattern matches, not how many groups wrap it. Live sed-verified with
 * both mutations applied against the full suite: both pass identically.
 */
test('clearDerivativeCache falls back to the custom-type pattern for a type name that is neither "all" nor a standard ImageStdParams type', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    // derivativeToUrl(CUSTOM) . '_' . $type == 'cu_myCustomWidget', matching
    // the '-cu_myCustomWidget.ext' filename a real custom-type derivative
    // would carry.
    file_put_contents($derivDir . '/photo-cu_myCustomWidget.jpg', 'x');
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCache('myCustomWidget');

    expect(file_exists($derivDir . '/photo-cu_myCustomWidget.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-th.jpg'))->toBeTrue();
});

test('deleteElementDerivatives rewrites the path to its pwg_representative form when representative_ext is given', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026/07/pwg_representative';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives([
        'path' => '2026/07/photo.pdf',
        'representative_ext' => 'jpg',
    ]);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse();
});

/**
 * Confirmed-equivalent: line 85's DecrementInteger (`substr($path, 2)`
 * instead of `substr($path, 3)`). Stripping only 2 of the 3 "../"
 * characters leaves a stray leading '/' on the path, which then gets
 * concatenated straight into glob()'s pattern argument -- producing a
 * double '//' where the derivatives dir and the (still-leading-slash)
 * remainder meet. glob() (and the underlying OS path resolution it
 * uses) treats a doubled path separator as equivalent to a single one,
 * so the resulting pattern matches the exact same files either way.
 * Live sed-verified against the full suite: passes identically.
 */
test('deleteElementDerivatives strips a leading "../" from the path', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->deleteElementDerivatives([
        'path' => '../2026/07/photo.jpg',
    ]);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse();
});

test('clearDerivativeCache does nothing, without throwing, when the derivatives directory does not exist', function (): void {
    // Kills line 61's FalseToTrue (`$contents !== true` instead of
    // `!== false`). opendir() never returns the literal `true`, only a
    // resource or `false` -- so the mutant condition is ALWAYS true,
    // meaning it would still try to readdir() the `false` opendir()
    // failure result. readdir()'s own parameter is a typed `resource`,
    // so passing it `false` throws a TypeError; real code's `!==
    // false` guard exists specifically to skip that (still surfacing
    // its own opendir() E_WARNING, same convention as the sibling
    // "cannot be opened" test below: `@` doesn't hide it from
    // PHPUnit's own error handler).
    rmdir(CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir());

    set_error_handler(static fn (): bool => true);
    try {
        new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCache('thumb');
    } finally {
        restore_error_handler();
    }

    expect(true)->toBeTrue();
});

test('clearDerivativeCache never removes the derivatives root directory itself, even once every year-folder is emptied out', function (): void {
    // Kills line 59's ConcatRemoveRight (opening the site root instead of
    // the derivatives dir) and line 63's IfNegated/NotIdenticalToIdentical
    // (x2)/BooleanAndToBooleanOr (x2) (each lets the '.' and/or '..'
    // pseudo-entries slip through the "real directory, not . or .." guard
    // and get passed to clearDerivativeCacheRecursive() as if they were
    // real subdirectories -- since '.' and '..' are filesystem aliases
    // for the current/parent directory, that recursive call still
    // reaches and deletes the SAME target files as the correct walk
    // would, which is exactly why the existing per-type deletion tests
    // above don't catch this: the file gets deleted either way. What
    // differs is that clearDerivativeCacheRecursive's own end-of-call
    // rmdir($path) cascade then runs against '.'/'..' too -- i.e.
    // against the derivatives root itself -- removing a directory real
    // code's outer loop (which only ever recurses into the root's own
    // CHILDREN, never the root path itself) would never touch).
    $derivRoot = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir();
    $derivDir = $derivRoot . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    // A plain file-existence assertion alone doesn't finish the job for
    // line 63's FIRST NotIdenticalToIdentical (the '.' variant): most
    // OSes flatly refuse rmdir("dir/."), so that spurious call always
    // fails with EINVAL and 'data/i' survives regardless -- but real
    // code never attempts that rmdir() call AT ALL, so it never raises
    // this warning either. Capturing warnings turns that silent-failure
    // difference into a real, assertable one.
    $warnings = [];
    set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = $errstr;

        return true;
    });
    try {
        new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCache('thumb');
    } finally {
        restore_error_handler();
    }

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(is_dir($derivRoot))->toBeTrue()
        ->and($warnings)->toBe([]);
});

test('clearDerivativeCache never recurses into a stray plain FILE at the derivatives root', function (): void {
    // Kills line 63's ConcatRemoveRight (`is_dir($root)` instead of
    // `is_dir($root . $node)`) -- with $root itself always a real,
    // existing directory, this reduces the guard to "any node that
    // isn't '.' or '..'", including plain FILES sitting directly at the
    // derivatives root. Passing a file path to
    // clearDerivativeCacheRecursive() is harmless in terms of file
    // deletion (its own un-@-suppressed opendir($path) call just fails
    // and returns false early) -- but that opendir() failure raises a
    // real, otherwise-absent E_WARNING, unlike real code which never
    // attempts to open a non-directory in the first place.
    $derivRoot = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir();
    file_put_contents($derivRoot . 'stray.txt', 'x');

    new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCache('thumb');

    expect(file_exists($derivRoot . 'stray.txt'))->toBeTrue();
});

test('clearDerivativeCacheRecursive\'s own return value reflects EVERY subdirectory succeeding, not just any one of them', function (): void {
    // Kills line 128's BooleanAndToBooleanOr (`$rmdir = $rmdir ||
    // $subRmdir` instead of `&&`) -- checked directly on the return
    // value rather than via file/directory existence, since rmdir()
    // silently no-ops on a non-empty directory regardless of which
    // logic decided to attempt it, masking the difference either way.
    // One subdirectory clears completely (nothing left to block its own
    // removal); the other has a stray file the given pattern doesn't
    // match, which blocks IT specifically. Real (&&) logic means one
    // blocked subdirectory blocks the whole parent; the mutant's (||)
    // logic would let the other subdirectory's success alone report
    // success for the parent too.
    $parent = CurrentPathsTestFactory::get()->root . 'parent';
    $goodChild = $parent . '/good';
    $badChild = $parent . '/bad';
    mkdir($goodChild, 0o777, true);
    mkdir($badChild, 0o777, true);
    file_put_contents($goodChild . '/photo-th.jpg', 'x');
    file_put_contents($badChild . '/unmatched.dat', 'x');

    $result = new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCacheRecursive($parent, '#-th\.jpg$#');

    expect($result)->toBeFalse();
});

test('clearDerivativeCacheRecursive never calls preg_match with a genuinely empty pattern', function (): void {
    // Kills line 129's EmptyStringToNotEmpty (`$pattern !== 'PEST
    // Mutator was here!'` instead of `!== ''`) -- an empty pattern is
    // a real, reachable input (this method is public and directly
    // called by admin/site_update.php with its own pattern, per this
    // class's own docblock), and preg_match() with a genuinely empty
    // pattern string raises a real E_WARNING ("Empty regular
    // expression"), unlike any non-empty pattern. Real code's `!== ''`
    // guard exists specifically to skip preg_match() entirely in that
    // case.
    $dir = CurrentPathsTestFactory::get()->root . 'derivatives';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/photo-th.jpg', 'x');

    $result = new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCacheRecursive($dir, '');

    expect($result)->toBeFalse()
        ->and(file_exists($dir . '/photo-th.jpg'))->toBeTrue();
});

test('clearDerivativeCacheRecursive\'s own return value reports failure when an unrecognized node is left behind', function (): void {
    // Kills line 134's FalseToTrue (`$rmdir = true` instead of `false`
    // in the "none of the above" else branch) -- checked directly on
    // the return value (matching the sibling AND/OR test above): even
    // with $rmdir wrongly forced true, the actual @rmdir() call still
    // silently fails on a non-empty directory either way, so
    // file/directory-existence assertions can't distinguish this.
    $dir = CurrentPathsTestFactory::get()->root . 'derivatives';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/unrecognized.dat', 'x');

    $result = new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCacheRecursive($dir, '#nomatch#');

    expect($result)->toBeFalse();
});

/**
 * Confirmed-equivalent/genuinely-hard: lines 67 and 137's
 * RemoveFunctionCall (both dropping a `closedir($contents)` call) and
 * line 143's RemoveFunctionCall (dropping `clearstatcache()`). All
 * three are pure resource/cache-lifecycle housekeeping with no return
 * value and no effect on this class's own subsequent behavior:
 * $contents is a local variable never read again after its owning
 * function's last use of it, so PHP's own refcounting GC closes the
 * directory handle immediately on function return regardless of an
 * explicit closedir() call; clearstatcache() only affects the
 * correctness of LATER is_dir()/file_exists()-family calls elsewhere in
 * the same request, and nothing in this class re-checks the just-
 * modified path afterward (rmdir()/unlink() themselves always hit the
 * real filesystem directly, never PHP's userland stat cache). Live
 * sed-verified each of the 3 against the full suite: all pass
 * identically. A cross-file interaction with another class's later
 * stat call is the only way clearstatcache()'s absence could ever
 * matter, which is outside what a unit test of this class can observe.
 */
test('clearDerivativeCacheRecursive returns false without throwing when the directory cannot be opened', function (): void {
    $missing = CurrentPathsTestFactory::get()->root . 'does-not-exist';

    // opendir() on a missing directory is a real, unsuppressed E_WARNING
    // at the call site (not `@`-guarded in the source) -- absorb it with
    // a scoped handler instead of letting failOnWarning="true" turn it
    // into a failure, same convention as PluginMaintainTest's own
    // set_error_handler()/restore_error_handler() pair.
    set_error_handler(static fn (): bool => true);
    try {
        $result = new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get())->clearDerivativeCacheRecursive($missing, '#.*#');
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeFalse();
});
