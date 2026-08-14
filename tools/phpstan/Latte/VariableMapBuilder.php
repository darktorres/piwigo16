<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

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
 */
final readonly class VariableMapBuilder
{
    /**
     * @param array<string, list<string>> $templatesByClass class FQCN => template realpaths
     * @param array<string, list<string>> $contextsByClass class FQCN => context FQCNs
     * @param array<string, array<string, string>> $varsByContext context FQCN => {var => type}
     */
    public function __construct(
        private array $templatesByClass,
        private array $contextsByClass,
        private array $varsByContext,
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

        return new VariableMap(
            array_map(self::joinSets(...), $setsByTemplate),
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
