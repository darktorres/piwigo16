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
// combine() now calls Piwigo\Auth\AccessControl::isAdmin() directly (P23
// batch 8d) -- a pure `global $user;` read, zero DB dependency -- so no
// stub is needed; beforeEach()'s $GLOBALS['user']['status'] = 'guest'
// below already makes it return false, matching this test's old stub.

// url_is_remote() -- always available now via composer autoload.files
// (src/Piwigo/Url/functions.php, P23 batch 8c), no explicit require needed.

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
