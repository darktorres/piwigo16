<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tools;

use Piwigo\Core\TemplatePageContext;

/**
 * Throwaway fixture, not a real production class -- P40 converted
 * every real TemplatePageContext whose toArray() built its result via
 * a dynamic array-dim assignment ($result[$dynamicKey] = $value;, as
 * opposed to a dynamic-keyed array literal like
 * NbmSubscribeActionMailContext's own [$this->sectionActionBy => true, ...]),
 * leaving no remaining real class shaped this way to exercise the
 * "collects literal keys and notices dynamic ones" test.
 */
final readonly class ContextVariableExtractorTestDynamicDimFixture implements TemplatePageContext
{
    public function __construct(
        public ?string $dynamicKey,
        public ?string $dynamicValue,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $result = [
            'literal_one' => 'a',
            'literal_two' => 'b',
        ];

        if ($this->dynamicKey !== null) {
            $result[$this->dynamicKey] = $this->dynamicValue;
        }

        return $result;
    }
}
