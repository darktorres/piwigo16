<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\StatsPageContext;
use Piwigo\Admin\Projection\TabsheetPageContext;
use Piwigo\Calendar\Projection\CalendarChronologyPageContext;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\Projection\MenubarBlocksPageContext;
use Piwigo\Template\TemplateAdapter;
use Piwigo\Tools\PhpStan\Latte\ContextVariableExtractor;
use Piwigo\Tools\PhpStan\Latte\VariableMapBuilder;

beforeEach(function (): void {
    $this->extractor = new ContextVariableExtractor();
});

it('maps StatsPageContext with docblock array types, VO ->value narrowing and native strings', function (): void {
    $extracted = $this->extractor->extract(StatsPageContext::class);

    expect($extracted->vars['U_HELP'])->toBe('string')
        ->and($extracted->vars['lastYears'])->toBe('float[]|int[]')
        ->and($extracted->vars['langCode'])->toBe('string')
        ->and($extracted->vars['monthStats'])->toContain('month?:')
        ->and($extracted->notices)
        ->toBe([]);
});

it('FQCN-expands use-imported classes in docblock types', function (): void {
    $extracted = $this->extractor->extract(MenubarBlocksPageContext::class);

    $withDisplayBlock = array_filter(
        $extracted->vars,
        static fn (string $type): bool => str_contains($type, DisplayBlock::class),
    );
    expect($withDisplayBlock)
        ->not->toBe([]);
    expect(implode(' ', $extracted->vars))
        ->not->toContain(' DisplayBlock');
});

it('collects literal keys and notices dynamic ones from variable-built toArray bodies', function (): void {
    $extracted = $this->extractor->extract(TabsheetPageContext::class);

    expect($extracted->vars)
        ->toHaveKey('tabsheet')
        ->toHaveKey('tabsheet_selected');
    expect(array_filter(
        $extracted->notices,
        static fn (string $n): bool => str_contains($n, 'dynamic array-dim assignment'),
    ))->not->toBe([]);
});

it('extracts every one of the 130 real context classes without a hard failure', function (): void {
    $root = dirname(__DIR__, 3);
    exec('grep -rl "implements TemplatePageContext" ' . escapeshellarg($root . '/src/Piwigo') . ' --include="*.php"', $files);
    expect(count($files))
        ->toBeGreaterThan(100);

    foreach ($files as $file) {
        $class = null;
        $source = (string) file_get_contents($file);
        if (preg_match('/namespace ([^;]+);.*(?:final readonly class|final class|class) (\w+)/s', $source, $m) === 1) {
            $class = $m[1] . '\\' . $m[2];
        }
        expect($class)
            ->not->toBeNull();
        assert($class !== null);
        $extracted = $this->extractor->extract($class);
        expect($extracted->vars !== [] || $extracted->notices !== [])->toBeTrue();
    }
});

it('declares the framework globals Template.php itself assigns', function (): void {
    $globals = $this->extractor->frameworkGlobals();

    expect($globals['ROOT_URL'])->toBe('string')
        // Leading backslash required -- this type string is spliced
        // directly into a generated `@var` docblock, see
        // frameworkGlobals()'s own comment. Written as a concatenation,
        // not a bare string literal, so this assertion itself can't be
        // silently flipped back to the wrong value by a future Rector
        // run the way it was here (real incident, 2026-08-14).
        ->and($globals['pwg'])->toBe('\\' . TemplateAdapter::class)
        ->and($globals)
        ->not->toHaveKey('theme_template_vars');
});

it('builds per-template maps with same-class association and a cross-class fallback union', function (): void {
    $map = new VariableMapBuilder(
        templatesByClass: [
            'App\\ARenderer' => ['/t/a.latte'],
        ],
        contextsByClass: [
            'App\\ARenderer' => ['App\\ACtx'],
            'App\\CrossClassAssigner' => ['App\\SharedCtx'],
        ],
        varsByContext: [
            'App\\ACtx' => [
                'title' => 'string',
            ],
            'App\\SharedCtx' => [
                'messages' => 'list<string>',
            ],
        ],
        extractor: $this->extractor,
    )->build();

    expect($map->byTemplate['/t/a.latte'])->toBe([
        'title' => 'string',
    ]);
    // The fallback union covers every context (assigns accumulate on the
    // request's shared Template instance), while fallbackContexts names
    // only the render-less classes' contexts -- the ones with no specific
    // association at all.
    expect($map->fallback)
        ->toBe([
            'messages' => 'list<string>',
            'title' => 'string',
        ]);
    expect($map->fallbackContexts)
        ->toBe(['App\\SharedCtx']);
});

it('unions conflicting types deterministically and never overrides specific vars with fallback ones', function (): void {
    $map = new VariableMapBuilder(
        templatesByClass: [
            'App\\A' => ['/t/shared.latte'],
            'App\\B' => ['/t/shared.latte'],
        ],
        contextsByClass: [
            'App\\A' => ['App\\ACtx'],
            'App\\B' => ['App\\BCtx'],
            'App\\Orphan' => ['App\\OrphanCtx'],
        ],
        varsByContext: [
            'App\\ACtx' => [
                'val' => 'string',
            ],
            'App\\BCtx' => [
                'val' => 'int',
            ],
            'App\\OrphanCtx' => [
                'val' => 'float',
            ],
        ],
        extractor: $this->extractor,
    )->build();

    expect($map->byTemplate['/t/shared.latte']['val'])->toBe('int|string');

    $globals = new ContextVariableExtractor()
        ->frameworkGlobals();
    $forTemplate = $map->forTemplate('/t/shared.latte', $globals);
    expect($forTemplate['val'])->toBe('int|string');
    expect($forTemplate['ROOT_URL'])->toBe('string');

    $unknown = $map->forTemplate('/t/unknown.latte', $globals);
    expect($unknown['val'])->toBe('float|int|string');
});

it('extracts conditional dim-assigned variables with their declared types', function (): void {
    $extracted = new ContextVariableExtractor()
        ->extract(CalendarChronologyPageContext::class);

    expect($extracted->vars)
        ->toHaveKey('chronology_views');
});

it('enumerates theme_template_vars keys across real themeconf files with reflected config types', function (): void {
    $result = new ContextVariableExtractor()
        ->themeTemplateVars(dirname(__DIR__, 3));

    expect($result['vars']['GALLERY_TITLE'])->toBe('string')
        ->and($result['vars'])->toHaveKey('STD_PGS_SELECTED_SKIN');
});
