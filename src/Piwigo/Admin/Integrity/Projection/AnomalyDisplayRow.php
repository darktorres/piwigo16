<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity\Projection;

use Latte\Runtime\Html;

/**
 * One row of `check_integrity.latte`'s `$c13yList`, built by
 * {@see \Piwigo\Admin\Integrity\CheckIntegrity::display()} from a real
 * {@see \Piwigo\Admin\Integrity\Projection\AnomalyRow}, and read by the
 * template as this object -- it used to be flattened back to an array one
 * line before the result was built (P58-A).
 *
 * `$correctionErrorFct` is Html, not string (P59): either empty, or
 * `CheckIntegrity::getHtlmLinksMoreInfo()`'s own hand-built `<a>` pair
 * -- both pieces (a fixed `AdminUiHelper::pwgUrl()` constant and a
 * `Lang::t()` translation) are trusted.
 */
final readonly class AnomalyDisplayRow
{
    public function __construct(
        public string $id,
        public string $anomaly,
        public bool $showIgnoreMsg,
        public bool $showCorrectionSuccessFct,
        public Html $correctionErrorFct,
        public bool $showCorrectionFct,
        public bool $showCorrectionBadFct,
        public string $correctionMsg,
        public bool $canSelect,
    ) {}
}
