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
    CurrentConfig::reset();
});

afterEach(function (): void {
    CurrentConfig::reset();
});

test('scriptBasename skips a candidate whose extension is not .php when phpExtensionInUrls is enforced, falling through to the next one', function (): void {
    $saved = pageFilterHelperTestSaveServerKeys();
    CurrentConfig::setPhpExtensionInUrls(true);
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

    CurrentConfig::setFilterPages([
        'picture' => ['show_thumbnail_caption' => true],
        'default' => ['hide_menu' => false],
    ]);

    try {
        expect(PageFilterHelper::getFilterPageValue('unrelated_setting'))->toBeNull();
    } finally {
        pageFilterHelperTestRestoreServerKeys($saved);
    }
});
