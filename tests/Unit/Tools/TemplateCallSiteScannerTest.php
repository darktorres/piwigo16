<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\Projection\CheckIntegrityPageContext;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Tools\PhpStan\Latte\TemplateCallSiteScanner;

/**
 * Runs against the real repository tree, not fixtures -- the scanner's
 * whole job is resolving real call sites against the real template
 * tree, and the assertions below pin the exact namespace-scoping
 * behavior the retired PiwigoLatteEngineResolver established: Admin
 * call sites resolve only under themes/admin/, Mail only under
 * themes/default/template/mail/, frontend call sites resolve every
 * genuinely-reachable theme variant.
 */
beforeEach(function (): void {
    $this->root = dirname(__DIR__, 3);
    $this->result = new TemplateCallSiteScanner($this->root)
        ->scan();
});

it('resolves an Admin renderer call site only under the admin theme', function (): void {
    $templates = $this->result->templatesByClass[CheckIntegrity::class] ?? [];

    expect($templates)
        ->toContain($this->root . '/themes/admin/default/template/check_integrity.latte');
    foreach ($templates as $path) {
        expect($path)->toContain('/themes/admin/default/template/');
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
    expect($this->result->contextsByClass[CheckIntegrity::class] ?? [])
        ->toContain(CheckIntegrityPageContext::class);
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
