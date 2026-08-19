<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use ReflectionClass;

/**
 * Joins the scanner's class->template / class->context maps with the
 * extractor's per-context variable maps into per-template `@var` data.
 *
 * Association (adversarially checked against the real tree, not
 * assumed): a context assigned in the same class as a render call
 * attaches to that class's own rendered templates -- the dominant
 * pattern (StatsPageRenderer etc.). The 25 confirmed real classes that
 * assign a context but render nothing themselves (MenubarRenderer,
 * CalendarRenderer, several *SubControllers...) contribute to a global
 * fallback union applied to every template instead. Union-widening is
 * sound in one direction only: a colliding name may widen a type
 * (under-flagging), never falsely flag. Per-template accuracy for the
 * cross-class 25 would need real call-graph tracing -- explicitly not
 * v1; `fallbackContexts` keeps the tradeoff visible in the compile
 * Command's output instead of silent.
 *
 * A template declaring `{templateType FooView}` (`$templateTypesByTemplate`)
 * gets its `byTemplate` entry populated directly from `FooView`'s own
 * reflected public properties instead -- those properties win over
 * whatever the classic association above would have produced for the
 * same path (there normally is none, once the migrated page's own
 * controller stops calling `assignContext()`). The fallback union and
 * `VariableMap::forTemplate()`'s own `$globals` merge still apply
 * afterwards, filling in only names the `View` doesn't itself declare --
 * true cross-cutting ambient values (`ROOT_URL`, a sibling renderer's own
 * ambient assign like `MENUBAR`/`CATEGORIES`, ...) reach a `{templateType}`
 * template exactly the way they reach every other template, with no new
 * hardcoded list to keep in sync.
 */
final readonly class VariableMapBuilder
{
    /**
     * @param array<string, list<string>> $templatesByClass class FQCN => template realpaths
     * @param array<string, list<string>> $contextsByClass class FQCN => context FQCNs
     * @param array<string, array<string, string>> $varsByContext context FQCN => {var => type}
     * @param array<string, string> $templateTypesByTemplate template realpath => View class FQCN
     */
    public function __construct(
        private array $templatesByClass,
        private array $contextsByClass,
        private array $varsByContext,
        private ContextVariableExtractor $extractor,
        private array $templateTypesByTemplate = [],
    ) {}

    public function build(): VariableMap
    {
        // The fallback union covers EVERY context, not only the render-less
        // classes': all assigns accumulate on the request's shared Template
        // instance, so a context assigned by one renderer is genuinely
        // visible to any template rendered later in the same request
        // (confirmed live: header.latte's U_HOME comes from
        // PageHeaderPageContext but is read by templates other classes
        // render). Per-template types stay first -- forTemplate() merges
        // specific-before-fallback -- so this only widens, never overrides.
        // fallbackContexts still names only the render-less classes'
        // contexts: those have no specific association at all, which is
        // the visibility-worthy tradeoff.
        $fallbackSets = [];
        $fallbackContexts = [];
        foreach ($this->contextsByClass as $class => $contexts) {
            foreach ($contexts as $context) {
                if (! isset($this->templatesByClass[$class]) && ! in_array($context, $fallbackContexts, true)) {
                    $fallbackContexts[] = $context;
                }
                self::mergeInto($fallbackSets, $this->varsByContext[$context] ?? []);
            }
        }
        sort($fallbackContexts);

        $setsByTemplate = [];
        foreach ($this->templatesByClass as $class => $templates) {
            $classSets = [];
            foreach ($this->contextsByClass[$class] ?? [] as $context) {
                self::mergeInto($classSets, $this->varsByContext[$context] ?? []);
            }
            foreach ($templates as $template) {
                $setsByTemplate[$template] ??= [];
                foreach ($classSets as $name => $types) {
                    $setsByTemplate[$template][$name] = [...$setsByTemplate[$template][$name] ?? [], ...$types];
                }
            }
        }
        ksort($setsByTemplate);

        $byTemplate = array_map(self::joinSets(...), $setsByTemplate);

        foreach ($this->templateTypesByTemplate as $path => $viewClass) {
            if (! class_exists($viewClass)) {
                continue;
            }
            $discardedNotices = [];
            $byTemplate[$path] = $this->extractor->propertyTypes(new ReflectionClass($viewClass), $discardedNotices);
        }
        ksort($byTemplate);

        return new VariableMap(
            $byTemplate,
            self::joinSets($fallbackSets),
            $fallbackContexts,
        );
    }

    /**
     * @param array<string, array<string, true>> $sets var name => set of exact type strings
     * @param array<string, string> $vars
     */
    private static function mergeInto(array &$sets, array $vars): void
    {
        foreach ($vars as $name => $type) {
            $sets[$name][$type] = true;
        }
    }

    /**
     * A variable seen with multiple distinct types becomes their union
     * -- deterministic (sorted, exact-string set), widening only.
     *
     * @param array<string, array<string, true>> $sets
     * @return array<string, string>
     */
    private static function joinSets(array $sets): array
    {
        $joined = [];
        foreach ($sets as $name => $types) {
            $parts = array_keys($types);
            sort($parts);
            $joined[$name] = implode('|', $parts);
        }
        ksort($joined);

        return $joined;
    }
}
