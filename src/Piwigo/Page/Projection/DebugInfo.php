<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

/**
 * `footer.latte`'s `$debug` block, built by
 * {@see \Piwigo\Page\PageTailRenderer::prepareTail()} from 2 independent
 * config flags: `$queriesList` under `CurrentConfig::$showQueries`;
 * `$time`/`$nbQueries`/`$sqlTime` together under `CurrentConfig::$showGt`.
 * All 4 fields are genuinely fixed (not a dynamic bag, despite this
 * class's own earlier docblock claiming otherwise) -- `footer.latte`
 * itself only ever reads these exact 4 keys, confirmed via
 * `{if isset($debug['TIME'])}`/`{if isset($debug['QUERIES_LIST'])}`.
 */
final readonly class DebugInfo
{
    public function __construct(
        public ?string $queriesList = null,
        public ?string $time = null,
        public ?int $nbQueries = null,
        public ?string $sqlTime = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->queriesList !== null) {
            $result['QUERIES_LIST'] = $this->queriesList;
        }

        if ($this->time !== null) {
            $result['TIME'] = $this->time;
            $result['NB_QUERIES'] = $this->nbQueries;
            $result['SQL_TIME'] = $this->sqlTime;
        }

        return $result;
    }
}
