<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\Integrity\CheckIntegrity}'s own 'check_integrity'
 * render pass. `$c13yList` is always included -- the original code's
 * own `foreach ($this->retrieve_list as ...)` loop that builds it
 * unconditionally appends at least once per real anomaly whenever this
 * render pass runs at all (its own caller only calls it when
 * `count($this->retrieve_list) > 0`). `$c13yDoCheck` is genuinely
 * optional -- only populated when at least one anomaly has a real,
 * callable correction function, omitted here (not present as a null
 * value) to match `check_integrity.latte`'s own `{if isset($c13y_do_check)}`
 * guard.
 */
final readonly class CheckIntegrityPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $c13yList
     * @param list<string>|null $c13yDoCheck
     */
    public function __construct(
        public bool $showSubmitAutomaticCorrection,
        public bool $showSubmitIgnore,
        public array $c13yList,
        public ?array $c13yDoCheck,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'c13y_show_submit_automatic_correction' => $this->showSubmitAutomaticCorrection,
            'c13y_show_submit_ignore' => $this->showSubmitIgnore,
            'c13y_list' => $this->c13yList,
        ];

        if ($this->c13yDoCheck !== null) {
            $result['c13y_do_check'] = $this->c13yDoCheck;
        }

        return $result;
    }
}
