<?php

declare(strict_types=1);

use Piwigo\Template\Combinable;
use Piwigo\Template\FileCombiner;

// combine()'s multi-item merge path (mkgetdir()+file_put_contents() under
// PHPWG_ROOT_PATH) and its is_template path (needs a real Smarty $template
// global) are Smarty-rendering/file-I/O integration, already covered
// indirectly by the Browser suite (see docs/PLAN-REPLAY.md's P16 audit
// note) -- not re-tested here. What's under test: combine()'s behavior for
// 0-1 non-template items and remote items, which never touch the
// filesystem (confirmed by reading flush_pending()/process_combinable()'s
// own branches).
//
// is_admin() (called unconditionally inside combine()) is a free function
// (include/functions_user.inc.php) -- not autoloaded like a class. A
// minimal guarded stub, not a require_once of the real file, to avoid
// pulling in the whole legacy bootstrap chain. Same "minimal stubs for
// not-yet-migrated free functions" pattern as HtmlServiceTest.php/
// PasswordHashTest.php.
if (! function_exists('is_admin')) {
    function is_admin(string $user_status = ''): bool
    {
        return false;
    }
}

if (! function_exists('url_is_remote')) {
    require_once dirname(__DIR__, 3) . '/include/functions_url.inc.php';
}

beforeEach(function (): void {
    $GLOBALS['conf'] = [
        'template_compile_check' => false,
        'template_combine_files' => false,
        'guest_access' => true,
    ];
    $GLOBALS['user'] = ['status' => 'guest'];
});

test('combine returns an empty array for no combinables', function (): void {
    $combiner = new FileCombiner('js', []);

    expect($combiner->combine())->toBe([]);
});

test('combine returns a single non-template combinable unchanged', function (): void {
    $combinable = new Combinable('my-script', 'themes/default/js/foo.js');
    $combiner = new FileCombiner('js', [$combinable]);

    $result = $combiner->combine();

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBe($combinable);
});

test('combine passes remote combinables through without combining them', function (): void {
    $remote = new Combinable('remote-script', 'https://cdn.example.com/foo.js');
    $local = new Combinable('local-script', 'themes/default/js/bar.js');
    $combiner = new FileCombiner('js', [$remote, $local]);

    $result = $combiner->combine();

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe($remote)
        ->and($result[1])->toBe($local);
});

test('add appends a single combinable', function (): void {
    $combiner = new FileCombiner('js', []);
    $combinable = new Combinable('my-script', 'themes/default/js/foo.js');

    $combiner->add($combinable);

    expect($combiner->combine())->toBe([$combinable]);
});

test('add merges an array of combinables', function (): void {
    $combiner = new FileCombiner('js', []);
    $first = new Combinable('first', 'themes/default/js/a.js');
    $second = new Combinable('second', 'themes/default/js/b.js');

    $combiner->add([$first, $second]);

    // template_combine_files is false, so each flushes as its own
    // single-item batch and is returned unchanged, in order.
    expect($combiner->combine())->toBe([$first, $second]);
});
