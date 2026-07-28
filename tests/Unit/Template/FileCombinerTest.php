<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Template\Combinable;
use Piwigo\Template\FileCombiner;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;

// combine()'s multi-item merge path (mkgetdir()+file_put_contents() under
// PHPWG_ROOT_PATH) and its is_template path (needs a real Smarty $template
// global) are Smarty-rendering/file-I/O integration, already covered
// indirectly by the Browser suite (see docs/PLAN.md's P16 audit
// note) -- not re-tested here. What's under test: combine()'s behavior for
// 0-1 non-template items and remote items, which never touch the
// filesystem (confirmed by reading flush_pending()/process_combinable()'s
// own branches).
//
// combine() now calls Piwigo\Auth\AccessControl::isAdmin() directly (P23
// batch 8d), which reads Piwigo\Users\CurrentUser (Legacy Coupling
// Retirement Track A batch A3) -- so no stub is needed beyond seeding a
// guest CurrentUser below, matching this test's old $GLOBALS['user'] stub.

// url_is_remote() -- always available now via composer autoload.files
// (src/Piwigo/Url/functions.php, P23 batch 8c), no explicit require needed.

beforeEach(function (): void {
    CurrentConfig::setTemplateCompileCheck(false);
    CurrentConfig::setTemplateCombineFiles(false);
    CurrentUser::attachGlobals();
});

afterEach(function (): void {
    CurrentUser::reset();
    CurrentConfig::reset();
});

test('combine returns an empty array for no combinables', function (): void {
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), []);

    expect($combiner->combine())->toBe([]);
});

test('combine returns a single non-template combinable unchanged', function (): void {
    $combinable = new Combinable('my-script', 'themes/default/js/foo.js');
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), [$combinable]);

    $result = $combiner->combine();

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBe($combinable);
});

test('combine passes remote combinables through without combining them', function (): void {
    $remote = new Combinable('remote-script', 'https://cdn.example.com/foo.js');
    $local = new Combinable('local-script', 'themes/default/js/bar.js');
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), [$remote, $local]);

    $result = $combiner->combine();

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe($remote)
        ->and($result[1])->toBe($local);
});

test('add appends a single combinable', function (): void {
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), []);
    $combinable = new Combinable('my-script', 'themes/default/js/foo.js');

    $combiner->add($combinable);

    expect($combiner->combine())->toBe([$combinable]);
});

test('add merges an array of combinables', function (): void {
    $combiner = new FileCombiner('js', new UrlService(new HtmlService()), Paths::fromRoot('/tmp/piwigo-file-combiner-test'), []);
    $first = new Combinable('first', 'themes/default/js/a.js');
    $second = new Combinable('second', 'themes/default/js/b.js');

    $combiner->add([$first, $second]);

    // template_combine_files is false, so each flushes as its own
    // single-item batch and is returned unchanged, in order.
    expect($combiner->combine())->toBe([$first, $second]);
});

test('clear_combined_files deletes only .js and .css files from the combined dir', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-clear-' . bin2hex(random_bytes(8));
    mkdir($root . '/_data/combined', 0o777, true);
    file_put_contents($root . '/_data/combined/a.js', 'x');
    file_put_contents($root . '/_data/combined/b.css', 'x');
    file_put_contents($root . '/_data/combined/c.txt', 'x');
    \Piwigo\Core\CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('_data/');

    try {
        FileCombiner::clear_combined_files();

        expect(file_exists($root . '/_data/combined/a.js'))->toBeFalse();
        expect(file_exists($root . '/_data/combined/b.css'))->toBeFalse();
        expect(file_exists($root . '/_data/combined/c.txt'))->toBeTrue();
    } finally {
        unlink($root . '/_data/combined/c.txt');
        rmdir($root . '/_data/combined');
        rmdir($root . '/_data');
        rmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
    }
});

test('clear_combined_files returns without error when the combined dir does not exist', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-file-combiner-noclear-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('_data/');

    set_error_handler(static fn (): bool => true);
    try {
        FileCombiner::clear_combined_files();
        $ranToCompletion = true;
    } finally {
        restore_error_handler();
        \Piwigo\Core\CurrentPaths::reset();
    }

    expect($ranToCompletion)->toBeTrue();
});
