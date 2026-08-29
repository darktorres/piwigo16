<?php

declare(strict_types=1);

use Piwigo\Calendar\Projection\CalendarChronologyCalendar;
use Piwigo\Calendar\Projection\CalendarChronologyPageContext;
use Piwigo\Calendar\Projection\CalendarMonthlyCalendarPageContext;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Template\TemplateAdapter;
use Piwigo\Tests\Unit\Tools\ContextVariableExtractorTestArrayLiteralFixture;
use Piwigo\Tests\Unit\Tools\ContextVariableExtractorTestDisplayBlocksFixture;
use Piwigo\Tests\Unit\Tools\ContextVariableExtractorTestDynamicDimFixture;
use Piwigo\Tests\Unit\Tools\ContextVariableExtractorTestNestedArrayShapeFixture;
use Piwigo\Tools\PhpStan\Latte\ContextVariableExtractor;
use Piwigo\Tools\PhpStan\Latte\VariableMapBuilder;

beforeEach(function (): void {
    $this->extractor = new ContextVariableExtractor();
});

it('maps a nested array-shape docblock', function (): void {
    $extracted = $this->extractor->extract(ContextVariableExtractorTestNestedArrayShapeFixture::class);

    expect($extracted->vars['cats_navbar'])->toContain('pages?:')
        ->and($extracted->notices)
        ->toBe([]);
});

it('types a toArray value built as an array literal by its real shape, not its first property', function (): void {
    $extracted = $this->extractor->extract(ContextVariableExtractorTestArrayLiteralFixture::class);

    // Without the array-literal branch this came back as `string` -- the
    // type of whichever property the literal mentions first -- so a
    // template reading `$wrapped['TITLE']` was an offset access on a
    // string, and reading `$wrapped['COUNT']` did not exist at all.
    expect($extracted->vars['wrapped'])
        ->toBe('array{TITLE: string, COUNT: int}');
});

it('types a keyless literal as a list of its value types', function (): void {
    $extracted = $this->extractor->extract(ContextVariableExtractorTestArrayLiteralFixture::class);

    // `'footer_elements' => [$this->searchDebug]` is the real shape this
    // covers: without it the layouts took `footer_elements` as `string`
    // and both foreach'd over it.
    expect($extracted->vars['listed'])
        ->toBe('list<string|int>')
        ->and($extracted->vars['listed_same'])
        ->toBe('list<string>');
});

it('falls back to the approximation for a literal it cannot describe as a shape', function (): void {
    $extracted = $this->extractor->extract(ContextVariableExtractorTestArrayLiteralFixture::class);

    // An int key and a spread are both outside `array{...}`'s string-keyed
    // form, so each keeps the old first-property-reference behaviour and
    // says so in a notice rather than emitting a shape it cannot justify.
    expect($extracted->vars['int_keyed'])
        ->toBe('string')
        ->and($extracted->vars['spread_built'])
        ->toBe('list<string>')
        ->and(implode(' ', $extracted->notices))
        ->toContain("'int_keyed'")
        ->toContain("'spread_built'")
        ->toContain("'mixed_keys'");
    // A literal mixing a keyed and a keyless item is neither shape.
    expect($extracted->vars['mixed_keys'])
        ->toBe('string');
});

it('FQCN-expands use-imported classes in docblock types', function (): void {
    $extracted = $this->extractor->extract(ContextVariableExtractorTestDisplayBlocksFixture::class);

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
    $extracted = $this->extractor->extract(ContextVariableExtractorTestDynamicDimFixture::class);

    expect($extracted->vars)
        ->toHaveKey('literal_one')
        ->toHaveKey('literal_two');
    expect(array_filter(
        $extracted->notices,
        static fn (string $n): bool => str_contains($n, 'dynamic array-dim assignment'),
    ))->not->toBe([]);
});

it('extracts every one of the remaining real context classes without a hard failure', function (): void {
    $root = dirname(__DIR__, 3);
    exec('grep -rl "implements TemplatePageContext" ' . escapeshellarg($root . '/src/Piwigo') . ' --include="*.php"', $files);
    // Was >100 (130 total) before the P40 campaign started converting
    // context classes to typed Views; down to exactly 30 after the Mail
    // batch (4 more deleted: NbmMailContentPageContext,
    // NbmSubscribeActionMailContext, NbmNewsMailContext,
    // MailRuntimeTemplatePageContext). Shrinks further with every landed
    // batch, so this stays a loose "grep still found a real pool" floor,
    // not a precise pin.
    expect(count($files))
        ->toBeGreaterThan(20);

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

it('types a new-expression value as that class, not its first argument', function (): void {
    $extracted = $this->extractor->extract(CalendarMonthlyCalendarPageContext::class);

    // `'chronology_calendar' => new CalendarChronologyCalendar(calendarBars:
    // ..., monthView: ...)`. Before this was handled the extractor fell
    // through to its first-property-reference heuristic and declared the
    // variable as $calendarBars's own type, and emitted a notice saying so.
    expect($extracted->vars['chronology_calendar'])
        ->toBe('\\' . CalendarChronologyCalendar::class);
    expect(array_filter(
        $extracted->notices,
        static fn (string $n): bool => str_contains($n, 'chronology_calendar'),
    ))->toBe([]);
});

/**
 * `?T` and `T|null` denote the same type but not the same string, so a
 * variable one context declares `?string` and another declares `string`
 * used to join into `?string|string`. That is not valid PHPDoc at all --
 * the `?` shorthand cannot take part in a union -- so PHPStan discarded
 * the annotation and every read of the variable reported as `mixed`.
 * $ADMIN_PAGE_TITLE is the case that surfaced it: four contexts assign it,
 * three as `string` and AdminContentPageContext as `?string`.
 */
it('folds a nullable and a non-nullable declaration into one valid union', function (): void {
    $map = new VariableMapBuilder(
        templatesByClass: [
            'App\\A' => ['/t/shared.latte'],
            'App\\B' => ['/t/shared.latte'],
        ],
        contextsByClass: [
            'App\\A' => ['App\\ACtx'],
            'App\\B' => ['App\\BCtx'],
        ],
        varsByContext: [
            'App\\ACtx' => [
                'title' => '?string',
            ],
            'App\\BCtx' => [
                'title' => 'string',
            ],
        ],
        extractor: $this->extractor,
    )->build();

    expect($map->byTemplate['/t/shared.latte']['title'])->toBe('string|null');
});

it('absorbs the union into mixed rather than listing types mixed already covers', function (): void {
    $map = new VariableMapBuilder(
        templatesByClass: [
            'App\\A' => ['/t/shared.latte'],
            'App\\B' => ['/t/shared.latte'],
        ],
        contextsByClass: [
            'App\\A' => ['App\\ACtx'],
            'App\\B' => ['App\\BCtx'],
        ],
        varsByContext: [
            'App\\ACtx' => [
                'val' => '?string',
            ],
            'App\\BCtx' => [
                'val' => 'mixed',
            ],
        ],
        extractor: $this->extractor,
    )->build();

    expect($map->byTemplate['/t/shared.latte']['val'])->toBe('mixed');
});

/**
 * The `|` inside a generic is not a union separator at this level; splitting
 * on it blindly would emit `array<string, string` and `null>`.
 */
it('leaves a pipe nested inside a generic alone', function (): void {
    $map = new VariableMapBuilder(
        templatesByClass: [
            'App\\A' => ['/t/shared.latte'],
            'App\\B' => ['/t/shared.latte'],
        ],
        contextsByClass: [
            'App\\A' => ['App\\ACtx'],
            'App\\B' => ['App\\BCtx'],
        ],
        varsByContext: [
            'App\\ACtx' => [
                'rows' => '?array<string, string|null>',
            ],
            'App\\BCtx' => [
                'rows' => 'array<string, string|null>',
            ],
        ],
        extractor: $this->extractor,
    )->build();

    expect($map->byTemplate['/t/shared.latte']['rows'])->toBe('array<string, string|null>|null');
});
