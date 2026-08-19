<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity\Projection;

/**
 * One row of `check_integrity.latte`'s `$c13y_list`, built by
 * {@see \Piwigo\Admin\Integrity\CheckIntegrity::display()} from a real
 * {@see \Piwigo\Admin\Integrity\Projection\AnomalyRow}.
 */
final readonly class AnomalyDisplayRow
{
    public function __construct(
        public string $id,
        public string $anomaly,
        public bool $showIgnoreMsg,
        public bool $showCorrectionSuccessFct,
        public string $correctionErrorFct,
        public bool $showCorrectionFct,
        public bool $showCorrectionBadFct,
        public string $correctionMsg,
        public bool $canSelect,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'anomaly' => $this->anomaly,
            'show_ignore_msg' => $this->showIgnoreMsg,
            'show_correction_success_fct' => $this->showCorrectionSuccessFct,
            'correction_error_fct' => $this->correctionErrorFct,
            'show_correction_fct' => $this->showCorrectionFct,
            'show_correction_bad_fct' => $this->showCorrectionBadFct,
            'correction_msg' => $this->correctionMsg,
            'can_select' => $this->canSelect,
        ];
    }
}
