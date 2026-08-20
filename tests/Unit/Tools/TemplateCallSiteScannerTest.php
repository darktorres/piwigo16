<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\TabsheetPageContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Tools\PhpStan\Latte\TemplateCallSiteScanner;

/**
 * Runs against the real repository tree, not fixtures, wherever a real
 * call site of the shape under test still exists -- the scanner's
 * whole job is resolving real call sites against the real template
 * tree, and the assertions below pin the exact namespace-scoping
 * behavior the retired PiwigoLatteEngineResolver established: Admin
 * call sites resolve only under themes/admin/, Mail only under
 * themes/default/template/mail/, frontend call sites resolve every
 * genuinely-reachable theme variant. The Admin-scoping test is the one
 * exception, and is synthetic (matching the "widens to the full tree"
 * test's own established fixture shape below): P40's admin `ADMIN_CONTENT`
 * sweep (Batches 3/5/7/8) converted every real `Piwigo\Admin\*`
 * `assignVarFromTemplate()`/`->parse()` call site to `Renderer::render()`,
 * which this scanner doesn't recognize at all (by design -- see
 * TemplateTypeScanner) -- there is no longer a real admin-scoped
 * fixture left in the repository to test the namespace-scoping rule
 * against. The rule itself still matters for whichever future admin
 * class might reintroduce a real `parse()`/`assignVarFromTemplate()`
 * call site, so it stays covered here, just synthetically now.
 */
beforeEach(function (): void {
    $this->root = dirname(__DIR__, 3);
    $this->result = new TemplateCallSiteScanner($this->root)
        ->scan();
});

it('resolves an Admin renderer call site only under the admin theme', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scanner-admin-scope-' . bin2hex(random_bytes(8));
    mkdir($root . '/src/Piwigo/Admin', 0o777, true);
    mkdir($root . '/themes/admin/default/template', 0o777, true);
    mkdir($root . '/themes/default/template', 0o777, true);
    file_put_contents($root . '/themes/admin/default/template/only_admin.latte', '{$x}');
    file_put_contents($root . '/src/Piwigo/Admin/SomeAdminRenderer.php', <<<'PHP'
        <?php
        namespace Piwigo\Admin;
        class SomeAdminRenderer {
            public function render($template): void {
                $template->parse('only_admin.latte');
            }
        }
        PHP);

    try {
        $result = new TemplateCallSiteScanner($root)
            ->scan();

        $templates = $result->templatesByClass['Piwigo\\Admin\\SomeAdminRenderer'] ?? [];

        expect($templates)
            ->toContain(realpath($root . '/themes/admin/default/template/only_admin.latte'));
        foreach ($templates as $path) {
            expect($path)->toContain('/themes/admin/default/template/');
        }
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
});

it('resolves a Mail call site only under the mail template dir', function (): void {
    $templates = $this->result->templatesByClass[NotificationByMailSender::class] ?? [];

    expect($templates)
        ->not->toBe([]);
    foreach ($templates as $path) {
        expect($path)->toContain('/themes/default/template/mail/');
    }
});

it('resolves a frontend polymorphic call site to every reachable theme variant', function (): void {
    $templates = $this->result->templatesByClass[PageHeaderRenderer::class] ?? [];

    expect($templates)
        ->toContain($this->root . '/themes/default/template/header.latte')
        ->toContain($this->root . '/themes/standard_pages/template/header.latte');
});

it('associates assignContext call sites with their context class', function (): void {
    expect($this->result->contextsByClass[Tabsheet::class] ?? [])
        ->toContain(TabsheetPageContext::class);
});

it('records the cross-class context assigners the same-class association cannot cover', function (): void {
    // MenubarRenderer assigns contexts but renders nothing itself -- one
    // of the 25 confirmed cross-class files that motivate the fallback
    // union in VariableMapBuilder.
    expect($this->result->contextsByClass)
        ->toHaveKey(MenubarRenderer::class);
    expect($this->result->templatesByClass)
        ->not->toHaveKey(MenubarRenderer::class);
});

it('resolves every literal call site to at least one real file', function (): void {
    $unresolvable = array_filter(
        $this->result->notices,
        static fn (string $n): bool => str_starts_with($n, 'unresolvable template'),
    );

    expect($unresolvable)
        ->toBe([]);
});

it('widens to the full tree when the namespace scope has no match, instead of resolving to nothing', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-scanner-fallback-' . bin2hex(random_bytes(8));
    mkdir($root . '/src/Piwigo/Admin', 0o777, true);
    mkdir($root . '/themes/default/template', 0o777, true);
    file_put_contents($root . '/themes/default/template/only_frontend.latte', '{$x}');
    file_put_contents($root . '/src/Piwigo/Admin/SomeRenderer.php', <<<'PHP'
        <?php
        namespace Piwigo\Admin;
        class SomeRenderer {
            public function render($template): void {
                $template->parse('only_frontend.latte');
            }
        }
        PHP);

    try {
        $result = new TemplateCallSiteScanner($root)
            ->scan();

        expect($result->templatesByClass['Piwigo\\Admin\\SomeRenderer'] ?? [])
            ->toContain(realpath($root . '/themes/default/template/only_frontend.latte'));
        expect(array_filter(
            $result->notices,
            static fn (string $n): bool => str_starts_with($n, 'fallback-widened'),
        ))->not->toBe([]);
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
});
