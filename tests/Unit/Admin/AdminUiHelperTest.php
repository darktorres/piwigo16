<?php

declare(strict_types=1);

use Piwigo\Admin\AdminUiHelper;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;

beforeEach(function (): void {
    // getExtents()'s no-args default is anchored to CurrentPaths::get()->
    // root (a real HTTP request's cwd is wherever Apache/PHP-FPM started
    // the process, not this project's root, so a `./`-relative default
    // silently resolved to nothing in production -- fixed at the source,
    // see AdminUiHelper's own docblock).
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 3)));
});

afterEach(function (): void {
    CurrentPaths::reset();
});

test('getExtents finds every .tpl file under the real template-extension directory, stripping the resolved root prefix', function (): void {
    // No args -> real default anchored to CurrentPaths::get()->root (the
    // repo root, same as every other test process in this suite) -- a
    // real, committed asset, not a throwaway fixture, so this asserts its
    // known real contents exactly.
    $result = AdminUiHelper::getExtents();

    sort($result);
    expect($result)->toBe([
        'distributed/samples/my-picture.tpl',
        'distributed/samples/my-thumbnails.tpl',
        'distributed/samples/my-thumbnails2.tpl',
        'distributed/samples/titling_categories.tpl',
    ]);
});

test('getExtents returns an empty array when the directory does not exist', function (): void {
    // opendir() on a genuinely missing path raises a real PHP warning even
    // though getExtents() itself already handles the false return
    // gracefully -- a plain @ does NOT stop PHPUnit's ErrorHandler from
    // surfacing it regardless (confirmed: @ only affects
    // error_reporting(), not whether the handler chain runs), so a real
    // no-op error handler for the duration of this one expected-to-warn
    // call is the only reliable way to swallow it, matching ImageGdTest's
    // own established pattern.
    set_error_handler(static fn (): bool => true);
    try {
        expect(AdminUiHelper::getExtents('/definitely/does/not/exist-' . uniqid()))->toBe([]);
    } finally {
        restore_error_handler();
    }
});

test('getExtents skips symlinked .tpl files and non-.tpl files', function (): void {
    $dir = sys_get_temp_dir() . '/piwigo-admin-ui-helper-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o777, true);

    try {
        file_put_contents($dir . '/real.tpl', 'real template');
        file_put_contents($dir . '/ignored.css', 'not a template');
        file_put_contents($dir . '/link-target.tpl', 'linked template');
        symlink($dir . '/link-target.tpl', $dir . '/symlinked.tpl');

        // The prefix strip is relative to whatever $start was actually
        // passed (not a hardcoded length) -- real.tpl and link-target.tpl
        // (a genuine file, not itself a symlink) both survive as bare
        // filenames; only the symlink itself (symlinked.tpl) and the
        // non-.tpl file are excluded.
        $result = AdminUiHelper::getExtents($dir);
        sort($result);

        expect($result)->toBe(['link-target.tpl', 'real.tpl']);
    } finally {
        unlink($dir . '/symlinked.tpl');
        unlink($dir . '/link-target.tpl');
        unlink($dir . '/ignored.css');
        unlink($dir . '/real.tpl');
        rmdir($dir);
    }
});

test('getExtents recurses into subdirectories', function (): void {
    $dir = sys_get_temp_dir() . '/piwigo-admin-ui-helper-test-' . bin2hex(random_bytes(8));
    mkdir($dir . '/nested/deeper', 0o777, true);

    try {
        file_put_contents($dir . '/top.tpl', 'top');
        file_put_contents($dir . '/nested/mid.tpl', 'mid');
        file_put_contents($dir . '/nested/deeper/bottom.tpl', 'bottom');

        $result = AdminUiHelper::getExtents($dir);

        expect($result)->toHaveCount(3);
    } finally {
        unlink($dir . '/nested/deeper/bottom.tpl');
        unlink($dir . '/nested/mid.tpl');
        unlink($dir . '/top.tpl');
        rmdir($dir . '/nested/deeper');
        rmdir($dir . '/nested');
        rmdir($dir);
    }
});

test('pwgUrl returns every known piwigo.org URL keyed by section', function (): void {
    expect(AdminUiHelper::pwgUrl())->toBe([
        'HOME' => AppInfo::URL,
        'WIKI' => AppInfo::URL . '/doc',
        'DEMO' => AppInfo::URL . '/demo',
        'FORUM' => AppInfo::URL . '/forum',
        'BUGS' => AppInfo::URL . '/bugs',
        'EXTENSIONS' => AppInfo::URL . '/ext',
    ]);
});

test('getNewsletterSubscribeBaseUrl and getOldNewslettersBaseUrl return their fixed piwigo.org URLs', function (): void {
    expect(AdminUiHelper::getNewsletterSubscribeBaseUrl())->toBe(AppInfo::URL . '/announcement/subscribe/');
    expect(AdminUiHelper::getOldNewslettersBaseUrl())->toBe(AppInfo::URL . '/newsletter');
});

test('getActiveMenu maps every known admin page to its menu section, and an unknown page to -1', function (): void {
    $expectations = [
        'photo' => 0, 'photos_add' => 0, 'rating' => 0, 'tags' => 0, 'batch_manager' => 0,
        'album' => 1, 'cat_list' => 1, 'albums' => 1, 'cat_options' => 1, 'cat_search' => 1, 'permalinks' => 1,
        'user_list' => 2, 'user_perm' => 2, 'group_list' => 2, 'group_perm' => 2, 'notification_by_mail' => 2, 'user_activity' => 2,
        'site_manager' => 3, 'site_update' => 3, 'stats' => 3, 'history' => 3, 'maintenance' => 3, 'comments' => 3, 'updates' => 3,
        'configuration' => 4, 'derivatives' => 4, 'extend_for_templates' => 4, 'menubar' => 4, 'themes' => 4, 'theme' => 4, 'languages' => 4,
        'not-a-real-page' => -1,
    ];

    foreach ($expectations as $page => $expectedMenu) {
        expect(AdminUiHelper::getActiveMenu($page))->toBe($expectedMenu);
    }
});

test('numberFormatHumanReadable formats small numbers with no suffix and no decimals', function (): void {
    expect(AdminUiHelper::numberFormatHumanReadable(0))->toBe('0');
    expect(AdminUiHelper::numberFormatHumanReadable(0.0))->toBe('0');
    expect(AdminUiHelper::numberFormatHumanReadable(42))->toBe('42');
    expect(AdminUiHelper::numberFormatHumanReadable(999))->toBe('999');
});

test('numberFormatHumanReadable formats thousands with a "k" suffix and 1 decimal', function (): void {
    expect(AdminUiHelper::numberFormatHumanReadable(1000))->toBe('1.0k');
    expect(AdminUiHelper::numberFormatHumanReadable(1500))->toBe('1.5k');
    expect(AdminUiHelper::numberFormatHumanReadable(999999))->toBe('1,000.0k');
});

test('numberFormatHumanReadable formats millions with an "M" suffix and 1 decimal', function (): void {
    expect(AdminUiHelper::numberFormatHumanReadable(1000000))->toBe('1.0M');
    expect(AdminUiHelper::numberFormatHumanReadable(2500000))->toBe('2.5M');
});

test('numberFormatHumanReadable caps at "M" instead of overflowing past the known suffix list', function (): void {
    // $readable only has 3 entries (index 0..2 = '', 'k', 'M') -- a value
    // large enough to need a 4th tier (billions) crosses the 1000
    // threshold a 3rd time (index reaches 3), hits the `$index >
    // count($readable) - 1` guard, and steps back to index 2 ('M') --
    // note this means the already-divided value (1.0, not 1000.0) is
    // what gets displayed, a real, observed lossy-display quirk for
    // billion-scale input, not a hypothetical.
    expect(AdminUiHelper::numberFormatHumanReadable(1_000_000_000))->toBe('1.0M');
});