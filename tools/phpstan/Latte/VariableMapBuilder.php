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
final class VariableMapBuilder
{
    /**
     * @param array<string, list<string>> $templatesByClass class FQCN => template realpaths
     * @param array<string, list<string>> $contextsByClass class FQCN => context FQCNs
     * @param array<string, array<string, string>> $varsByContext context FQCN => {var => type}
     */
    public function __construct(
        private readonly array $templatesByClass,
        private readonly array $contextsByClass,
        private readonly array $varsByContext,
    ) {}

    public function build(): VariableMap
    {
        $fallbackSets = [];
        $fallbackContexts = [];
        foreach ($this->contextsByClass as $class => $contexts) {
            if (isset($this->templatesByClass[$class])) {
                continue;
            }
            foreach ($contexts as $context) {
                $fallbackContexts[] = $context;
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
