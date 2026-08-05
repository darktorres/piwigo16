<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\PageFilterHelper;

/**
 * No prior PageFilterHelperTest.php existed. scriptBasename()'s
 * SCRIPT_NAME/SCRIPT_FILENAME/PHP_SELF fallback loop and
 * getFilterPageValue()'s page-then-default lookup already have full
 * coverage through other suites' real HTTP-request-driven callers -- this
 * file closes the two remaining gaps: the phpExtensionInUrls()
 * non-.php-extension skip, and getFilterPageValue()'s final "neither page
 * nor default configures this value" null fallback.
 */
/**
 * @return array<string, string|null>
 */
function pageFilterHelperTestSaveServerKeys(): array
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? null;
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
    $phpSelf = $_SERVER['PHP_SELF'] ?? null;

    return [
        'SCRIPT_NAME' => is_string($scriptName) ? $scriptName : null,
        'SCRIPT_FILENAME' => is_string($scriptFilename) ? $scriptFilename : null,
        'PHP_SELF' => is_string($phpSelf) ? $phpSelf : null,
    ];
}

/**
 * @param array<string, string|null> $saved
 */
function pageFilterHelperTestRestoreServerKeys(array $saved): void
{
    foreach ($saved as $key => $value) {
        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }
}

beforeEach(function (): void {
    CurrentConfig::current()->reset();
});

afterEach(function (): void {
    CurrentConfig::current()->reset();
});

test('scriptBasename lowercases SCRIPT_NAME and uses it when it is the only candidate present', function (): void {
    // Kills line 19's RemoveArrayItem on 'SCRIPT_NAME' (without it in
    // the candidate list, no key is left to try at all) and line 22's
    // UnwrapStrtolower (without it, getExtension() sees the literal
    // ".PHP" suffix, which -- since phpExtensionInUrls defaults to
    // true -- doesn't match the required lowercase 'php', so this
    // candidate would be wrongly skipped, and with none left, the
    // method returns '' instead of 'upper').
    $saved = pageFilterHelperTestSaveServerKeys();
    $_SERVER['SCRIPT_NAME'] = '/gallery/UPPER.PHP';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);

    try {
        expect(PageFilterHelper::scriptBasename())->toBe('upper');
    } finally {
        pageFilterHelperTestRestoreServerKeys($saved);
    }
});

test('scriptBasename skips a genuinely unset candidate without crashing, falling through to the next one', function (): void {
    // Kills line 21's BooleanAndToBooleanOr (`||` instead of `&&`):
    // with SCRIPT_NAME entirely unset, $raw is null. Real code's `&&`
    // short-circuits on is_string(null) === false and skips it. The
    // `||` mutant instead evaluates the second operand (`null !== ''`,
    // which is true, since null and '' are different types) and
    // wrongly treats the candidate as usable -- confirmed live this
    // throws a TypeError from strtolower(null) under strict_types,
    // rather than gracefully falling through to SCRIPT_FILENAME.
    $saved = pageFilterHelperTestSaveServerKeys();
    unset($_SERVER['SCRIPT_NAME']);
    $_SERVER['SCRIPT_FILENAME'] = '/var/www/piwigo/picture.php';
    unset($_SERVER['PHP_SELF']);

    try {
        expect(PageFilterHelper::scriptBasename())->toBe('picture');
    } finally {
        pageFilterHelperTestRestoreServerKeys($saved);
    }
});

test('scriptBasename uses PHP_SELF when it is the only candidate present', function (): void {
    // Kills line 19's RemoveArrayItem on 'PHP_SELF': without it in the
    // candidate list, and with both other keys unset, no candidate is
    // left to try at all.
    $saved = pageFilterHelperTestSaveServerKeys();
    unset($_SERVER['SCRIPT_NAME'], $_SERVER['SCRIPT_FILENAME']);
    $_SERVER['PHP_SELF'] = '/gallery/picture.php';

    try {
        expect(PageFilterHelper::scriptBasename())->toBe('picture');
    } finally {
        pageFilterHelperTestRestoreServerKeys($saved);
    }
});

/**
 * Confirmed-equivalent: line 21's EmptyStringToNotEmpty (`$raw !==
 * 'PEST Mutator was here!'` instead of `!== ''`). A genuinely empty
 * SCRIPT_NAME either gets correctly skipped by the real `!== ''` check,
 * or -- under the mutant, which wrongly treats it as usable -- reduces
 * to `strtolower('') === ''`, which basename('', '.php') also reduces
 * to '', tripping line 33's own "empty basename, try the next
 * candidate" check and converging on the exact same fall-through
 * behavior either way. Confirmed live with phpExtensionInUrls both
 * on and off: identical output in both configurations.
 */
test('scriptBasename skips a candidate whose extension is not .php when phpExtensionInUrls is enforced, falling through to the next one', function (): void {
    $saved = pageFilterHelperTestSaveServerKeys();
    CurrentConfig::current()->setPhpExtensionInUrls(true);
    $_SERVER['SCRIPT_NAME'] = '/gallery/index.html';
    $_SERVER['SCRIPT_FILENAME'] = '/var/www/piwigo/picture.php';
    unset($_SERVER['PHP_SELF']);

    try {
        expect(PageFilterHelper::scriptBasename())->toBe('picture');
    } finally {
        pageFilterHelperTestRestoreServerKeys($saved);
    }
});

test('getFilterPageValue returns null when neither the page nor the default entry configures the requested value', function (): void {
    $saved = pageFilterHelperTestSaveServerKeys();
    $_SERVER['SCRIPT_NAME'] = '/gallery/picture.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);

    CurrentConfig::current()->setFilterPages([
        'picture' => ['show_thumbnail_caption' => true],
        'default' => ['hide_menu' => false],
    ]);

    try {
        expect(PageFilterHelper::getFilterPageValue('unrelated_setting'))->toBeNull();
    } finally {
        pageFilterHelperTestRestoreServerKeys($saved);
    }
});

test('getFilterPageValue returns the page-specific value when configured, falling back to the default entry otherwise', function (): void {
    // Kills line 51's CoalesceRemoveLeft (dropping the read of
    // $filter_pages[$page_name], always null) via the page-specific
    // assertion, and line 55's CoalesceRemoveLeft (dropping the read
    // of $filter_pages['default'], always null) via the default-
    // fallback assertion -- confirmed live both mutations turn their
    // respective value into null instead of the real configured one.
    $saved = pageFilterHelperTestSaveServerKeys();
    $_SERVER['SCRIPT_NAME'] = '/gallery/picture.php';
    unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);

    CurrentConfig::current()->setFilterPages([
        'picture' => ['show_thumbnail_caption' => true],
        'default' => ['hide_menu' => false],
    ]);

    try {
        expect(PageFilterHelper::getFilterPageValue('show_thumbnail_caption'))->toBeTrue()
            ->and(PageFilterHelper::getFilterPageValue('hide_menu'))->toBeFalse();
    } finally {
        pageFilterHelperTestRestoreServerKeys($saved);
    }
});

test('scriptBasename falls through to the next candidate when basename() reduces to empty, not returning the empty string', function (): void {
    // Kills line 33's EmptyStringToNotEmpty: with phpExtensionInUrls
    // disabled, a SCRIPT_NAME of a bare '/' passes the (skipped)
    // extension check but basename('/', '.php') genuinely reduces to
    // '' -- real code correctly keeps looking and finds SCRIPT_FILENAME
    // instead; the mutant wrongly accepts and returns the empty string
    // immediately.
    $saved = pageFilterHelperTestSaveServerKeys();
    CurrentConfig::current()->setPhpExtensionInUrls(false);
    $_SERVER['SCRIPT_NAME'] = '/';
    $_SERVER['SCRIPT_FILENAME'] = '/var/www/piwigo/picture.php';
    unset($_SERVER['PHP_SELF']);

    try {
        expect(PageFilterHelper::scriptBasename())->toBe('picture');
    } finally {
        pageFilterHelperTestRestoreServerKeys($saved);
    }
});
