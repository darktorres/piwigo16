<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * P29.6 (modus port) Phase 0 spike, kept as a permanent regression test
 * since the mechanism it proves is now load-bearing infrastructure every
 * future themed override (modus, then elegant/smartpocket) depends on.
 *
 * Proves two facts about Latte's own `{layout}` tag (verified by direct
 * source read of `vendor/latte/latte`'s `TagParser`/`ExtendsNode`/
 * `FileLoader` before this test was written, see docs/theme-porting --
 * this test is the live confirmation, not the first evidence):
 *
 * 1. `{layout $ROOT_PATH . 'literal.latte'}` accepts a dynamic PHP
 *    expression, not only a bare quoted string literal, and an absolute
 *    path resolves independent of the referring file's own directory --
 *    letting a child theme's template extend a *different* theme's
 *    template living in a different directory.
 * 2. A bare `{include '...'}` written inside a block a child template
 *    supplies still resolves relative to the file that textually owns
 *    that block's source (the child's own directory), not the parent
 *    layout's directory -- confirming per-layer relative-include
 *    resolution behaves as expected once mixed with cross-directory
 *    `{layout}`.
 */
function latte_block_inheritance_test_rrmdir(string $dir): void
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
        is_dir($path) ? latte_block_inheritance_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-latte-block-inheritance-test-' . bin2hex(random_bytes(8));
    $this->root = $root;
    mkdir($root . '/themes/default/template', 0o777, true);
    mkdir($root . '/themes/modus/template', 0o777, true);
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentUserTestFactory::get()->attachGlobals();
});

afterEach(function (): void {
    latte_block_inheritance_test_rrmdir($this->root);
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('{layout $ROOT_PATH . \'...\'} extends a template in a different directory via a dynamic expression, and a nested {include} inside the overriding block resolves relative to the child theme\'s own directory', function (): void {
    file_put_contents(
        $this->root . '/themes/default/template/layout.latte',
        'BEFORE{block content}default-content{/block}AFTER',
    );
    file_put_contents(
        $this->root . '/themes/modus/template/sibling.latte',
        'SIBLING-CONTENT',
    );
    file_put_contents(
        $this->root . '/themes/modus/template/child.latte',
        <<<'LATTE'
        {layout $ROOT_PATH . 'themes/default/template/layout.latte'}
        {block content}{include 'sibling.latte'}{/block}
        LATTE
        ,
    );

    $t = TemplateTestFactory::build();
    $t->setTemplateDir($this->root . '/themes/modus/template');

    $output = $t->parse('child.latte');

    expect($output)
        ->toContain('BEFORE')
        ->toContain('SIBLING-CONTENT')
        ->toContain('AFTER')
        ->not->toContain('default-content');

    latte_block_inheritance_test_rrmdir($this->root);
});

test('confirms the negative: a bare {layout \'layout.latte\'} self-references instead of reaching another directory\'s file', function (): void {
    // Ground truth for why the absolute-path form above is required, not
    // a stylistic choice -- FileLoader::getReferredName() resolves a bare
    // filename relative to the referring file's own directory, so this
    // naive child.latte "reaching for" default's layout.latte via a bare
    // name actually reaches for a file of the same name in its OWN
    // directory instead.
    file_put_contents(
        $this->root . '/themes/default/template/layout.latte',
        'SHOULD-NOT-BE-REACHED{block content}{/block}',
    );
    file_put_contents(
        $this->root . '/themes/modus/template/layout.latte',
        'SELF-REFERENCED{block content}{/block}',
    );
    file_put_contents(
        $this->root . '/themes/modus/template/child.latte',
        <<<'LATTE'
        {layout 'layout.latte'}
        {block content}child-content{/block}
        LATTE
        ,
    );

    $t = TemplateTestFactory::build();
    $t->setTemplateDir($this->root . '/themes/modus/template');

    $output = $t->parse('child.latte');

    expect($output)
        ->toContain('SELF-REFERENCED')
        ->not->toContain('SHOULD-NOT-BE-REACHED');

    latte_block_inheritance_test_rrmdir($this->root);
});
